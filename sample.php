<?php

require('./include.php');

use QCloud\Cos\Api;

$bucket = 'testbucket';
$src = './hello.txt';
$dst = '/testfolder/hello.txt';
$dst2 = 'hello2.txt';
$folder = '/testfolder';

$config = array(
    'app_id' => '',
    'secret_id' => '',
    'secret_key' => '',
    'region' => '',   // bucket所属地域：华北 'tj' 华东 'sh' 华南 'gz'
    'timeout' => 60
);

date_default_timezone_set('PRC');
$cosApi = new Api($config);

// Create folder in bucket.
$ret = $cosApi->createFolder($bucket, $folder);
var_dump($ret);

// Upload file into bucket.
$ret = $cosApi->upload($bucket, $src, $dst);
var_dump($ret);

// Download file
$ret = $cosApi->download($bucket, $dst, $dst2);
var_dump($ret);
unlink($dst2);

// List folder.
$ret = $cosApi->listFolder($bucket, $folder);
var_dump($ret);

// Update folder information.
$bizAttr = "";
$ret = $cosApi->updateFolder($bucket, $folder, $bizAttr);
var_dump($ret);

// Update file information.
$bizAttr = '';
$authority = 'eWPrivateRPublic';
$customerHeaders = array(
    'Cache-Control' => 'no',
    'Content-Type' => 'application/pdf',
    'Content-Language' => 'ch',
);
$ret = $cosApi->update($bucket, $dst, $bizAttr,$authority, $customerHeaders);
var_dump($ret);

// Stat folder.
$ret = $cosApi->statFolder($bucket, $folder);
var_dump($ret);

// Stat file.
$ret = $cosApi->stat($bucket, $dst);
var_dump($ret);

// Copy file.
$ret = $cosApi->copyFile($bucket, $dst, $dst . '_copy');
var_dump($ret);

// Move file.
$ret = $cosApi->moveFile($bucket, $dst, $dst . '_move');
var_dump($ret);

// Delete file.
$ret = $cosApi->delFile($bucket, $dst . '_copy');
var_dump($ret);
$ret = $cosApi->delFile($bucket, $dst . '_move');
var_dump($ret);

// Delete folder.
$ret = $cosApi->delFolder($bucket, $folder);
var_dump($ret);
