<?php

namespace InnotecScotlandLtd\Gazette\Services;

use App\Models\GazetteNotice;
use Carbon\Carbon;

class GazetteService
{
    protected $curl;

    public function __construct()
    {
        $this->curl = new CurlService();
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
//        }
        return $existing_token;
    }

    public function get($type = 'insolvency')
    {
        $token = $this->token();
        $endpoint = config('gazette.GAZETTE_API_ENDPOINT');
        $last_request = \DB::table('gazette_events')
            ->select('*')
            ->where('type', $type)
            ->orderBy('id', 'desc')
            ->first();
        if ($type == 'insolvency') {
            $response = $this->getData($token, $last_request, $endpoint);
            if (empty($response['error'])) {
                $data = [
                    'type' => $type,
                    'api_end_point' => '',
                    'page_size' => !empty($response['f:page-size']) ? $response['f:page-size'] : 100,
                    'page_number' => !empty($response['f:page-number']) ? $response['f:page-number'] : 1,
                    'total_rows' => !empty($response['f:total']) ? $response['f:total'] : 0,
                    'payload' => !empty($response['link']) ? json_encode($response['link']) : '',
                    'requested_date' => Carbon::parse($response['updated'])->format('Y-m-d h:i:s'),
                ];
                \DB::table('gazette_events')->insert($data);
                $this->batchInsert($response,$token, $last_request, $endpoint);
            }
            return $response;
        }
    }

    public function getData($token, $last_request, $endpoint, $fullEndpoint = '')
    {
        $fields = [
            'start-publish-date' => (!empty($last_request)) ? date('Y-m-d', strtotime($last_request->requested_date)) : date('Y-m-d', strtotime('-3 days')),
            'end-publish-date' => date('Y-m-d'),
            'sort-by' => 'latest-date',
        ];
        if ($fullEndpoint == '') {
            $fields['categorycode'] = 24;
            $fields['results-page-size'] = 100;
            $fields = http_build_query($fields);
            $url = $endpoint . 'insolvency/notice?' . $fields;
        } else {
            $fields = http_build_query($fields);
            $url = str_replace(array('/data.json','http:/'),array('','https://'),$fullEndpoint).'&'.$fields;
        }
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $token->access_token
        ];
        $curl = $this->curl->initiateCurl($url, [], $headers, 'GET', false);
        $response = $this->curl->executeCurl($curl);
        $response = json_decode($response, true);
        if ($fullEndpoint == '') {
            return $response;
        } else {
            $this->batchInsert($response,$token, $last_request, $endpoint);
            return true;
        }
    }

    public function batchInsert($response, $token, $last_request, $endpoint)
    {
        if (!empty($response['entry'])) {
            $batch_insert = [];
            foreach ($response['entry'] as $key => $value) {
                $temp = [];
                $temp['status'] = (!empty($value['f:status'])) ? $value['f:status'] : 'published';
                $temp['notice_code'] = (!empty($value['f:notice-code'])) ? $value['f:notice-code'] : '';
                $temp['company_name'] = $value['title'];
                $temp['notice_link'] = $value['id'];
                $temp['notice_links'] = json_encode($value['link']);
                $temp['author'] = $value['author']['name'];
                $temp['updated'] = Carbon::parse($value['updated']);
                $temp['published'] = Carbon::parse($value['published']);
                $temp['category'] = $value['category']['@term'];
                $temp['lat'] = !empty($value['geo:Point']) ? $value['geo:Point']['geo:lat'] : '';
                $temp['long'] = !empty($value['geo:Point']) ? $value['geo:Point']['geo:long'] : '';
                $temp['content'] = $value['content'];
                $batch_insert[] = $temp;
            }
            GazetteNotice::insert($batch_insert);

            if (!empty($response['link'])) {
                foreach ($response['link'] as $key => $value) {
                    if (!empty($value['@rel']) && $value['@rel'] == 'next') {
                        $this->getData($token, $last_request, $endpoint, $value['@href']);
                    }
                }
            }
        }
    }
}