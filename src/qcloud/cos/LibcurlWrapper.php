<?php

namespace QCloud\Cos;

// A simple wrapper for libcurl using multi interface to do transfers in parallel.
class LibcurlWrapper {
    private $sequence;        // integer: sequence id for each request.
    private $curlMultiHandle; // curl handle: curl multi handle.
    private $curlHandleInfo;  // array: array of active curl handle.
    private $idleCurlHandle;  // array: idle curl handle which can be reused.

    public function __construct() {
        $this->sequence = 0;
        $this->curlMultiHandle = curl_multi_init();
        $this->idleCurlHandle = array();
    }

    public function __destruct() {
        curl_multi_close($this->curlMultiHandle);
        foreach ($this->idleCurlHandle as $handle) {
            curl_close($handle);
        }
        $this->idleCurlHandle = array();
    }

    public function startSendingRequest($httpRequest, $done) {
        $this->sequence += 1;

        if (count($this->idleCurlHandle) !== 0) {
            $curlHandle = array_pop($this->idleCurlHandle);
        } else {
            $curlHandle = curl_init();
            if ($curlHandle === false) {
                return false;
            }
        }

        curl_setopt($curlHandle, CURLOPT_TIMEOUT_MS, $httpRequest->timeoutMs);
        curl_setopt($curlHandle, CURLOPT_URL, $httpRequest->url);
        curl_setopt($curlHandle, CURLOPT_HEADER, 1);
        curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, 1);
        $headers = $httpRequest->customHeaders;
        array_push($headers, 'User-Agent:'.$this->getUserAgent());
        if ($httpRequest->method === 'POST') {
            if (defined('CURLOPT_SAFE_UPLOAD')) {
                curl_setopt($curlHandle, CURLOPT_SAFE_UPLOAD, true);
            }

            curl_setopt($curlHandle, CURLOPT_POST, true);
            $arr = Helper::buildCustomPostFields($httpRequest->dataToPost);
            array_push($headers, 'Expect: 100-continue');
            array_push($headers, 'Content-Type: multipart/form-data; boundary=' . $arr[0]);
            curl_setopt($curlHandle, CURLOPT_POSTFIELDS, $arr[1]);
        }
        curl_setopt($curlHandle, CURLOPT_HTTPHEADER, $headers);

        curl_multi_add_handle($this->curlMultiHandle, $curlHandle);


        $this->curlHandleInfo[$this->sequence]['handle'] = $curlHandle;
        $this->curlHandleInfo[$this->sequence]['done'] = $done;
        $this->curlHandleInfo[$this->sequence]['request'] = $httpRequest;
    }

    public function performSendingRequest() {
        for (;;) {
            $active = null;

            do {
                $mrc = curl_multi_exec($this->curlMultiHandle, $active);
                $info = curl_multi_info_read($this->curlMultiHandle);
                if ($info !== false) {
                    $this->processResult($info);
                }
            } while ($mrc == CURLM_CALL_MULTI_PERFORM);

            while ($active && $mrc == CURLM_OK) {
                if (curl_multi_select($this->curlMultiHandle) == -1) {
                    usleep(1);
                }

                do {
                    $mrc = curl_multi_exec($this->curlMultiHandle, $active);
                    $info = curl_multi_info_read($this->curlMultiHandle);
                    if ($info !== false) {
                        $this->processResult($info);
                    }
                } while ($mrc == CURLM_CALL_MULTI_PERFORM);
            }

            if (count($this->curlHandleInfo) == 0) {
                break;
            }
        }
    }

    private function processResult($info) {
        $result = $info['result'];
        $handle = $info['handle'];
        $sequence = 0;

        foreach ($this->curlHandleInfo as $key => $info) {
            if ($info['handle'] === $handle) {
                $sequence = $key;
                break;
            }
        }

        $request = $this->curlHandleInfo[$sequence]['request'];
        $done = $this->curlHandleInfo[$sequence]['done'];
        $response = new HttpResponse();

        if ($result !== CURLE_OK) {
            $response->curlErrorCode = $result;
            $response->curlErrorMessage = curl_error($handle);

            call_user_func($done, $request, $response);
        } else {
            $responseStr = curl_multi_getcontent($handle);
            $headerSize = curl_getinfo($handle, CURLINFO_HEADER_SIZE);
            $headerStr = substr($responseStr, 0, $headerSize);
            $body = substr($responseStr, $headerSize);

            $response->curlErrorCode = curl_errno($handle);
            $response->curlErrorMessage = curl_error($handle);
            $response->statusCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);
            $headLines = explode("\r\n", $headerStr);
            foreach ($headLines as $head) {
                $arr = explode(':', $head);
                if (count($arr) >= 2) {
                    $response->headers[trim($arr[0])] = trim($arr[1]);
                }
            }
            $response->body = $body;

            call_user_func($done, $request, $response);
        }

        unset($this->curlHandleInfo[$sequence]);
        curl_multi_remove_handle($this->curlMultiHandle, $handle);

        array_push($this->idleCurlHandle, $handle);
    }

    private function resetCurl($handle) {
        if (function_exists('curl_reset')) {
            curl_reset($handle);
        } else {
            curl_setopt($handler, CURLOPT_URL, '');
            curl_setopt($handler, CURLOPT_HTTPHEADER, array());
            curl_setopt($handler, CURLOPT_POSTFIELDS, array());
            curl_setopt($handler, CURLOPT_TIMEOUT, 0);
            curl_setopt($handler, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($handler, CURLOPT_SSL_VERIFYHOST, 0);
        }
    }

    private function getUserAgent() {
        return 'cos-php-sdk-' . Api::VERSION;
    }
}
