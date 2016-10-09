<?php

namespace qcloudcos;

date_default_timezone_set('PRC');

class Cosapi {

    //计算sign签名的时间参数
    const EXPIRED_SECONDS = 180;
    //512K
    const SLICE_SIZE_512K = 524288;
    //1M
    const SLICE_SIZE_1M = 1048576;
    //2M
    const SLICE_SIZE_2M = 2097152;
	//3M
    const SLICE_SIZE_3M = 3145728;
    //20M 大于20M的文件需要进行分片传输
    const MAX_UNSLICE_FILE_SIZE = 20971520;
	//失败尝试次数
    const MAX_RETRY_TIMES = 3;
	//返回的错误码
    const COSAPI_PARAMS_ERROR = -1;
    const COSAPI_NETWORK_ERROR = -2;
	//HTTP请求超时时间
    private static $timeout = 60;
    private static $region = 'gz'; // default region is guangzou

	/**
     * 设置HTTP请求超时时间
     * @param  int  $timeout  超时时长
     */
    public static function setTimeout($timeout = 60) {
        if (!is_int($timeout) || $timeout < 0) {
            return false;
        }

        self::$timeout = $timeout;
        return true;
    }

    public static function setRegion($region) {
        self::$region = $region;
    }

    /**
     * 上传文件,自动判断文件大小,如果小于20M则使用普通文件上传,大于20M则使用分片上传
     * @param  string  $bucket   bucket名称
     * @param  string  $srcPath      本地文件路径
     * @param  string  $dstPath      上传的文件路径
	 * @param  string  $bizAttr      文件属性
	 * @param  string  $slicesize    分片大小(512k,1m,2m,3m)，默认:1m
	 * @param  string  $insertOnly   同名文件是否覆盖
     * @return [type]                [description]
     */
    public static function upload(
            $bucket, $srcPath, $dstPath, $bizAttr = null, $sliceSize = null, $insertOnly = null) {
        if (!file_exists($srcPath)) {
            return array(
                    'code' => self::COSAPI_PARAMS_ERROR,
                    'message' => 'file ' . $srcPath .' not exists',
                    'data' => array());
        }

        $dstPath = self::normalizerPath($dstPath, false);

        //文件大于20M则使用分片传输
	    if (filesize($srcPath) < self::MAX_UNSLICE_FILE_SIZE ) {
            return self::uploadFile($bucket, $srcPath, $dstPath, $bizAttr, $insertOnly);
        } else {
			$sliceSize = self::getSliceSize($sliceSize);
		    return self::uploadBySlicing($bucket, $srcPath, $dstPath, $bizAttr, $sliceSize, $insertOnly);
	    }
    }

    /*
     * 创建目录
     * @param  string  $bucket bucket名称
     * @param  string  $folder       目录路径
	 * @param  string  $bizAttr    目录属性
     */
    public static function createFolder($bucket, $folder, $bizAttr = null) {
        if (!self::isValidPath($folder)) {
            return array(
                    'code' => self::COSAPI_PARAMS_ERROR,
                    'message' => 'folder ' . $path . ' is not a valid folder name',
                    'data' => array());
        }

        $folder = self::normalizerPath($folder, True);
        $folder = self::cosUrlEncode($folder);
        $expired = time() + self::EXPIRED_SECONDS;
        $url = self::generateResUrl($bucket, $folder);
        $signature = Auth::createReusableSignature($expired, $bucket);

        $data = array(
            'op' => 'create',
            'biz_attr' => (isset($bizAttr) ? $bizAttr : ''),
        );

        $data = json_encode($data);

        $req = array(
            'url' => $url,
            'method' => 'post',
            'timeout' => self::$timeout,
            'data' => $data,
            'header' => array(
                'Authorization: ' . $signature,
                'Content-Type: application/json',
            ),
        );

        return self::sendRequest($req);
    }

    /*
     * 目录列表
     * @param  string  $bucket bucket名称
     * @param  string  $path     目录路径，sdk会补齐末尾的 '/'
     * @param  int     $num      拉取的总数
     * @param  string  $pattern  eListBoth,ListDirOnly,eListFileOnly  默认both
     * @param  int     $order    默认正序(=0), 填1为反序,
     * @param  string  $offset   透传字段,用于翻页,前端不需理解,需要往前/往后翻页则透传回来
     */
    public static function listFolder(
                    $bucket, $folder, $num = 20,
                    $pattern = 'eListBoth', $order = 0,
                    $context = null) {
        $folder = self::normalizerPath($folder, True);

        return self::listBase($bucket, $folder, $num, $pattern, $order, $context);
    }

    /*
     * 目录列表(前缀搜索)
     * @param  string  $bucket bucket名称
     * @param  string  $prefix   列出含此前缀的所有文件
     * @param  int     $num      拉取的总数
     * @param  string  $pattern  eListBoth(默认),ListDirOnly,eListFileOnly
     * @param  int     $order    默认正序(=0), 填1为反序,
     * @param  string  $offset   透传字段,用于翻页,前端不需理解,需要往前/往后翻页则透传回来
     */
    public static function prefixSearch(
                    $bucket, $prefix, $num = 20,
                    $pattern = 'eListBoth', $order = 0,
                    $context = null) {
		$path = self::normalizerPath($prefix);

        return self::listBase($bucket, $prefix, $num,
                $pattern, $order, $context);
    }

    /*
     * 目录更新
     * @param  string  $bucket bucket名称
     * @param  string  $folder      文件夹路径,SDK会补齐末尾的 '/'
     * @param  string  $bizAttr   目录属性
     */
    public static function updateFolder($bucket, $folder, $bizAttr = null) {
        $folder = self::normalizerPath($folder, True);

        return self::updateBase($bucket, $folder, $bizAttr);
    }

   /*
     * 查询目录信息
     * @param  string  $bucket bucket名称
     * @param  string  $folder       目录路径
     */
    public static function statFolder($bucket, $folder) {
        $folder = self::normalizerPath($folder, True);

        return self::statBase($bucket, $folder);
    }

    /*
     * 删除目录
     * @param  string  $bucket bucket名称
     * @param  string  $folder       目录路径
	 *  注意不能删除bucket下根目录/
     */
    public static function delFolder($bucket, $folder) {
        if (empty($bucket) || empty($folder)) {
            return array(
                    'code' => self::COSAPI_PARAMS_ERROR,
                    'message' => 'bucket or path is empty');
        }

        $folder = self::normalizerPath($folder, True);

        return self::delBase($bucket, $folder);
    }

    /*
     * 更新文件
     * @param  string  $bucket  bucket名称
     * @param  string  $path        文件路径
     * @param  string  $authority:  eInvalid(继承Bucket的读写权限)/eWRPrivate(私有读写)/eWPrivateRPublic(公有读私有写)
	 * @param  array   $customer_headers_array 携带的用户自定义头域,包括
     * 'Cache-Control' => '*'
     * 'Content-Type' => '*'
     * 'Content-Disposition' => '*'
     * 'Content-Language' => '*'
     * 'x-cos-meta-自定义内容' => '*'
     */
    public static function update($bucket, $path,
                  $bizAttr = null, $authority=null,$customer_headers_array=null) {
        $path = self::normalizerPath($path);

        return self::updateBase($bucket, $path, $bizAttr, $authority, $customer_headers_array);
    }

    /*
     * 查询文件信息
     * @param  string  $bucket  bucket名称
     * @param  string  $path        文件路径
     */
    public static function stat($bucket, $path) {
        $path = self::normalizerPath($path);

        return self::statBase($bucket, $path);
    }

    /*
     * 删除文件
     * @param  string  $bucket
     * @param  string  $path      文件路径
     */
    public static function delFile($bucket, $path) {
        if (empty($bucket) || empty($path)) {
            return array(
                    'code' => self::COSAPI_PARAMS_ERROR,
                    'message' => 'path is empty');
        }

        $path = self::normalizerPath($path);

        return self::delBase($bucket, $path);
    }

    /**
     * 内部方法, 上传文件
     * @param  string  $bucket  bucket名称
     * @param  string  $srcPath     本地文件路径
     * @param  string  $dstPath     上传的文件路径
	 * @param  string  $bizAttr     文件属性
	 * @param  int     $insertOnly  是否覆盖同名文件:0 覆盖,1:不覆盖
     * @return [type]               [description]
     */
    private static function uploadFile($bucket, $srcPath, $dstPath, $bizAttr = null, $insertOnly = null) {
        $srcPath = realpath($srcPath);
	    $dstPath = self::cosUrlEncode($dstPath);

	    if (filesize($srcPath) >= self::MAX_UNSLICE_FILE_SIZE ) {
		    return array(
                'code' => self::COSAPI_PARAMS_ERROR,
                'message' => 'file '.$srcPath.' larger then 20M, please use uploadBySlicing interface',
                'data' => array()
            );
	    }

        $expired = time() + self::EXPIRED_SECONDS;
        $url = self::generateResUrl($bucket, $dstPath);
        $signature = Auth::createReusableSignature($expired, $bucket);
        $fileSha = hash_file('sha1', $srcPath);

        $data = array(
            'op' => 'upload',
            'sha' => $fileSha,
            'biz_attr' => (isset($bizAttr) ? $bizAttr : ''),
        );

        if (function_exists('curl_file_create')) {
            $data['filecontent'] = curl_file_create($srcPath);
        } else {
            $data['filecontent'] = '@' . $srcPath;
        }

        if (isset($insertOnly) && strlen($insertOnly) > 0) {
            $data['insertOnly'] = (($insertOnly == 0 || $insertOnly == '0' ) ? 0 : 1);
        }

        $req = array(
            'url' => $url,
            'method' => 'post',
            'timeout' => self::$timeout,
            'data' => $data,
            'header' => array(
                'Authorization: ' . $signature,
            ),
        );

        return self::sendRequest($req);
    }

    /**
     * 内部方法,上传文件
     * @param  string  $bucket  bucket名称
     * @param  string  $srcPath     本地文件路径
     * @param  string  $dstPath     上传的文件路径
	 * @param  string  $bizAttr     文件属性
	 * @param  string  $sliceSize   分片大小
	 * @param  int     $insertOnly  是否覆盖同名文件:0 覆盖,1:不覆盖
     * @return [type]                [description]
     */
    private static function uploadBySlicing(
            $bucket, $srcPath,  $dstPath, $bizAttr = null, $sliceSize = null, $insertOnly=null) {
        $srcPath = realpath($srcPath);
        $fileSize = filesize($srcPath);
        $dstPath = self::cosUrlEncode($dstPath);

        $expired = time() + self::EXPIRED_SECONDS;
        $url = self::generateResUrl($bucket, $dstPath);
        $signature = Auth::createReusableSignature($expired, $bucket);
        $fileSha = hash_file('sha1', $srcPath);

        $ret = self::initUploadingSlices($url, $signature, $srcPath, $fileSize, $fileSha,
                $sliceSize, $bizAttr, $insertOnly);
        if($ret['code'] != 0) {
            return $ret;
        }

        if(isset($ret['data']) && isset($ret['data']['url'])) {
            //秒传命中，直接返回了url
            return $ret;
        }

        $sliceSize = $ret['data']['slice_size'];
        if ($sliceSize > self::SLICE_SIZE_3M || $sliceSize <= 0) {
            $ret['code'] = self::COSAPI_PARAMS_ERROR;
            $ret['message'] = 'illegal slice size';
            return $ret;
        }

        $session = $ret['data']['session'];

        $sliceCount = ceil($fileSize / $sliceSize);
        // expired seconds for one slice mutiply by slice count
        // will be the expired seconds for whole file
        $expired = time() + (self::EXPIRED_SECONDS * $sliceCount);
        $signature = Auth::createReusableSignature($expired, $bucket);

        $ret = self::uploadSlices(
                $url, $session, $signature, $srcPath, $fileSize, $fileSha, $sliceSize);
        if ($ret['code'] != 0) {
            return $ret;
        }

        $ret = self::finishUploadingSlices($url, $session, $signature, $fileSize, $fileSha);
        return $ret;
    }

    /**
     * Init uploading slices.
     */
    private static function initUploadingSlices($url, $signature, $filepath, $fileSize, $fileSha,
            $sliceSize, $bizAttr = null, $insertOnly = null) {
        $data = array(
            'op' => 'upload_slice_init',
            'filesize' => $fileSize,
        );

        if ($sliceSize <= self::SLICE_SIZE_3M) {
            $data['slice_size'] = $sliceSize;
        } else {
            $data['slice_size'] = self::SLICE_SIZE_3M;
        }

		if (isset($bizAttr) && strlen($bizAttr)) {
            $data['biz_attr'] = $bizAttr;
        }

	    if (isset($insertOnly)) {
			$data['insertOnly'] = (($insertOnly == 0) ? 0 : 1);
        }

        $slicesSha = self::computeSlicesSha($filepath, $sliceSize);
        if ($slicesSha === false) {
            $ret['code'] = self::COSAPI_PARAMS_ERROR;
            $ret['message'] = 'read file ' . $filepath . ' error';
            return $ret;
        }

        $data['uploadparts'] = json_encode($slicesSha);
        $data['sha'] = $slicesSha[count($slicesSha) - 1]['datasha'];

        $req = array(
            'url' => $url,
            'method' => 'post',
            'timeout' => self::$timeout,
            'data' => $data,
            'header' => array(
                'Authorization: ' . $signature,
            ),
        );

        return self::sendRequest($req);
    }

    /**
     * Upload |$filepath|'s all slices into cos syste.
     */
    private static function uploadSlices(
            $url, $session, $signature, $filepath, $fileSize, $fileSha, $sliceSize) {
        for ($offset = 0; $offset < $fileSize; $offset += $sliceSize) {
            $sliceContent = file_get_contents($filepath, false, null, $offset, $sliceSize);
            if ($sliceContent === false) {
                return array(
                    'code' => self::COSAPI_PARAMS_ERROR,
                    'message' => 'read file ' . $filepath .' error',
                    'data' => array(),
                );
            }

            $data = array(
                'op' => 'upload_slice_data',
                'session' => $session,
                'offset' => $offset,
                'filecontent' => $sliceContent,
                'sha' => $fileSha,
            );

            $req = array(
                'url' => $url,
                'method' => 'post',
                'timeout' => self::$timeout,
                'data' => $data,
                'header' => array(
                    'Authorization: ' . $signature,
                ),
            );

            for ($retryCount = 0; $retryCount < self::MAX_RETRY_TIMES; ++$retryCount) {
                var_dump($req);
                $ret = self::sendRequest($req);
                var_dump($ret);
                if ($ret['code'] == 0) {
                    break;
                }
            }

            if($ret['code'] != 0) {
                return $ret;
            }
        }

        return $ret;
    }

    /**
     * Finish uploading slices.
     */
    private static function finishUploadingSlices($url, $session, $signature, $fileSize, $fileSha) {
        $data = array(
            'op' => 'upload_slice_finish',
            'session' => $session,
            'filesize' => $fileSize,
            'sha' => $fileSha,
        );

        $req = array(
            'url' => $url,
            'method' => 'post',
            'timeout' => self::$timeout,
            'data' => $data,
            'header' => array(
                'Authorization: ' . $signature,
            ),
        );

        var_dump($req);
        return self::sendRequest($req);
    }

    /*
     * 内部公共函数
     * @param  string  $bucket bucket名称
     * @param  string  $path       文件夹路径
     * @param  int     $num        拉取的总数
     * @param  string  $pattern    eListBoth(默认),ListDirOnly,eListFileOnly
     * @param  int     $order      默认正序(=0), 填1为反序,
     * @param  string  $context    在翻页查询时候用到
     */
    private static function listBase(
            $bucket, $path, $num = 20, $pattern = 'eListBoth', $order = 0, $context = null) {
        $path = self::cosUrlEncode($path);
        $expired = time() + self::EXPIRED_SECONDS;
        $url = self::generateResUrl($bucket, $path);
        $signature = Auth::createReusableSignature($expired, $bucket);

        $data = array(
            'op' => 'list',
        );

		if (self::isPatternValid($pattern) == false) {
            return array(
                'code' => self::COSAPI_PARAMS_ERROR,
                'message' => 'parameter pattern invalid',
            );
		}
		$data['pattern'] = $pattern;

		if ($order != 0 && $order != 1) {
			return array(
                'code' => self::COSAPI_PARAMS_ERROR,
                'message' => 'parameter order invalid',
            );
		}
		$data['order'] = $order;

		if ($num < 0 || $num > 199) {
			return array(
                'code' => self::COSAPI_PARAMS_ERROR,
                'message' => 'parameter num invalid, num need less then 200',
            );
		}
		$data['num'] = $num;

		if (isset($context)) {
			$data['context'] = $context;
		}

        $url = $url . '?' . http_build_query($data);

        $req = array(
            'url' => $url,
            'method' => 'get',
            'timeout' => self::$timeout,
            'header' => array(
                'Authorization: ' . $signature,
            ),
        );

        return self::sendRequest($req);
    }

    /*
     * 内部公共方法(更新文件和更新文件夹)
     * @param  string  $bucket  bucket名称
     * @param  string  $path        路径
     * @param  string  $bizAttr     文件/目录属性
     * @param  string  $authority:  eInvalid/eWRPrivate(私有)/eWPrivateRPublic(公有读写)
	 * @param  array   $customer_headers_array 携带的用户自定义头域,包括
     * 'Cache-Control' => '*'
     * 'Content-Type' => '*'
     * 'Content-Disposition' => '*'
     * 'Content-Language' => '*'
     * 'x-cos-meta-自定义内容' => '*'
     */
    private static function updateBase(
            $bucket, $path, $bizAttr = null, $authority = null, $custom_headers_array = null) {

        $path = self::cosUrlEncode($path);
        $expired = time() + self::EXPIRED_SECONDS;
        $url = self::generateResUrl($bucket, $path);
        $signature = Auth::createNonreusableSignature($bucket, $path);

        $data = array('op' => 'update');

	    if (isset($bizAttr)) {
	        $data['biz_attr'] = $bizAttr;
	    }

	    if (isset($authority) && strlen($authority) > 0) {
			if(self::isAuthorityValid($authority) == false) {
                return array(
                        'code' => self::COSAPI_PARAMS_ERROR,
                        'message' => 'parameter authority invalid');
			}

	        $data['authority'] = $authority;
	    }

	    if (isset($custom_headers_array)) {
	        $data['custom_headers'] = array();
	        self::add_customer_header($data['custom_headers'], $custom_headers_array);
	    }

        $data = json_encode($data);

        $req = array(
            'url' => $url,
            'method' => 'post',
            'timeout' => self::$timeout,
            'data' => $data,
            'header' => array(
                'Authorization: ' . $signature,
                'Content-Type: application/json',
            ),
        );

		return self::sendRequest($req);
    }

    /*
     * 内部方法
     * @param  string  $bucket  bucket名称
     * @param  string  $path        文件/目录路径
     */
    private static function statBase($bucket, $path) {
        $path = self::cosUrlEncode($path);
        $expired = time() + self::EXPIRED_SECONDS;
        $url = self::generateResUrl($bucket, $path);
        $signature = Auth::createReusableSignature($expired, $bucket);

        $data = array('op' => 'stat');

        $url = $url . '?' . http_build_query($data);

        $req = array(
            'url' => $url,
            'method' => 'get',
            'timeout' => self::$timeout,
            'header' => array(
                'Authorization: ' . $signature,
            ),
        );

        return self::sendRequest($req);
    }

    /*
     * 内部私有方法
     * @param  string  $bucket  bucket名称
     * @param  string  $path        文件/目录路径路径
     */
    private static function delBase($bucket, $path) {
        if ($path == "/") {
            return array(
                    'code' => self::COSAPI_PARAMS_ERROR,
                    'message' => 'can not delete bucket using api! go to ' .
                                 'http://console.qcloud.com/cos to operate bucket');
        }

        $path = self::cosUrlEncode($path);
        $expired = time() + self::EXPIRED_SECONDS;
        $url = self::generateResUrl($bucket, $path);
        $signature = Auth::createNonreusableSignature($bucket, $path);

        $data = array('op' => 'delete');

        $data = json_encode($data);

        $req = array(
            'url' => $url,
            'method' => 'post',
            'timeout' => self::$timeout,
            'data' => $data,
            'header' => array(
                'Authorization: ' . $signature,
                'Content-Type: application/json',
            ),
        );

        return self::sendRequest($req);
    }

    /*
     * 内部公共方法, 路径编码
     * @param  string  $path 待编码路径
     */
	private static function cosUrlEncode($path) {
        return str_replace('%2F', '/',  rawurlencode($path));
    }

    /*
     * 内部公共方法, 构造URL
     * @param  string  $bucket
     * @param  string  $dstPath
     */
    private static function generateResUrl($bucket, $dstPath) {
        $endPoint = Conf::API_COSAPI_END_POINT;
        $endPoint = str_replace('region', self::$region, $endPoint);

        return $endPoint . Conf::APP_ID . '/' . $bucket . $dstPath;
    }

	/*
     * 内部公共方法, 发送消息
     * @param  string  $req
     */
    private static function sendRequest($req) {
        $rsp = HttpClient::sendRequest($req);
        if ($rsp === false) {
            return array(
                'code' => self::COSAPI_NETWORK_ERROR,
                'message' => 'network error',
            );
        }

        $info = HttpClient::info();
        $ret = json_decode($rsp, true);

        if ($ret === NULL) {
            return array(
                'code' => self::COSAPI_NETWORK_ERROR,
                'message' => $rsp,
                'data' => array()
            );
        }

        return $ret;
    }

    /**
     * Get slice size.
     */
	private static function getSliceSize($sliceSize) {
		if (!isset($sliceSize)) {
			return $self::SLICE_SIZE_1M;
		}

		if ($sliceSize <= self::SLICE_SIZE_512K) {
			return self::SLICE_SIZE_512K;
		} else if ($sliceSize <= self::SLICE_SIZE_1M) {
			return self::SLICE_SIZE_1M;
		} else if ($sliceSize <= self::SLICE_SIZE_2M) {
			return self::SLICE_SIZE_2M;
		} else {
			return self::SLICE_SIZE_3M;
		}
	}

    /*
     * 内部方法, 规整文件路径
     * @param  string  $path      文件路径
     * @param  string  $isfolder  是否为文件夹
     */
	private static function normalizerPath($path, $isfolder = False) {
		if (preg_match('/^\//', $path) == 0) {
            $path = '/' . $path;
        }

        if ($isfolder == True) {
            if (preg_match('/\/$/', $path) == 0) {
                $path = $path . '/';
            }
        }

        // Remove unnecessary slashes.
        $path = preg_replace('#/+#', '/', $path);

		return $path;
	}

    /**
     * 判断authority值是否正确
     * @param  string  $authority
     * @return [type]  bool
     */
    private static function isAuthorityValid($authority) {
        if ($authority == 'eInvalid' || $authority == 'eWRPrivate' || $authority == 'eWPrivateRPublic') {
            return true;
	    }
	    return false;
    }

    /**
     * 判断pattern值是否正确
     * @param  string  $authority
     * @return [type]  bool
     */
    private static function isPatternValid($pattern) {
        if ($pattern == 'eListBoth' || $pattern == 'eListDirOnly' || $pattern == 'eListFileOnly') {
            return true;
	    }
	    return false;
    }

    /**
     * 判断是否符合自定义属性
     * @param  string  $key
     * @return [type]  bool
     */
    private static function isCustomer_header($key) {
        if ($key == 'Cache-Control' || $key == 'Content-Type' ||
                $key == 'Content-Disposition' || $key == 'Content-Language' ||
                $key == 'Content-Encoding' ||
                substr($key,0,strlen('x-cos-meta-')) == 'x-cos-meta-') {
            return true;
	    }
	    return false;
    }

	/**
     * 增加自定义属性到data中
     * @param  array  $data
	 * @param  array  $customer_headers_array
     * @return [type]  void
     */
    private static function add_customer_header(&$data, &$customer_headers_array) {
        if (count($customer_headers_array) < 1) {
            return;
        }
	    foreach($customer_headers_array as $key=>$value) {
            if(self::isCustomer_header($key)) {
	            $data[$key] = $value;
            }
	    }
    }

    /**
     * Compute each slice's sha for |$filepath|.
     * Return slicesSha on success, false on failure.
     */
    private static function computeSlicesSha($filepath, $sliceSize) {
        $fileSize = filesize($filepath);
        if ($fileSize === false) {
            return false;
        }

        $ctx = new Sha1Digest();
        $ctx->initSha1();
        $slicesSha = array();

        for ($offset = 0; $offset < $fileSize; $offset += $sliceSize) {
            $sliceContent = file_get_contents($filepath, false, null, $offset, $sliceSize);
            if ($fileSize - $offset >= $sliceSize) {
                $length = $sliceSize;
            } else {
                $length = $fileSize - $offset;
            }

            $sha = $ctx->updateSha1($sliceContent);

            if ($offset + $length >= $fileSize) { // The last slice.
                $sha = $ctx->finalSha1();
            }

            $slicesSha[] = array(
                'offset' => $offset,
                'datalen' => $length,
                'datasha' => $sha,
            );
        }

        return $slicesSha;
    }

    // Check |$path| is a valid file path.
    // Return true on success, otherwise return false.
    private static function isValidPath($path) {
        if (strpos($path, '?') !== false) {
            return false;
        }
        if (strpos($path, '*') !== false) {
            return false;
        }
        if (strpos($path, ':') !== false) {
            return false;
        }
        if (strpos($path, '|') !== false) {
            return false;
        }
        if (strpos($path, '\\') !== false) {
            return false;
        }
        if (strpos($path, '<') !== false) {
            return false;
        }
        if (strpos($path, '>') !== false) {
            return false;
        }
        if (strpos($path, '"') !== false) {
            return false;
        }

        return true;
    }
}
