<?php
/**
 * Plain-PHP tests for wpup_route_composer().
 * Run: php tests/index-routing-test.php
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../loader.php';
require __DIR__ . '/../includes/composer-routing.php';
require __DIR__ . '/check.php';

use Aws\MockHandler;
use Aws\S3\S3Client;

$s3 = new S3Client(array(
	'region'      => 'us-east-1',
	'version'     => 'latest',
	'handler'     => new MockHandler(),
	'credentials' => array('key' => 'AKIDTEST', 'secret' => 'SECRETTEST'),
));
$repo = new Wpup_ComposerRepository($s3, 'test-bucket', 'k');

check('update-checker path falls through', wpup_route_composer($repo, '/acme-widget/?action=get_metadata', array()) === null);
check('query string is stripped before matching', wpup_route_composer($repo, '/packages.json?foo=1', array('Authorization' => 'Bearer k'))->status === 200);
check('query key does not authorize', wpup_route_composer($repo, '/packages.json?key=k', array())->status === 401);
check('header authorizes', wpup_route_composer($repo, '/packages.json', array('Authorization' => 'Bearer k'))->status === 200);
check('reserved path never falls through', wpup_route_composer($repo, '/meta/', array('Authorization' => 'Bearer k')) !== null);

finish_tests();
