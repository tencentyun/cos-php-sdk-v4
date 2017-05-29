<?php

require('./include.php');

use QCloud\Cos\Api;

$bucket = 'testbucket';
$src = './111.txt';
$dst = '/testfolder/111.txt';
$folder = '/testfolder';

$config = array(
    'app_id' => '',
    'secret_id' => '',
    'secret_key' => '',
    'end_point' => 'http://region.file.myqcloud.com/files/v2/',
    'region' => 'gz',
    'timeout' => 60
);

date_default_timezone_set('PRC');
$api = new Api($config);

$api->setTimeout(180);

// 设置COS所在的区域，对应关系如下：
//     华南  -> gz
//     华中  -> sh
//     华北  -> tj
$api->setRegion('gz');

// Create folder in bucket.
$ret = $api->createFolder($bucket, $folder);
var_dump($ret);

// Upload file into bucket.
$ret = $api->upload($bucket, $src, $dst);
var_dump($ret);

// List folder.
$ret = $api->listFolder($bucket, $folder);
var_dump($ret);

// Update folder information.
$bizAttr = "";
$ret = $api->updateFolder($bucket, $folder, $bizAttr);
var_dump($ret);

// Update file information.
$bizAttr = '';
$authority = 'eWPrivateRPublic';
$customerHeaders = array(
    'Cache-Control' => 'no',
    'Content-Type' => 'application/pdf',
    'Content-Language' => 'ch',
);
$ret = $api->update($bucket, $dst, $bizAttr,$authority, $customerHeaders);
var_dump($ret);

// Stat folder.
$ret = $api->statFolder($bucket, $folder);
var_dump($ret);

// Stat file.
$ret = $api->stat($bucket, $dst);
var_dump($ret);

// Copy file.
$ret = $api->copyFile($bucket, $dst, $dst . '_copy');
var_dump($ret);

// Move file.
$ret = $api->moveFile($bucket, $dst, $dst . '_move');
var_dump($ret);

// Delete file.
$ret = $api->delFile($bucket, $dst . '_copy');
var_dump($ret);
$ret = $api->delFile($bucket, $dst . '_move');
var_dump($ret);

// Delete folder.
$ret = $api->delFolder($bucket, $folder);
var_dump($ret);
