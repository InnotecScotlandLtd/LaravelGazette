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

        if ((!empty($existing_token) && $existing_token->expires_at <= Carbon::now()) || empty($existing_token)) {
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

    public function get($type = 'administrations')
    {
        $token = $this->token();
        dd($token);
    }
}