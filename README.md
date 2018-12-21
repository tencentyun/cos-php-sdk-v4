cos-php-sdk：php sdk for [腾讯云对象存储服务](https://www.qcloud.com/product/cos.html)
===================================================================================================

## 已弃用 - 请使用 cos-php-sdk-v5
SDK 依赖的 JSON API 已弃用，请使用基于 XML API 的 [cos-php-sdk-v5](https://github.com/tencentyun/cos-php-sdk-v5)。 

### 安装（直接下载源码集成）
若出现下载失败的问题，请将代码升级到最新的版本(>=v4.3.7)。
直接从[github](https://github.com/tencentyun/cos-php-sdk-v4)下载源码，然后在您的程序中加载cos-php-sdk-v4/include.php就可以了。

### 修改配置
配置使用数组形式

COS所在的区域(region)，对应关系如下：

|地区|region|
|:--:|:--:|
|华南|gz|
|华中(华东)|sh|
|华北|tj|

```php
$config = array(
    'app_id' => '',
    'secret_id' => '',
    'secret_key' => '',
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
    'region' => 'gz',
    'timeout' => 60
);
$cosApi = new Api($config);

// 创建文件夹
$ret = $cosApi->createFolder($bucket, $folder);
var_dump($ret);

// 上传文件
$ret = $cosApi->upload($bucket, $src, $dst);
var_dump($ret);

// 下载文件
$ret = $cosApi->download($bucket, $src, $dst);
var_dump($ret);

// 目录列表
$ret = $cosApi->listFolder($bucket, $folder);
var_dump($ret);

// 更新目录信息
$bizAttr = "";
$ret = $cosApi->updateFolder($bucket, $folder, $bizAttr);
var_dump($ret);

// 更新文件信息
$bizAttr = '';
$authority = 'eWPrivateRPublic';
$customerHeaders = array(
    'Cache-Control' => 'no',
    'Content-Type' => 'application/pdf',
    'Content-Language' => 'ch',
);
$ret = $cosApi->update($bucket, $dst, $bizAttr, $authority, $customerHeaders);
var_dump($ret);

// 查询目录信息
$ret = $cosApi->statFolder($bucket, $folder);
var_dump($ret);

// 查询文件信息
$ret = $cosApi->stat($bucket, $dst);
var_dump($ret);

// 删除文件
$ret = $cosApi->delFile($bucket, $dst);
var_dump($ret);

// 删除目录
$ret = $cosApi->delFolder($bucket, $folder);
var_dump($ret);

// 复制文件
$ret = $cosApi->copyFile($bucket, '/111.txt', '/111_2.txt');
var_dump($ret);

// 移动文件
$ret = $cosApi->moveFile($bucket, '/111.txt', '/111_3.txt');
var_dump($ret);
```
