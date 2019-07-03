<?php

namespace InnotecScotlandLtd\Gazette\Services;

class CurlService
{

    /**
     * @param $url
     * @param $data
     * @param array $headers
     * @param string $type
     * @param bool $ssl
     * @return false|resource
     */
    public function initiateCurl($url, $data, $headers = [], $type = 'GET', $ssl = true)
    {
        $curl = curl_init();
        $params = [
            CURLOPT_HEADER => 0,
            CURLOPT_URL => $url,
            CURLOPT_POST => (!empty($type) && $type == 'POST') ? true : false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_ENCODING => '',
            CURLOPT_TIMEOUT => 30000,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_CUSTOMREQUEST => (!empty($type) && $type == 'POST') ? 'POST' : 'GET',
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_HTTPHEADER => $headers,
        ];

        if (!$ssl) {
            $params[CURLOPT_SSL_VERIFYPEER] = false;
            $params[CURLOPT_SSL_VERIFYHOST] = false;
        } else {

        }
        $params[CURLOPT_RETURNTRANSFER] = true;
        curl_setopt_array($curl, $params);
        return $curl;
    }

    /**
     * Executes a CURL request.
     *
     * @param resource $curl
     *
     * @return void
     */
    public function executeCurl($curl)
    {
        return curl_exec($curl);
    }

    /**
     * Closes a CURL request.
     *
     * @param resource $curl
     *
     * @return void
     */
    public function closeCurl($curl)
    {
        return curl_close($curl);
    }
}
