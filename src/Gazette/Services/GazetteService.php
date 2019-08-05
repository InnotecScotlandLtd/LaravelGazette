<?php

namespace InnotecScotlandLtd\Gazette\Services;

use App\Models\GazetteNotice;
use Carbon\Carbon;

class GazetteService
{
    protected $curl;
    public $edition;
    public $shouldInsert;

    public function __construct($edition = 'london', $shouldInsert = true)
    {
        $this->curl = new CurlService();
        $this->edition = $edition;
        $this->shouldInsert = $shouldInsert;
    }

    public function token()
    {
        $existing_token = \DB::table('gazette_tokens')->select('*')->orderBy('id', 'desc')->first();
        $url = config('gazette.TOKEN_URL');
        $fields = array();
        $fields["grant_type"] = "password";
        $fields["username"] = config('gazette.USERNAME');
        $fields["password"] = config('gazette.PASSWORD');
        $fields["scope"] = "trust";
        $headers = [
            'Authorization: Basic dHNvOkphdmEkY3IxcHQh'
        ];
        $curl = $this->curl->initiateCurl($url, http_build_query($fields), $headers, 'POST');
        $response = $this->curl->executeCurl($curl);
        $response = json_decode($response, true);
        if (!empty($response['access_token'])) {
            \DB::table('gazette_tokens')->truncate();
            $token = \DB::table('gazette_tokens')->insert(
                [
                    'access_token' => $response['access_token'],
                    'expires_in' => $response['expires_in'],
                    'expires_at' => Carbon::now()->addSeconds($response['expires_in']),
                    'token_type' => $response['token_type'],
                ]
            );
            if ($token) {
                $existing_token = \DB::table('gazette_tokens')->select('*')->orderBy('id', 'desc')->first();
            }
        }
        return $existing_token;
    }

    public function get($type = 'insolvency')
    {
        \DB::beginTransaction();
        try {
            $token = $this->token();
            $endpoint = config('gazette.GAZETTE_API_ENDPOINT');
            $last_request = \DB::table('gazette_events')
                ->select('*')
                ->where('type', $type)
                ->where('edition', $this->edition)
                ->orderBy('id', 'desc')
                ->first();
            if ($type == 'insolvency') {
                $responseArray = [];
                $url = '';

                do {
                    $response = $this->getData($token, $last_request, $endpoint, $url);

                    if (isset($response['entry'])) {
                        $responseArray = array_merge($responseArray, $response['entry']);
                    }

                    if (empty($response['error'])) {
                        $this->insertGazetteEvent($type, $response);

                        if ($this->shouldInsert) {
                            $this->batchInsert($response, $token, $last_request, $endpoint);
                        }
                    }

                    $url = $this->getNextUrl($response);
                } while ($url);

                \DB::commit();

                return $responseArray;
            }
        } catch (\Exception $e) {
            \DB::rollback();
            \Log::error($e->getMessage());
            return false;
        }
    }

    public function getData($token, $last_request, $endpoint, $fullEndpoint = '', $keys = [])
    {
        $fields = [
            'start-publish-date' => (!empty($last_request)) ? date('Y-m-d', strtotime($last_request->requested_date)) : date('Y-m-d', strtotime('-3 days')),
            'end-publish-date' => date('Y-m-d'),
            'sort-by' => 'latest-date',
        ];
        $fullEndpoint = str_replace(array('/data.json', 'http:/'), array('', 'https://'), $fullEndpoint);
        if ($fullEndpoint == '') {
            $fields['categorycode'] = 24;
            $fields['results-page-size'] = 100;
            $fields = http_build_query($fields);

            if ($this->edition) {
                $url = $endpoint . 'insolvency/'.$this->edition.'/notice?' . $fields;
            } else {
                $url = $endpoint . 'insolvency/notice?' . $fields;
            }
        } else {
            $url = $fullEndpoint . '&results-page-size=100';
        }
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $token->access_token
        ];
        $curl = $this->curl->initiateCurl($url, [], $headers, 'GET', false);
        $response = $this->curl->executeCurl($curl);

        return json_decode($response, true);
    }

    public function batchInsert($response, $token, $last_request, $endpoint, $key_array = [])
    {
        if (!empty($response['entry'])) {
            $keys = $key_array;
            $batch_insert = [];
            foreach ($response['entry'] as $key => $value) {
                $keys[] = $key;
                $temp = [];
                $temp['status'] = (!empty($value['f:status'])) ? $value['f:status'] : 'published';
                $temp['notice_code'] = (!empty($value['f:notice-code'])) ? $value['f:notice-code'] : '';
                $id = explode("/", $value['id']);
                $notice_number = end($id);
                $temp['notice_number'] = $notice_number;
                $temp['company_name'] = $value['title'];
                $temp['notice_link'] = str_replace('/id/', '/', $value['id']) . '/data.json?view=linked-data';
                $temp['author'] = $value['author']['name'];
                $temp['updated'] = Carbon::parse($value['updated']);
                $temp['published'] = Carbon::parse($value['published']);
                $temp['category'] = $value['category']['@term'];
                $temp['lat'] = !empty($value['geo:Point']) ? $value['geo:Point']['geo:lat'] : '';
                $temp['long'] = !empty($value['geo:Point']) ? $value['geo:Point']['geo:long'] : '';
                $temp['content'] = $value['content'];
                $temp['edition'] = $this->edition;
                $is_notice_exist = GazetteNotice::select(['notice_number'])->where('notice_number', $notice_number)->first();
                if ($is_notice_exist) {
                    continue;
                }
                $batch_insert[] = $temp;
            }
            GazetteNotice::insert($batch_insert);
        }
    }

    public function getNoticeData($url)
    {
        $headers = [];
        $curl = $this->curl->initiateCurl($url, [], $headers, 'GET', false);
        $response = $this->curl->executeCurl($curl);
        $response = json_decode($response, true);
        return $response;
    }

    public function getNoticeContent($url)
    {
        $headers = [];
        $curl = $this->curl->initiateCurl($url, [], $headers, 'GET', false);
        $response = $this->curl->executeCurl($curl);
        return $response;
    }

    public function insertGazetteEvent($type, $response)
    {
        $data = [
            'type' => $type,
            'api_end_point' => '',
            'edition' => $this->edition,
            'page_size' => !empty($response['f:page-size']) ? $response['f:page-size'] : 100,
            'page_number' => !empty($response['f:page-number']) ? $response['f:page-number'] : 1,
            'total_rows' => !empty($response['f:total']) ? $response['f:total'] : 0,
            'payload' => !empty($response['link']) ? json_encode($response['link']) : '',
            'requested_date' => Carbon::parse($response['updated'])->format('Y-m-d h:i:s'),
        ];

        \DB::table('gazette_events')->insert($data);
    }

    public function getNextUrl($response)
    {
        if (isset($response['link'])) {
            foreach ($response['link'] as $link) {
                if ($link['@rel'] == 'next') {
                    return $link['@href'];
                }
            }
        }

        return '';
    }
}