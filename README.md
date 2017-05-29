cos-php-sdk：php sdk for [腾讯云对象存储服务](https://www.qcloud.com/product/cos.html)
===================================================================================================

### 安装（直接下载源码集成）
直接从[github](https://github.com/tencentyun/cos-php-sdk-v4)下载源码，然后在您的程序中加载cos-php-sdk-v4/include.php就可以了。

### 修改配置
配置使用数组形式
```php
$config = array(
    'app_id' => '',
    'secret_id' => '',
    'secret_key' => '',
    'end_point' => 'http://region.file.myqcloud.com/files/v2/',
    'region' => 'gz',
    'timeout' => 60
);
```

### 示例程序
请参考sample.php

```php
// 包含cos-php-sdk-v4/include.php文件
require('cos-php-sdk-v4/include.php');
use QCloud\Cos\Api;

$config = array(
    'app_id' => '',
    'secret_id' => '',
    'secret_key' => '',
    'end_point' => 'http://region.file.myqcloud.com/files/v2/',
    'region' => 'gz',
    'timeout' => 60
);
$api = new Api($config);

// 设置COS所在的区域，对应关系如下：
//     华南  -> gz
//     华中  -> sh
//     华北  -> tj
$api->setRegion('gz');

// 创建文件夹
$ret = $api->createFolder($bucket, $folder);
var_dump($ret);

// 上传文件
$ret = $api->upload($bucket, $src, $dst);
var_dump($ret);

// 目录列表
$ret = $api->listFolder($bucket, $folder);
var_dump($ret);

// 更新目录信息
$bizAttr = "";
$ret = $api->updateFolder($bucket, $folder, $bizAttr);
var_dump($ret);

// 更新文件信息
$bizAttr = '';
$authority = 'eWPrivateRPublic';
$customerHeaders = array(
    'Cache-Control' => 'no',
    'Content-Type' => 'application/pdf',
    'Content-Language' => 'ch',
);
$ret = $api->update($bucket, $dst, $bizAttr, $authority, $customerHeaders);
var_dump($ret);

// 查询目录信息
$ret = $api->statFolder($bucket, $folder);
var_dump($ret);

// 查询文件信息
$ret = $api->stat($bucket, $dst);
var_dump($ret);

// 删除文件
$ret = $api->delFile($bucket, $dst);
var_dump($ret);

// 删除目录
$ret = $api->delFolder($bucket, $folder);
var_dump($ret);

// 复制文件
$ret = $api->copyFile($bucket, '/111.txt', '/111_2.txt');
var_dump($ret);

// 移动文件
$ret = $api->moveFile($bucket, '/111.txt', '/111_3.txt');
var_dump($ret);
```
