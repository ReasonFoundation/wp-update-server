<?php
require __DIR__ . '/loader.php';
require __DIR__ . '/vendor/autoload.php';

use Aws\S3\S3Client;

$s3Client = new S3Client(array(
    'version' => 'latest',
    'region'  => getenv('AWS_REGION') ?: 'us-east-1',
));

$bucketName = getenv('S3_BUCKET');
$prefix = getenv('S3_PREFIX') ?: '';

$server = new Wpup_S3UpdateServer(Wpup_UpdateServer::guessServerUrl(), $s3Client, $bucketName, $prefix);
$server->handleRequest();