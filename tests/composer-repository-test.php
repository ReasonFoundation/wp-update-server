<?php
/**
 * Plain-PHP tests for Wpup_ComposerRepository.
 * Run: php tests/composer-repository-test.php
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../loader.php';
require __DIR__ . '/check.php';

//The router logs S3 failures with error_log(); keep that out of the test output.
ini_set('error_log', '/dev/null');

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;

const KEY = 'test-key-123';

function make_repo($key, MockHandler $mock) {
	$s3 = new S3Client(array(
		'region'      => 'us-east-1',
		'version'     => 'latest',
		'handler'     => $mock,
		'credentials' => array('key' => 'AKIDTEST', 'secret' => 'SECRETTEST'),
	));
	return new Wpup_ComposerRepository($s3, 'test-bucket', $key);
}

function bearer($key) {
	return new Wpup_Headers(array('Authorization' => 'Bearer ' . $key));
}

function s3_error($status, $code) {
	return function (CommandInterface $cmd) use ($status, $code) {
		return new S3Exception($code, $cmd, array('response' => new Response($status), 'code' => $code));
	};
}

// --- paths the router does not own ---
$mock = new MockHandler();
$repo = make_repo(KEY, $mock);
check('not composer: root', $repo->handle('/', bearer(KEY)) === null);
check('not composer: plugin slug', $repo->handle('/my-plugin/', bearer(KEY)) === null);
check('not composer: index.php', $repo->handle('/index.php', bearer(KEY)) === null);
check('not composer: slug that merely starts with p2', $repo->handle('/p2x/', bearer(KEY)) === null);
check('isComposerPath: dist', Wpup_ComposerRepository::isComposerPath('/dist/reason-dev/a/a-1.0.zip'));

// --- no key configured: open, like the update-checker route ---
$mock = new MockHandler();
$mock->append(new Result(array('Body' => Utils::streamFor('{"packages":{}}'))));
$open = make_repo('', $mock);
check('empty key, no header: 200', $open->handle('/packages.json', new Wpup_Headers(array()))->status === 200);
check('empty key, stray bearer: 200', $open->handle('/packages.json', bearer('anything'))->status === 200);
check('empty key: p2 served', $open->handle('/p2/reason-dev/acme-widget.json', new Wpup_Headers(array()))->status === 200);
check('empty key: unknown path still 404', $open->handle('/p2/', new Wpup_Headers(array()))->status === 404);

// --- authorization ---
$mock = new MockHandler();
$repo = make_repo(KEY, $mock);
$res = $repo->handle('/packages.json', new Wpup_Headers(array()));
check('no header: 401', $res->status === 401);
check('no header: WWW-Authenticate set', isset($res->headers['WWW-Authenticate']));
check('wrong key: 401', $repo->handle('/packages.json', bearer('nope'))->status === 401);
check('basic scheme: 401', $repo->handle('/packages.json', new Wpup_Headers(array('Authorization' => 'Basic ' . base64_encode('x:' . KEY))))->status === 401);
check('lowercase bearer scheme: 200', $repo->handle('/packages.json', new Wpup_Headers(array('Authorization' => 'bearer ' . KEY)))->status === 200);

// --- packages.json ---
$res = $repo->handle('/packages.json', bearer(KEY));
$root = json_decode($res->body, true);
check('packages.json: 200', $res->status === 200);
check('packages.json: json content type', $res->headers['Content-Type'] === 'application/json');
check('packages.json: metadata-url', $root['metadata-url'] === '/p2/%package%.json');
check('packages.json: patterns', $root['available-package-patterns'] === array('reason-dev/*'));

// --- p2 ---
$mock = new MockHandler();
$mock->append(new Result(array('Body' => Utils::streamFor('{"packages":{"reason-dev/acme-widget":[]}}'))));
$repo = make_repo(KEY, $mock);
$res = $repo->handle('/p2/reason-dev/acme-widget.json', bearer(KEY));
check('p2: 200', $res->status === 200);
check('p2: body passed through', $res->body === '{"packages":{"reason-dev/acme-widget":[]}}');
check('p2: bucket', $mock->getLastCommand()['Bucket'] === 'test-bucket');
check('p2: key', $mock->getLastCommand()['Key'] === 'p2/reason-dev/acme-widget.json');

$mock = new MockHandler();
$mock->append(s3_error(404, 'NoSuchKey'));
check('p2 missing: 404', make_repo(KEY, $mock)->handle('/p2/reason-dev/nope.json', bearer(KEY))->status === 404);

$mock = new MockHandler();
$repo = make_repo(KEY, $mock);
check('p2 ~dev: 404', $repo->handle('/p2/reason-dev/acme-widget~dev.json', bearer(KEY))->status === 404);
check('p2 other vendor: 404', $repo->handle('/p2/wpengine/acf.json', bearer(KEY))->status === 404);
check('p2 traversal: 404', $repo->handle('/p2/reason-dev/../x.json', bearer(KEY))->status === 404);
check('p2 bad requests made no S3 calls', count($mock) === 0 && $mock->getLastCommand() === null);

// --- dist ---
$mock = new MockHandler();
$mock->append(new Result(array()));  // HeadObject succeeds
$res = make_repo(KEY, $mock)->handle('/dist/reason-dev/acme-widget/acme-widget-1.4.2.zip', bearer(KEY));
check('dist: 302', $res->status === 302);
check('dist: presigned', strpos($res->headers['Location'], 'X-Amz-Signature=') !== false);
check('dist: correct object', strpos($res->headers['Location'], '/dist/reason-dev/acme-widget/acme-widget-1.4.2.zip') !== false);
check('dist: key not leaked', strpos($res->headers['Location'], KEY) === false);
check('dist: head checked the object', $mock->getLastCommand()->getName() === 'HeadObject');

$mock = new MockHandler();
$mock->append(new Result(array()));
$res = make_repo(KEY, $mock)->handle('/dist/reason-dev/foo-2/foo-2-1.0.0.1.zip', bearer(KEY));
check('dist: slug containing a digit segment and four-part version', $res->status === 302);

$mock = new MockHandler();
$mock->append(s3_error(404, 'NotFound'));
check('dist missing: 404', make_repo(KEY, $mock)->handle('/dist/reason-dev/acme-widget/acme-widget-9.9.9.zip', bearer(KEY))->status === 404);

$mock = new MockHandler();
$repo = make_repo(KEY, $mock);
check('dist slug mismatch: 404', $repo->handle('/dist/reason-dev/acme-widget/other-1.0.zip', bearer(KEY))->status === 404);
check('dist no version: 404', $repo->handle('/dist/reason-dev/acme-widget/acme-widget.zip', bearer(KEY))->status === 404);
check('meta is never served', $repo->handle('/meta/reason-dev/acme-widget/1.4.2.json', bearer(KEY))->status === 404);
check('bare reserved segment: 404', $repo->handle('/p2/', bearer(KEY))->status === 404);
check('bad dist requests made no S3 calls', $mock->getLastCommand() === null);

// --- S3 errors other than not-found ---
$mock = new MockHandler();
$mock->append(s3_error(403, 'AccessDenied'));
check('S3 access denied: 500', make_repo(KEY, $mock)->handle('/p2/reason-dev/acme-widget.json', bearer(KEY))->status === 500);

finish_tests();
