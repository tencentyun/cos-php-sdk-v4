<?php

namespace qcloudcos;

function my_curl_reset($handler) {
    curl_setopt($handler, CURLOPT_URL, '');
    curl_setopt($handler, CURLOPT_HTTPHEADER, array());
    curl_setopt($handler, CURLOPT_POSTFIELDS, array());
    curl_setopt($handler, CURLOPT_TIMEOUT, 0);
    curl_setopt($handler, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($handler, CURLOPT_SSL_VERIFYHOST, 0);
}

class HttpClient {
    private static $httpInfo = '';
    private static $curlHandler;

    /**
     * send http request
     * @param  array $request http请求信息
     *                   url        : 请求的url地址
     *                   method     : 请求方法，'get', 'post', 'put', 'delete', 'head'
     *                   data       : 请求数据，如有设置，则method为post
     *                   header     : 需要设置的http头部
     *                   host       : 请求头部host
     *                   timeout    : 请求超时时间
     *                   cert       : ca文件路径
     *                   ssl_version: SSL版本号
     * @return string    http请求响应
     */
    public static function sendRequest($request) {
        if (self::$curlHandler) {
            if (function_exists('curl_reset')) {
                curl_reset(self::$curlHandler);
            } else {
                my_curl_reset(self::$curlHandler);
            }
        } else {
            self::$curlHandler = curl_init();
        }

        curl_setopt(self::$curlHandler, CURLOPT_URL, $request['url']);

        $method = 'GET';
        if (isset($request['method']) &&
                in_array(strtolower($request['method']), array('get', 'post', 'put', 'delete', 'head'))) {
            $method = strtoupper($request['method']);
        } else if (isset($request['data'])) {
            $method = 'POST';
        }

        $header = isset($request['header']) ? $request['header'] : array();
        $header[] = 'Method:'.$method;
        $header[] = 'User-Agent:'.Conf::getUserAgent();
        $header[] = 'Connection: keep-alive';
        if ('POST' == $method) {
            $header[] = 'Expect: ';
        }

        isset($request['host']) && $header[] = 'Host:' . $request['host'];
        curl_setopt(self::$curlHandler, CURLOPT_HTTPHEADER, $header);
        curl_setopt(self::$curlHandler, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt(self::$curlHandler, CURLOPT_CUSTOMREQUEST, $method);
        if (defined('CURLOPT_SAFE_UPLOAD')) {
            curl_setopt(self::$curlHandler, CURLOPT_SAFE_UPLOAD, true);
        }
        isset($request['timeout']) && curl_setopt(self::$curlHandler, CURLOPT_TIMEOUT, $request['timeout']);
        isset($request['data']) && in_array($method, array('POST', 'PUT')) &&
            curl_setopt(self::$curlHandler, CURLOPT_POSTFIELDS, $request['data']);
        $ssl = substr($request['url'], 0, 8) == "https://" ? true : false;
        if( isset($request['cert'])){
            curl_setopt(self::$curlHandler, CURLOPT_SSL_VERIFYPEER,true);
            curl_setopt(self::$curlHandler, CURLOPT_CAINFO, $request['cert']);
            curl_setopt(self::$curlHandler, CURLOPT_SSL_VERIFYHOST,2);
            if (isset($request['ssl_version'])) {
                curl_setopt(self::$curlHandler, CURLOPT_SSLVERSION, $request['ssl_version']);
            } else {
                curl_setopt(self::$curlHandler, CURLOPT_SSLVERSION, 4);
            }
        }else if( $ssl ){
            curl_setopt(self::$curlHandler, CURLOPT_SSL_VERIFYPEER,false);   //true any ca
            curl_setopt(self::$curlHandler, CURLOPT_SSL_VERIFYHOST,1);       //check only host
            if (isset($request['ssl_version'])) {
                curl_setopt(self::$curlHandler, CURLOPT_SSLVERSION, $request['ssl_version']);
            } else {
                curl_setopt(self::$curlHandler, CURLOPT_SSLVERSION, 4);
            }
        }
        $ret = curl_exec(self::$curlHandler);
        self::$httpInfo = curl_getinfo(self::$curlHandler);
        return $ret;
    }

    public static function info() {
        return self::$httpInfo;
    }
}
