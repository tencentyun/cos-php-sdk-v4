<?php

namespace QCloud\Cos;

class Api {
    /* 版本 */
    const VERSION = 'v4.3.7';
    /* 签名超时时间 */
    const EXPIRED_SECONDS = 180;
    /* 分片大小-512K */
    const SLICE_SIZE_512K = 524288;
    /* 分片大小-1M */
    const SLICE_SIZE_1M = 1048576;
    /* 分片大小-2M */
    const SLICE_SIZE_2M = 2097152;
    /* 分片大小-3M */
    const SLICE_SIZE_3M = 3145728;
    /* 20M 大于20M的文件需要进行分片传输 */
    const MAX_UNSLICE_FILE_SIZE = 20971520;
	/* 失败尝试次数 */
    const MAX_RETRY_TIMES = 3;
    /* 错误代码 */
    const COSAPI_SUCCESS         = 0;
    const COSAPI_PARAMS_ERROR    = -1;
    const COSAPI_NETWORK_ERROR   = -2;
    const COSAPI_INTEGRITY_ERROR = -3;

    /* HTTP请求超时时间 */
    private $timeout = 60;
    private $endPoint = 'http://region.file.myqcloud.com/files/v2/';
    /* 默认区域是广州 */
    private $region = 'gz';
    private $auth;
    private $httpClient;
    private $config;

    /**
     * 构造函数
     * @param  array  $config  配置数组
     */
    public function __construct($config) {
        if (empty($config['app_id']) || empty($config['secret_id']) || empty($config['secret_key'])) {
            throw new \Exception('Config need app_id,secret_id,secret_key!');
        }
        $this->config = $config;
        $this->auth = new Auth($config['app_id'], $config['secret_id'], $config['secret_key']);
        $this->httpClient = new HttpClient();

        if (isset($config['region'])) {
            $this->setRegion($config['region']);
        }

        if (isset($config['timeout'])) {
            $this->setTimeout($config['timeout']);
        }
    }

	/**
     * 设置HTTP请求超时时间
     * @param  int  $timeout  超时时长
     * @return bool           设置是否成功
     */
    public function setTimeout($timeout = 60) {
        if (!is_int($timeout) || $timeout < 0) {
            return false;
        }

        $this->timeout = $timeout;
        return true;
    }

    /**
     * 设置区域
     * @param string $region 区域
     */
    public function setRegion($region) {
        $this->region = $region;
    }

    /**
     * 上传文件,自动判断文件大小,如果小于20M则使用普通文件上传,大于20M则使用分片上传
     * @param  string  $bucket       bucket名称
     * @param  string  $srcPath      本地文件路径
     * @param  string  $dstPath      上传的文件路径
     * @param  string  $bizAttr      文件属性
     * @param  int     $slicesize    分片大小(512k, 1m, 2m, 3m)，默认: 1m (SLICE_SIZE_1M)
     * @param  int     $insertOnly   同名文件是否覆盖，默认为1，不覆盖
     * @return array                 返回结果数组
     */
    public function upload(
            $bucket, $srcPath, $dstPath, $bizAttr = '', $sliceSize = self::SLICE_SIZE_1M, $insertOnly = 1) {
        if (!file_exists($srcPath)) {
            return array(
                        'code' => self::COSAPI_PARAMS_ERROR,
                        'message' => 'file ' . $srcPath .' not exists',
                        'data' => array()
                    );
        }

        if (!$dstPath || !is_string($dstPath)
                      || $dstPath[strlen($dstPath) - 1] == '/') {
            return array(
                        'code' => self::COSAPI_PARAMS_ERROR,
                        'message' => 'dstPath ' . $dstPath .' invalid',
                        'data' => array()
                    );
        }

        $dstPath = $this->normalizerPath($dstPath, false);

        /* 文件大于20M则使用分片传输 */
        if (filesize($srcPath) < self::MAX_UNSLICE_FILE_SIZE ) {
            return $this->uploadFile($bucket, $srcPath, $dstPath, $bizAttr, $insertOnly);
        } else {
            $sliceSize = $this->getSliceSize($sliceSize);
            return $this->uploadBySlicing($bucket, $srcPath, $dstPath, $bizAttr, $sliceSize, $insertOnly);
        }
    }

    /**
     * 上传内存中的内容
     * @param  string  $bucket      bucket名称
     * @param  string  $content     文件内容，二进制安全
     * @param  string  $dstPath     上传的文件路径
     * @param  string  $bizAttr     文件属性
     * @param  int     $insertOnly  是否覆盖同名文件:0 覆盖,1:不覆盖，默认值1，不覆盖
     * @return array                返回结果数组
     **/
    public function uploadBuffer(
        $bucket, $content, $dstPath, $bizAttr = '', $insertOnly = 1) {

	    if (strlen($content) >= self::MAX_UNSLICE_FILE_SIZE) {
		    return array(
                'code' => self::COSAPI_PARAMS_ERROR,
                'message' => 'content larger then 20M, not supported',
                'data' => array()
            );
	    }

        if (!$dstPath || !is_string($dstPath)
                      || $dstPath[strlen($dstPath) - 1] == '/') {
            return array(
                        'code' => self::COSAPI_PARAMS_ERROR,
                        'message' => 'dstPath ' . $dstPath .' invalid',
                        'data' => array()
                    );
        }

	    $dstPath = $this->cosUrlEncode($dstPath);
        $expired = time() + self::EXPIRED_SECONDS;
        $url = $this->generateResUrl($bucket, $dstPath);
        $signature = $this->auth->createReusableSignature($expired, $bucket);
        $fileSha = sha1($content);

        $data = array(
            'op' => 'upload',
            'sha' => $fileSha,
            'biz_attr' => $bizAttr,
            'filecontent' => $content,
        );

        $data['insertOnly'] = (($insertOnly == 0 || $insertOnly == '0' ) ? 0 : 1);

        $req = array(
            'url' => $url,
            'method' => 'post',
            'timeout' => $this->timeout,
            'data' => $data,
            'header' => array(
                'Authorization: ' . $signature,
            ),
        );

        return $this->sendRequest($req);
    }

    /**
     * 下载文件
     * @param  string  $bucket      bucket名称
     * @param  string  $srcPath     远程文件路径
     * @param  string  $dstPath     下载目标文件路径
     * @return array                返回结果数组
     */
    public function download($bucket, $srcPath, $dstPath) {
        $srcInfo = $this->stat($bucket, $srcPath);
        if ($srcInfo['code'] !== 0) {
            return array(
                'code' => self::COSAPI_PARAMS_ERROR,
                'message' => 'file '.$srcPath.' does not exists.',
                'data' => array()
            );
        }

        $url = $srcInfo['data']['source_url'];
        $sha = $srcInfo['data']['sha'];
        $expired = time() + self::EXPIRED_SECONDS;
        $signature = $this->auth->createReusableSignature($expired, $bucket);
        $req = array(
            'url' => $url,
            'method' => 'get',
            'timeout' => $this->timeout,
            'header' => array(
                'Authorization: ' . $signature,
            ),
        );

        $result = $this->httpClient->download($req, $dstPath);
        if ($result['code'] !== self::COSAPI_SUCCESS) {
            return array(
                'code' => $result['code'],
                'message' => $result['message'],
                'data' => array()
            );
        }
        return array(
            'code' => self::COSAPI_SUCCESS,
            'message' => '',
            'data' => array()
        );
    }

    /**
     * 创建目录
     * @param  string  $bucket     bucket名称
     * @param  string  $folder     目录路径
     * @param  string  $bizAttr    目录属性
     * @return array               返回结果数组
     */
    public function createFolder($bucket, $folder, $bizAttr = null) {
        if (!$this->isValidPath($folder)) {
            return array(
                        'code' => self::COSAPI_PARAMS_ERROR,
                        'message' => 'folder ' . $folder . ' is not a valid folder name',
                        'data' => array()
                    );
        }

        $folder = $this->normalizerPath($folder, True);
        $folder = $this->cosUrlEncode($folder);
        $expired = time() + self::EXPIRED_SECONDS;
        $url = $this->generateResUrl($bucket, $folder);
        $signature = $this->auth->createReusableSignature($expired, $bucket);

        $data = array(
            'op' => 'create',
            'biz_attr' => (isset($bizAttr) ? $bizAttr : ''),
        );

        $data = json_encode($data);

        $req = array(
            'url' => $url,
            'method' => 'post',
            'timeout' => $this->timeout,
            'data' => $data,
            'header' => array(
                'Authorization: ' . $signature,
                'Content-Type: application/json',
            ),
        );

        return $this->sendRequest($req);
    }

    /**
     * 目录列表
     * @param  string  $bucket    bucket名称
     * @param  string  $path      目录路径，sdk会补齐末尾的 '/'
     * @param  int     $num       本次拉取的总数，取值范围1-199，默认值20
     * @param  string  $context   列表开始条目, 用于获取大于本次拉取总数的列表清单或翻页，其值取于前一次结果的$result['data']['context'];
     * @return array              返回结果数组
     */
    public function listFolder($bucket, $folder, $num = 20, $context = '') {
        $folder = $this->normalizerPath($folder, True);

        return $this->listBase($bucket, $folder, $num, $context);
    }

    /*
     * 目录列表(前缀搜索)
     * @param  string  $bucket   bucket名称
     * @param  string  $prefix   列出含此前缀的所有文件
     * @param  int     $num      拉取的总数
     * @param  string  $context  列表开始条目, 用于获取大于本次拉取总数的列表清单或翻页，其值取于前一次结果的$result['data']['context'];
     * @return array             返回结果数组
     */
    public function prefixSearch($bucket, $prefix, $num = 20, $context = '') {
        $path = $this->normalizerPath($prefix);

        return $this->listBase($bucket, $prefix, $num, $context);
    }

    /*
     * 目录更新
     * @param  string  $bucket    bucket名称
     * @param  string  $folder    文件夹路径,SDK会补齐末尾的 '/'
     * @param  string  $bizAttr   目录属性
     * @return array              返回结果数组
     */
    public function updateFolder($bucket, $folder, $bizAttr = '') {
        $folder = $this->normalizerPath($folder, True);

        return $this->updateBase($bucket, $folder, $bizAttr);
    }

   /*
     * 查询目录信息
     * @param  string  $bucket    bucket名称
     * @param  string  $folder    目录路径
     * @return array              返回结果数组
     */
    public function statFolder($bucket, $folder) {
        $folder = $this->normalizerPath($folder, True);

        return $this->statBase($bucket, $folder);
    }

    /*
     * 删除目录，注意不能删除bucket下根目录/
     * @param  string  $bucket  bucket名称
     * @param  string  $folder  目录路径
	 * @return array            返回结果数组
     */
    public function delFolder($bucket, $folder) {
        if (empty($bucket) || empty($folder)) {
            return array(
                    'code' => self::COSAPI_PARAMS_ERROR,
                    'message' => 'bucket or path is empty');
        }

        $folder = $this->normalizerPath($folder, True);

        return $this->delBase($bucket, $folder);
    }

    /*
     * 更新文件
     * @param  string  $bucket                  bucket名称
     * @param  string  $path                    文件路径
     * @param  string  $bizAttr                 文件属性
     * @param  string  $authority               文件权限，可选值：eInvalid(继承Bucket的读写权限)/eWRPrivate(私有读写)/eWPrivateRPublic(公有读私有写)
	 * @param  array   $customerHeaders  携带的用户自定义头域,包括
     * 'Cache-Control' => '*'
     * 'Content-Type' => '*'
     * 'Content-Disposition' => '*'
     * 'Content-Language' => '*'
     * 'x-cos-meta-自定义内容' => '*'
     * @return array                            返回结果数组
     */
    public function update($bucket, $path,
                  $bizAttr = '', $authority = '', $customerHeaders = array()) {
        $path = $this->normalizerPath($path);

        return $this->updateBase($bucket, $path, $bizAttr, $authority, $customerHeaders);
    }

    /*
     * 查询文件信息
     * @param  string  $bucket  bucket名称
     * @param  string  $path    文件路径
     * @return array            返回结果数组
     */
    public function stat($bucket, $path) {
        $path = $this->normalizerPath($path);

        return $this->statBase($bucket, $path);
    }

    /*
     * 删除文件
     * @param  string  $bucket  bucket名称
     * @param  string  $path    文件路径
     * @return array            返回结果数组
     */
    public function delFile($bucket, $path) {
        if (empty($bucket) || empty($path)) {
            return array(
                    'code' => self::COSAPI_PARAMS_ERROR,
                    'message' => 'path is empty');
        }

        $path = $this->normalizerPath($path);

        return $this->delBase($bucket, $path);
    }

    /**
     * 内部方法, 上传文件
     * @param  string  $bucket      bucket名称
     * @param  string  $srcPath     本地文件路径
     * @param  string  $dstPath     上传的文件路径
     * @param  string  $bizAttr     文件属性
     * @param  int     $insertOnly  是否覆盖同名文件:0 覆盖,1:不覆盖，默认值1，不覆盖
     * @return array                返回结果数组
     */
    private function uploadFile($bucket, $srcPath, $dstPath, $bizAttr = '', $insertOnly = 1) {
        $srcPath = realpath($srcPath);
	    $dstPath = $this->cosUrlEncode($dstPath);

	    if (filesize($srcPath) >= self::MAX_UNSLICE_FILE_SIZE ) {
		    return array(
                'code' => self::COSAPI_PARAMS_ERROR,
                'message' => 'file '.$srcPath.' larger then 20M, please use uploadBySlicing interface',
                'data' => array()
            );
	    }

        $expired = time() + self::EXPIRED_SECONDS;
        $url = $this->generateResUrl($bucket, $dstPath);
        $signature = $this->auth->createReusableSignature($expired, $bucket);
        $fileSha = hash_file('sha1', $srcPath);

        $data = array(
            'op' => 'upload',
            'sha' => $fileSha,
            'biz_attr' => $bizAttr,
        );

        $data['filecontent'] = file_get_contents($srcPath);
        $data['insertOnly'] = (($insertOnly == 0 || $insertOnly == '0' ) ? 0 : 1);

        $req = array(
            'url' => $url,
            'method' => 'post',
            'timeout' => $this->timeout,
            'data' => $data,
            'header' => array(
                'Authorization: ' . $signature,
            ),
        );

        return $this->sendRequest($req);
    }

    /**
     * 内部方法,上传文件
     * @param  string  $bucket      bucket名称
     * @param  string  $srcPath     本地文件路径
     * @param  string  $dstPath     上传的文件路径
     * @param  string  $bizAttr     文件属性
     * @param  string  $sliceSize   分片大小
     * @param  int     $insertOnly  是否覆盖同名文件:0 覆盖,1:不覆盖，默认值1，不覆盖
     * @return array                返回结果数组
     */
    private function uploadBySlicing(
            $bucket, $srcFpath,  $dstFpath, $bizAttr = '', $sliceSize = self::SLICE_SIZE_1M, $insertOnly = 1) {
        $srcFpath = realpath($srcFpath);
        $fileSize = filesize($srcFpath);
        $dstFpath = $this->cosUrlEncode($dstFpath);
        $url = $this->generateResUrl($bucket, $dstFpath);
        $sliceCount = ceil($fileSize / $sliceSize);
        /* 超时时间为单个分片超时时间乘以总分片数 */
        $expiration = time() + (self::EXPIRED_SECONDS * $sliceCount);
        if ($expiration >= (time() + 10 * 24 * 60 * 60)) {
            $expiration = time() + 10 * 24 * 60 * 60;
        }
        $signature = $this->auth->createReusableSignature($expiration, $bucket);

        $sliceUploading = new SliceUploading($this->timeout * 1000, self::MAX_RETRY_TIMES);
        for ($tryCount = 0; $tryCount < self::MAX_RETRY_TIMES; ++$tryCount) {
            if ($sliceUploading->initUploading(
                        $signature,
                        $srcFpath,
                        $url,
                        $fileSize, $sliceSize, $bizAttr, $insertOnly)) {
                break;
            }

            $errorCode = $sliceUploading->getLastErrorCode();
            if ($errorCode === -4019) {
                /* 当返回 _ERROR_FILE_NOT_FINISH_UPLOAD 错误时，删除发生错误的文件 */
                Cosapi::delFile($bucket, $dstFpath);
                continue;
            }

            if ($tryCount === self::MAX_RETRY_TIMES - 1) {
                return array(
                            'code' => $sliceUploading->getLastErrorCode(),
                            'message' => $sliceUploading->getLastErrorMessage(),
                            'request_id' => $sliceUploading->getRequestId(),
                        );
            }
        }

        if (!$sliceUploading->performUploading()) {
            return array(
                        'code' => $sliceUploading->getLastErrorCode(),
                        'message' => $sliceUploading->getLastErrorMessage(),
                        'request_id' => $sliceUploading->getRequestId(),
                    );
        }

        if (!$sliceUploading->finishUploading()) {
            return array(
                        'code' => $sliceUploading->getLastErrorCode(),
                        'message' => $sliceUploading->getLastErrorMessage(),
                        'request_id' => $sliceUploading->getRequestId(),
                    );
        }

        return array(
                    'code' => 0,
                    'message' => 'SUCCESS',
                    'request_id' => $sliceUploading->getRequestId(),
                    'data' => array(
                        'access_url' => $sliceUploading->getAccessUrl(),
                        'resource_path' => $sliceUploading->getResourcePath(),
                        'source_url' => $sliceUploading->getSourceUrl(),
                    ),
                );
    }

    /**
     * 内部公共函数
     * @param  string  $bucket     bucket名称
     * @param  string  $path       文件夹路径
     * @param  int     $num        拉取的总数
     * @param  string  $context    在翻页查询时候用到
     */
    private function listBase($bucket, $path, $num = 20, $context = '') {
        $path = $this->cosUrlEncode($path);
        $expired = time() + self::EXPIRED_SECONDS;
        $url = $this->generateResUrl($bucket, $path);
        $signature = $this->auth->createReusableSignature($expired, $bucket);

        $data = array(
            'op' => 'list',
        );

		if ($num < 0 || $num > 199) {
            return array(
                        'code' => self::COSAPI_PARAMS_ERROR,
                        'message' => 'parameter num invalid, num need less then 200',
                    );
		}
        $data['num'] = $num;

        if ($context !== '') {
            $data['context'] = $context;
        }

        $url = $url . '?' . http_build_query($data);

        $req = array(
                    'url' => $url,
                    'method' => 'get',
                    'timeout' => $this->timeout,
                    'header' => array(
                        'Authorization: ' . $signature,
                    ),
                );

        return $this->sendRequest($req);
    }

    /**
     * 内部公共方法(更新文件和更新文件夹)
     * @param  string  $bucket           bucket名称
     * @param  string  $path             路径
     * @param  string  $bizAttr          文件/目录属性
     * @param  string  $authority        eInvalid/eWRPrivate(私有)/eWPrivateRPublic(公有读写)
	 * @param  array   $customerHeaders  携带的用户自定义头域,包括
     * 'Cache-Control' => '*'
     * 'Content-Type' => '*'
     * 'Content-Disposition' => '*'
     * 'Content-Language' => '*'
     * 'x-cos-meta-自定义内容' => '*'
     * @return array                            返回结果数组
     */
    private function updateBase(
            $bucket, $path, $bizAttr = '', $authority = '', $customerHeaders = array()) {
        $path = $this->cosUrlEncode($path);
        $expired = time() + self::EXPIRED_SECONDS;
        $url = $this->generateResUrl($bucket, $path);
        $signature = $this->auth->createNonreusableSignature($bucket, $path);

        $data = array('op' => 'update');

	    if (isset($bizAttr)) {
	        $data['biz_attr'] = $bizAttr;
	    }

	    if (isset($authority) && strlen($authority) > 0) {
			if($this->isAuthorityValid($authority) == false) {
                return array(
                        'code' => self::COSAPI_PARAMS_ERROR,
                        'message' => 'parameter authority invalid');
			}

	        $data['authority'] = $authority;
	    }

	    if (isset($customerHeaders)) {
	        $data['custom_headers'] = array();
	        $this->addCustomerHeader($data['custom_headers'], $customerHeaders);
	    }

        $data = json_encode($data);

        $req = array(
            'url' => $url,
            'method' => 'post',
            'timeout' => $this->timeout,
            'data' => $data,
            'header' => array(
                'Authorization: ' . $signature,
                'Content-Type: application/json',
            ),
        );

		return $this->sendRequest($req);
    }

    /**
     * 内部方法，查询文件/目录信息
     * @param  string  $bucket  bucket名称
     * @param  string  $path    文件/目录路径
     * @return array            返回结果数组
     */
    private function statBase($bucket, $path) {
        $path = $this->cosUrlEncode($path);
        $expired = time() + self::EXPIRED_SECONDS;
        $url = $this->generateResUrl($bucket, $path);
        $signature = $this->auth->createReusableSignature($expired, $bucket);

        $data = array('op' => 'stat');

        $url = $url . '?' . http_build_query($data);

        $req = array(
            'url' => $url,
            'method' => 'get',
            'timeout' => $this->timeout,
            'header' => array(
                'Authorization: ' . $signature,
            ),
        );

        return $this->sendRequest($req);
    }

    /**
     * 内部私有方法
     * @param  string  $bucket  bucket名称
     * @param  string  $path    文件/目录路径路径
     * @return array            返回结果数组
     */
    private function delBase($bucket, $path) {
        if ($path == "/") {
            return array(
                    'code' => self::COSAPI_PARAMS_ERROR,
                    'message' => 'can not delete bucket using api! go to ' .
                                 'http://console.qcloud.com/cos to operate bucket');
        }

        $path = $this->cosUrlEncode($path);
        $expired = time() + self::EXPIRED_SECONDS;
        $url = $this->generateResUrl($bucket, $path);
        $signature = $this->auth->createNonreusableSignature($bucket, $path);

        $data = array('op' => 'delete');

        $data = json_encode($data);

        $req = array(
            'url' => $url,
            'method' => 'post',
            'timeout' => $this->timeout,
            'data' => $data,
            'header' => array(
                'Authorization: ' . $signature,
                'Content-Type: application/json',
            ),
        );

        return $this->sendRequest($req);
    }

    /**
     * 内部公共方法, 路径编码
     * @param  string  $path 待编码路径
     * @return string        处理后的路径
     */
	private function cosUrlEncode($path) {
        return str_replace('%2F', '/',  rawurlencode($path));
    }

    /**
     * 内部公共方法, 构造URL
     * @param  string  $bucket   bucket名称
     * @param  string  $dstPath  路径
     * @return string            完整路径
     */
    private function generateResUrl($bucket, $dstPath) {
        $endPoint = str_replace('region', $this->region, $this->endPoint);

        return $endPoint . $this->config['app_id'] . '/' . $bucket . $dstPath;
    }

	/**
     * 内部公共方法, 发送请求
     * @param  array  $req  请求数组
     * @return array        返回结果数组
     */
    private function sendRequest($req) {
        $rsp = $this->httpClient->sendRequest($req);
        if ($rsp === false) {
            return array(
                'code' => self::COSAPI_NETWORK_ERROR,
                'message' => 'network error',
            );
        }

        $info = $this->httpClient->info();
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
     * 获取分片大小
     * @param  int  $sliceSize  分片大小
     * @return int              修复后的分片大小
     */
	private function getSliceSize($sliceSize) {
        if (is_int($sliceSize) && $sliceSize > 0) {
            return $sliceSize;
        } else {
            return self::SLICE_SIZE_1M;
        }
	}

    /*
     * 内部方法, 规整文件路径
     * @param  string  $path      文件路径
     * @param  string  $isFolder  是否为文件夹
     * @return string             规整后的路径
     */
	private function normalizerPath($path, $isFolder = False) {
		if (preg_match('/^\//', $path) == 0) {
            $path = '/' . $path;
        }

        if ($isFolder == True) {
            if (preg_match('/\/$/', $path) == 0) {
                $path = $path . '/';
            }
        }

        /* 删除多余的/ */
        $path = preg_replace('#/+#', '/', $path);

		return $path;
	}

    /**
     * 判断authority值是否正确
     * @param  string  $authority  authority字符串
     * @return bool                是否正确
     */
    private function isAuthorityValid($authority) {
        if ($authority == 'eInvalid' || $authority == 'eWRPrivate' || $authority == 'eWPrivateRPublic') {
            return true;
	    }
	    return false;
    }

    /**
     * 判断是否符合自定义属性
     * @param  string  $key  header字符串
     * @return bool          是否符合
     */
    private function isCustomerHeader($key) {
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
	 * @param  array  $customerHeaders
     */
    private function addCustomerHeader(&$data, &$customerHeaders) {
        if (count($customerHeaders) < 1) {
            return;
        }
	    foreach($customerHeaders as $key=>$value) {
            if($this->isCustomerHeader($key)) {
	            $data[$key] = $value;
            }
	    }
    }

    /**
     * 检查路径是否正确
     * @param  string  $path  路径
     * @return bool           是否正确
     */
    private function isValidPath($path) {
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

    /**
     * 复制文件（COS到COS）
     * @param  string  $bucket     bucket名称
     * @param  string  $srcFpath   源文件路径
     * @param  string  $dstFpath   目标文件路径
     * @param  bool    $overwrite  目标文件存在，是否覆盖，默认值否
     * @return array   返回结果数组
     */
    public function copyFile($bucket, $srcFpath, $dstFpath, $overwrite = false) {
        $srcFpath = $this->normalizerPath($srcFpath, false);
        $srcFpath = $this->cosUrlEncode($srcFpath);
        $url = $this->generateResUrl($bucket, $srcFpath);
        $sign = $this->auth->createNonreusableSignature($bucket, $srcFpath);
        $data = array(
            'op' => 'copy',
            'dest_fileid' => $dstFpath,
            'to_over_write' => $overwrite ? 1 : 0,
        );
        $req = array(
            'url' => $url,
            'method' => 'post',
            'timeout' => $this->timeout,
            'data' => $data,
            'header' => array(
                'Authorization: ' . $sign,
            ),
        );

        return $this->sendRequest($req);
    }

    /**
     * 移动一个文件 （COS到COS）
     * @param  string  $bucket     bucket名称
     * @param  string  $srcFpath   源文件路径
     * @param  string  $dstFpath   目标文件路径
     * @param  bool    $overwrite  目标文件存在，是否覆盖，默认值否
     * @return array   返回结果数组
     */
    public function moveFile($bucket, $srcFpath, $dstFpath, $overwrite = false) {
        $srcFpath = $this->normalizerPath($srcFpath, false);
        $srcFpath = $this->cosUrlEncode($srcFpath);
        $url = $this->generateResUrl($bucket, $srcFpath);
        $sign = $this->auth->createNonreusableSignature($bucket, $srcFpath);
        $data = array(
            'op' => 'move',
            'dest_fileid' => $dstFpath,
            'to_over_write' => $overwrite ? 1 : 0,
        );
        $req = array(
            'url' => $url,
            'method' => 'post',
            'timeout' => $this->timeout,
            'data' => $data,
            'header' => array(
                'Authorization: ' . $sign,
            ),
        );

        return $this->sendRequest($req);
    }

    /**
     * 获取文件下载链接
     * @param  string  $bucket           bucket名称
     * @param  string  $fpath            文件路径
     * @param  int     $expireAfterSecs  链接超时时间
     * @return array                     返回结果数组
     */
    public function getDownloadUrl($bucket, $fpath, $expireAfterSecs) {
        $fpath = $this->normalizerPath($fpath, false);
        $expiration = time() + $expireAfterSecs;
        $signature = $this->auth->createReusableSignature($expiration, $bucket);
        $appId = $this->config['app_id'];
        $region = $this->config['region'];

        $accessUrl = "http://$bucket-$appId.file.myqcloud.com$fpath?sign=$signature";
        $sourceUrl = "http://$bucket-$appId.cos${region}.myqcloud.com$fpath?sign=$signature";
        $url = "http://$region.file.myqcloud.com/files/v2/${appId}${fpath}?sign=$signature";

        return array(
            'code' => 0,
            'message' => 'SUCCESS',
            'data' => array(
                'access_url' => $accessUrl,
                'source_url' => $sourceUrl,
                'url' => $url,
            ),
        );
    }
}
