<?php

namespace InnotecScotlandLtd\Gazette\Services;

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

        $current_time = Carbon::now()->format('Y-m-d h:i:s');
        if ((!empty($existing_token) && $existing_token->expires_at <= $current_time) || empty($existing_token)) {
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
        }
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
            $fields = [
                'categorycode' => 24,
                'results-page-size' => 10,
                'status' => 'published',
                'start-publish-date' => (!empty($last_request)) ? date('Y-m-d', strtotime($last_request->requested_date)) : date('Y-m-d', strtotime('-3 days')),
                'end-publish-date' => date('Y-m-d'),
                'sort-by' => 'latest-date',
            ];
            $fields = http_build_query($fields);
            $url = $endpoint . 'insolvency/notice?' . $fields;
            $headers = [
                'Accept: application/json',
                'Authorization: Bearer ' . $token->access_token
            ];
            $curl = $this->curl->initiateCurl($url, [], $headers, 'GET', false);
            $response = $this->curl->executeCurl($curl);
            $response = json_decode($response, true);

            $data = [
                'type' => $type,
                'api_end_point' => $url,
                'page_size' => !empty($response['f:page-size']) ? $response['f:page-size'] : 100,
                'page_number' => !empty($response['f:page-number']) ? $response['f:page-number'] : 1,
                'total_rows' => !empty($response['f:total']) ? $response['f:total'] : 0,
                'payload' => !empty($response['entry']) ? json_encode($response['entry']) : '',
                'requested_date' => Carbon::parse($response['updated'])->format('Y-m-d h:i:s'),
            ];
            \DB::table('gazette_events')->insert($data);
            return $response;
        }
    }
}