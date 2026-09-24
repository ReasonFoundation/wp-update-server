<?php
require __DIR__ . '/loader.php';
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/includes/composer-routing.php';

use Aws\S3\S3Client;

$s3Client = new S3Client(array(
    'version' => 'latest',
    'region'  => getenv('AWS_REGION') ?: 'us-east-1',
));

$bucketName = getenv('S3_BUCKET');
$prefix = getenv('S3_PREFIX') ?: '';
$simpleUpdateKey = getenv('SIMPLE_UPDATE_KEY') ?: '';

// Composer routes first: Wpup_Request would otherwise read "p2", "dist", ... as a plugin slug.
$composerResponse = wpup_route_composer(
    new Wpup_ComposerRepository($s3Client, $bucketName, $simpleUpdateKey),
    isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/',
    Wpup_Headers::parseCurrent()
);
if ($composerResponse !== null) {
    error_log(sprintf('[composer] %d %s', $composerResponse->status, parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)));
    $composerResponse->send();
    exit;
}

$server = new Wpup_LambdaS3UpdateServer(
    // Keep the Lambda override: the parent's version yields a relative download_url under API Gateway.
    Wpup_LambdaS3UpdateServer::guessServerUrl(),
    $s3Client,
    $bucketName,
    $prefix,
    $simpleUpdateKey
);
$server->handleRequest();
