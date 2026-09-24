# Composer routes for packages.reason.com — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** packages.reason.com answers Composer's `/packages.json`, `/p2/…` and `/dist/…` requests behind the existing `SIMPLE_UPDATE_KEY` gate, without changing the update-checker route.

**Architecture:** A new, side-effect-free router class (`Wpup_ComposerRepository`) takes a request path and headers and returns a response object, or `null` for paths it doesn't own. `index.php` asks it first and only falls through to the existing `Wpup_LambdaS3UpdateServer` when it returns `null`. The router only reads S3; everything it serves is written by the publish pipeline (plan 2).

**Tech Stack:** PHP 8.4 on AWS Lambda via Bref (`serverless.yml`), `aws/aws-sdk-php` v3 (`S3Client`, `MockHandler` for tests), plain-PHP test scripts.

**Spec:** `~/.claude/plans/vnext-package-distribution/spec.md` — sections "wp-update-server", "Storage layout", "Invariants" 2–3. (The spec and its sibling plans are kept outside all repos on purpose; this is Plan 1 of that set, adapted into this repo.)

**Provenance:** adapted on 2026-09-24 from `~/.claude/plans/vnext-package-distribution/1-update-server.md` and checked against `master` at `555d9c7`. Changes from that draft: `index.php` keeps `Wpup_LambdaS3UpdateServer::guessServerUrl()` (the absolute-URL fix in `555d9c7`); deploys use Bref Cloud (`bref deploy --env`); tests and docs are kept out of the Lambda package; the production rollout accounts for the key not being enforced yet; the runbook lives in `docs/`. The draft's router code and tests were run against `555d9c7` unchanged and passed.

**Repo:** `~/dev/reason-update-server/wp-update-server` (`ReasonFoundation/wp-update-server`, default branch `master`). Work on a branch `feature/composer-routes`.

## Global Constraints

- Package vendor is exactly `reason-dev`; only `reason-dev/*` paths are served.
- Reserved first path segments: `packages.json`, `p2`, `dist`, `meta`. `meta/` is never served.
- Key accepted **only** as `Authorization: Bearer <key>` on Composer routes (case-insensitive scheme). Never read from the query string there.
- If `SIMPLE_UPDATE_KEY` is empty, Composer routes return `503` (fail closed). The update-checker route keeps its existing "empty key disables the gate" behavior.
- Presigned download URLs expire in `+15 minutes` (same as `generatePresignedUrl()`).
- Slug pattern: `[a-z0-9][a-z0-9._-]*`. Version pattern: `[0-9][0-9A-Za-z.+-]*`.
- No change to `Wpup_LambdaS3UpdateServer`, `Wpup_Request` or the update-checker route's responses.
- Must keep running on `php-84-fpm`; don't use syntax newer than PHP 8.4.
- `index.php` must keep building the server URL with `Wpup_LambdaS3UpdateServer::guessServerUrl()`. The parent `Wpup_UpdateServer::guessServerUrl()` produces a relative `download_url` under API Gateway, which WordPress rejects ("A valid URL was not provided").
- Deploys go through Bref Cloud: `bref deploy --env <stage>`. Stage `prod` is production; use `dev` for testing.
- `SIMPLE_UPDATE_KEY` is read at deploy time from SSM `/wp-update-server/<stage>/SIMPLE_UPDATE_KEY` (see `serverless.yml`); an absent parameter means an empty key.

## Review Focus

1. **Composer route requested with the key in `?key=` instead of a header** → `401`. A key accepted from the query string would encourage keys in URLs, which end up in lockfiles and logs.
2. **`SIMPLE_UPDATE_KEY` unset in a stage** → Composer routes `503`, never an open repository. (Task 1 test "empty key".)
3. **A dist filename that doesn't start with its own slug** (`dist/reason-dev/acme/other-1.0.zip`) → `404`, with no S3 call.
4. **`index.php` rewired for Composer, update-checker `download_url` quietly turns relative again** → WordPress refuses every update. (Task 3 Step 4 checks the dev stage's `download_url` starts with `https://`.)
5. **An S3 error other than not-found** (for example `403` from a misconfigured role) → surfaces as `500` with a log line, not a misleading `404`. (Task 1 test "S3 access denied".)

---

### Task 1: Composer router (`Wpup_ComposerRepository`) with tests

**Files:**
- Create: `includes/Wpup/ComposerResponse.php`
- Create: `includes/Wpup/ComposerRepository.php`
- Modify: `loader.php` (add two `require_once` lines)
- Create: `tests/check.php`
- Create: `tests/composer-repository-test.php`
- Create: `.github/workflows/tests.yml`
- Modify: `serverless.yml` (keep `tests/`, `docs/`, `.github/` out of the Lambda package)

**Interfaces:**
- Produces:
  - `Wpup_ComposerResponse::__construct(int $status, array $headers = [], string $body = '')`, public props `status`, `headers`, `body`; `static json(int $status, array $data): Wpup_ComposerResponse`; `static error(int $status, string $message): Wpup_ComposerResponse`; `send(): void`.
  - `Wpup_ComposerRepository::__construct(Aws\S3\S3Client $s3, string $bucket, string $simpleUpdateKey)`.
  - `Wpup_ComposerRepository::handle(string $path, Wpup_Headers $headers): ?Wpup_ComposerResponse` — `null` means "not a Composer path".
  - `Wpup_ComposerRepository::isComposerPath(string $path): bool` (static).

- [ ] **Step 1: Add the shared test helper**

`tests/check.php` (same helper the update checker uses):

```php
<?php
//Shared helpers for the plain-PHP test scripts in this directory.

$failures = 0;

function check($name, $condition) {
	global $failures;
	echo ($condition ? 'PASS ' : 'FAIL ') . $name . "\n";
	if ( !$condition ) {
		$failures++;
	}
}

function finish_tests() {
	global $failures;
	echo "\n" . ($failures === 0 ? 'ALL PASSED' : "$failures FAILED") . "\n";
	exit($failures === 0 ? 0 : 1);
}
```

- [ ] **Step 2: Write the failing test**

`tests/composer-repository-test.php`:

```php
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

// --- fail closed when the key is not configured ---
$mock = new MockHandler();
$res = make_repo('', $mock)->handle('/packages.json', bearer('anything'));
check('empty key: 503', $res->status === 503);

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
```

- [ ] **Step 3: Run it to verify it fails**

Run: `php tests/composer-repository-test.php`
Expected: fatal error `Class "Wpup_ComposerRepository" not found`.

- [ ] **Step 4: Write the response class**

`includes/Wpup/ComposerResponse.php`:

```php
<?php
/**
 * A response from Wpup_ComposerRepository. Kept separate from sending so the
 * router can be tested without output or exit().
 */
class Wpup_ComposerResponse {
	/** @var int */
	public $status;
	/** @var array<string,string> */
	public $headers;
	/** @var string */
	public $body;

	public function __construct($status, array $headers = array(), $body = '') {
		$this->status = (int)$status;
		$this->headers = $headers;
		$this->body = (string)$body;
	}

	public static function json($status, array $data) {
		return new self(
			$status,
			array('Content-Type' => 'application/json', 'Cache-Control' => 'no-cache'),
			json_encode($data, JSON_UNESCAPED_SLASHES)
		);
	}

	public static function error($status, $message) {
		return self::json($status, array('error' => $message));
	}

	public function send() {
		http_response_code($this->status);
		foreach ( $this->headers as $name => $value ) {
			header($name . ': ' . $value);
		}
		echo $this->body;
	}
}
```

- [ ] **Step 5: Write the router**

`includes/Wpup/ComposerRepository.php`:

```php
<?php
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;

/**
 * Read-only Composer v2 repository for reason-dev/* packages.
 *
 * Serves files the publish pipeline writes to S3 (see reason-wp-repo-actions):
 *   /packages.json                                     static root index
 *   /p2/reason-dev/<slug>.json                         p2/reason-dev/<slug>.json
 *   /dist/reason-dev/<slug>/<slug>-<version>.zip       302 to a presigned URL
 *
 * Every route requires SIMPLE_UPDATE_KEY as "Authorization: Bearer <key>" and
 * fails closed (503) when the key isn't configured: premium plugin code sits
 * behind these routes. The key is never accepted from the query string here,
 * because Composer records download URLs in composer.lock.
 */
class Wpup_ComposerRepository {
	const VENDOR = 'reason-dev';
	const SLUG_PATTERN = '[a-z0-9][a-z0-9._-]*';
	const VERSION_PATTERN = '[0-9][0-9A-Za-z.+-]*';
	const PRESIGN_TTL = '+15 minutes';

	/** First path segments this router owns. They can never be plugin slugs. */
	const RESERVED = array('packages.json', 'p2', 'dist', 'meta');

	/** @var S3Client */
	protected $s3;
	/** @var string */
	protected $bucket;
	/** @var string */
	protected $key;

	public function __construct(S3Client $s3, $bucket, $simpleUpdateKey) {
		$this->s3 = $s3;
		$this->bucket = (string)$bucket;
		$this->key = (string)$simpleUpdateKey;
	}

	public static function isComposerPath($path) {
		$parts = explode('/', ltrim((string)$path, '/'), 2);
		return in_array($parts[0], self::RESERVED, true);
	}

	/**
	 * @param string $path Request path, e.g. "/p2/reason-dev/acme.json".
	 * @param Wpup_Headers $headers
	 * @return Wpup_ComposerResponse|null Null when the path is not a Composer route.
	 */
	public function handle($path, Wpup_Headers $headers) {
		if ( !self::isComposerPath($path) ) {
			return null;
		}
		$path = ltrim((string)$path, '/');

		if ( $this->key === '' ) {
			return Wpup_ComposerResponse::error(503, 'The Composer repository is disabled: SIMPLE_UPDATE_KEY is not set.');
		}
		if ( !$this->isAuthorized($headers) ) {
			$response = Wpup_ComposerResponse::error(401, 'Invalid or missing update key.');
			$response->headers['WWW-Authenticate'] = 'Bearer realm="packages.reason.com"';
			return $response;
		}

		if ( $path === 'packages.json' ) {
			return Wpup_ComposerResponse::json(200, array(
				'metadata-url'               => '/p2/%package%.json',
				'available-package-patterns' => array(self::VENDOR . '/*'),
			));
		}

		if ( preg_match('@^p2/' . self::VENDOR . '/(' . self::SLUG_PATTERN . ')\.json$@', $path, $m) ) {
			return $this->serveP2($m[1]);
		}

		if ( preg_match('@^dist/' . self::VENDOR . '/(' . self::SLUG_PATTERN . ')/([^/]+)\.zip$@', $path, $m) ) {
			$slug = $m[1];
			$prefix = $slug . '-';
			if (
				strpos($m[2], $prefix) === 0
				&& preg_match('@^' . self::VERSION_PATTERN . '$@', substr($m[2], strlen($prefix)))
			) {
				return $this->serveDist($path);
			}
		}

		return Wpup_ComposerResponse::error(404, 'Not found.');
	}

	protected function isAuthorized(Wpup_Headers $headers) {
		$header = $headers->get('Authorization', '');
		if ( !is_string($header) || stripos($header, 'Bearer ') !== 0 ) {
			return false;
		}
		return hash_equals($this->key, trim(substr($header, 7)));
	}

	protected function serveP2($slug) {
		try {
			$result = $this->s3->getObject(array(
				'Bucket' => $this->bucket,
				'Key'    => 'p2/' . self::VENDOR . '/' . $slug . '.json',
			));
		} catch ( S3Exception $e ) {
			return $this->s3Failure($e);
		}
		return new Wpup_ComposerResponse(
			200,
			array('Content-Type' => 'application/json', 'Cache-Control' => 'no-cache'),
			(string)$result['Body']
		);
	}

	protected function serveDist($key) {
		try {
			$this->s3->headObject(array('Bucket' => $this->bucket, 'Key' => $key));
		} catch ( S3Exception $e ) {
			return $this->s3Failure($e);
		}
		$command = $this->s3->getCommand('GetObject', array('Bucket' => $this->bucket, 'Key' => $key));
		$url = (string)$this->s3->createPresignedRequest($command, self::PRESIGN_TTL)->getUri();
		return new Wpup_ComposerResponse(302, array('Location' => $url, 'Cache-Control' => 'no-store'));
	}

	protected function s3Failure(S3Exception $e) {
		if ( $e->getStatusCode() === 404 ) {
			return Wpup_ComposerResponse::error(404, 'Not found.');
		}
		error_log('[composer] S3 error ' . $e->getStatusCode() . ' ' . $e->getAwsErrorCode());
		return Wpup_ComposerResponse::error(500, 'Storage error.');
	}
}
```

Add to `loader.php`, after the `LambdaS3UpdateServer.php` line:

```php
require_once __DIR__ . '/includes/Wpup/ComposerResponse.php';
require_once __DIR__ . '/includes/Wpup/ComposerRepository.php';
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `composer install && php tests/composer-repository-test.php`
Expected: every line `PASS`, final line `ALL PASSED`, exit code 0.

- [ ] **Step 7: Run the tests in CI**

`.github/workflows/tests.yml`:

```yaml
---
name: Tests

"on":
  push:
    branches: [master]
  pull_request:

jobs:
  php:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v6.0.2
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          coverage: none
      - run: composer install --no-interaction --no-progress
      - name: Run test scripts
        run: |
          status=0
          for t in tests/*-test.php; do
            echo "== $t"
            php "$t" || status=1
          done
          exit $status
```

- [ ] **Step 8: Keep tests and docs out of the Lambda package**

Bref zips the project directory as-is, so without this the new `tests/` and `docs/` folders ship to Lambda. In `serverless.yml`, add a top-level `package` block directly above the `functions:` key:

```yaml
package:
  patterns:
    - '!tests/**'
    - '!docs/**'
    - '!.github/**'
    - '!.idea/**'
```

Run: `grep -n "^package:" -A5 serverless.yml`
Expected: the block is present at the top level (same indentation as `functions:`).

- [ ] **Step 9: Commit**

```bash
git checkout -b feature/composer-routes
git add includes/Wpup/ComposerResponse.php includes/Wpup/ComposerRepository.php loader.php tests/ .github/workflows/tests.yml serverless.yml
git commit -m "Add a read-only Composer repository router for reason-dev packages"
```

---

### Task 2: Route Composer paths in `index.php`

**Files:**
- Modify: `index.php`
- Test: `tests/index-routing-test.php`

**Interfaces:**
- Consumes: `Wpup_ComposerRepository::handle()`, `Wpup_ComposerResponse::send()` (Task 1).
- Produces: `wpup_route_composer(Wpup_ComposerRepository $repo, string $requestUri, array $headers): ?Wpup_ComposerResponse` in new file `includes/composer-routing.php` — the testable part of `index.php`.

- [ ] **Step 1: Write the failing test**

`tests/index-routing-test.php`:

```php
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
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php tests/index-routing-test.php`
Expected: failure opening `includes/composer-routing.php`.

- [ ] **Step 3: Implement**

`includes/composer-routing.php`:

```php
<?php
/**
 * Route a request to the Composer repository if it owns the path.
 *
 * @param Wpup_ComposerRepository $repo
 * @param string $requestUri Raw REQUEST_URI (path plus optional query string).
 * @param array $headers Header name => value.
 * @return Wpup_ComposerResponse|null
 */
function wpup_route_composer(Wpup_ComposerRepository $repo, $requestUri, array $headers) {
	$path = parse_url((string)$requestUri, PHP_URL_PATH);
	if ( !is_string($path) ) {
		$path = '/';
	}
	return $repo->handle($path, new Wpup_Headers($headers));
}
```

Replace `index.php` with:

```php
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
```

- [ ] **Step 4: Run all tests**

Run: `for t in tests/*-test.php; do php "$t" || echo "FAILED: $t"; done`
Expected: both scripts end `ALL PASSED`, no `FAILED:` lines.

Run: `php -l index.php && grep -c "Wpup_LambdaS3UpdateServer::guessServerUrl()" index.php`
Expected: `No syntax errors detected`, then `1`.

- [ ] **Step 5: Commit**

```bash
git add index.php includes/composer-routing.php tests/index-routing-test.php
git commit -m "Serve Composer routes before the update-checker route"
```

---

### Task 3: Acceptance on a `dev` stage

**Files:**
- Create: `tests/acceptance/composer-smoke.sh`

**Interfaces:**
- Consumes: deployed Composer routes (Tasks 1–2).
- Produces: `tests/acceptance/composer-smoke.sh <base-url> <bucket> <key>` — reused after the production deploy and by plan 2's canary.

- [ ] **Step 1: Write the smoke script**

`tests/acceptance/composer-smoke.sh`:

```bash
#!/usr/bin/env bash
# End-to-end check of the Composer routes against a deployed stage.
# Uploads a throwaway package reason-dev/wpup-smoke at a unique version,
# installs it with Composer using Bearer auth, and checks the lockfile.
#
#   tests/acceptance/composer-smoke.sh https://<api-id>.execute-api.us-east-1.amazonaws.com <bucket> <key>
set -euo pipefail

BASE_URL="${1%/}"; BUCKET="$2"; KEY="$3"
HOST="$(printf '%s' "$BASE_URL" | sed -E 's#^https?://([^/]+).*#\1#')"
VERSION="0.0.$(date +%s)"
SLUG="wpup-smoke"
WORK="$(mktemp -d)"; trap 'rm -rf "$WORK"' EXIT

mkdir -p "$WORK/pkg/$SLUG"
printf '<?php\n/*\n * Plugin Name: wpup smoke\n * Version: %s\n */\n' "$VERSION" > "$WORK/pkg/$SLUG/$SLUG.php"
(cd "$WORK/pkg" && zip -qr "$WORK/$SLUG-$VERSION.zip" "$SLUG")
SHA1="$(shasum -a 1 "$WORK/$SLUG-$VERSION.zip" | cut -d' ' -f1)"
DIST_KEY="dist/reason-dev/$SLUG/$SLUG-$VERSION.zip"

cat > "$WORK/p2.json" <<JSON
{"packages":{"reason-dev/$SLUG":[{"name":"reason-dev/$SLUG","version":"$VERSION","type":"wordpress-plugin",
"extra":{"installer-name":"$SLUG"},
"dist":{"type":"zip","url":"$BASE_URL/$DIST_KEY","shasum":"$SHA1","reference":"$VERSION"}}]}}
JSON

aws s3api put-object --bucket "$BUCKET" --key "$DIST_KEY" --body "$WORK/$SLUG-$VERSION.zip" --if-none-match '*' >/dev/null
aws s3api put-object --bucket "$BUCKET" --key "p2/reason-dev/$SLUG.json" --body "$WORK/p2.json" --content-type application/json >/dev/null

echo "== unauthenticated request is refused"
code="$(curl -s -o /dev/null -w '%{http_code}' "$BASE_URL/packages.json")"
[ "$code" = "401" ] || { echo "FAIL: expected 401, got $code"; exit 1; }

echo "== query-string key is refused"
code="$(curl -s -o /dev/null -w '%{http_code}' "$BASE_URL/packages.json?key=$KEY")"
[ "$code" = "401" ] || { echo "FAIL: expected 401, got $code"; exit 1; }

echo "== composer install with Bearer auth"
mkdir "$WORK/site"
cat > "$WORK/site/composer.json" <<JSON
{"repositories":[{"type":"composer","url":"$BASE_URL","only":["reason-dev/*"]},{"packagist.org":false}],
 "require":{"reason-dev/$SLUG":"$VERSION"}}
JSON
COMPOSER_AUTH="{\"bearer\":{\"$HOST\":\"$KEY\"}}" composer install --working-dir "$WORK/site" --no-interaction --no-progress

[ -f "$WORK/site/vendor/reason-dev/$SLUG/$SLUG.php" ] || { echo "FAIL: package files not installed at vendor/reason-dev/$SLUG/"; exit 1; }
if grep -q "$KEY" "$WORK/site/composer.lock"; then echo "FAIL: key found in composer.lock"; exit 1; fi

echo "PASS: Composer routes work end to end ($SLUG $VERSION)"
```

`chmod +x tests/acceptance/composer-smoke.sh`

- [ ] **Step 2: Deploy a `dev` stage**

Create the dev key at the exact SSM path `serverless.yml` reads, then deploy the `dev` environment with Bref Cloud. Use `aws ssm put-parameter` rather than `bref secret:create`: Bref doesn't document the path its command writes to, and a mismatch silently yields an empty key, which disables the gate.

```bash
DEV_KEY="$(openssl rand -hex 32)"
aws ssm put-parameter --region us-east-1 --name /wp-update-server/dev/SIMPLE_UPDATE_KEY --type SecureString --value "$DEV_KEY"
composer install --no-dev --optimize-autoloader
bref deploy --env dev
```

Note the HTTP API URL printed by the deploy and the dev bucket name:

```bash
aws cloudformation describe-stack-resources --stack-name wp-update-server-dev \
  --logical-resource-id WPUpdateBucket --query 'StackResources[0].PhysicalResourceId' --output text
```

- [ ] **Step 3: Run the smoke test against dev**

Run: `tests/acceptance/composer-smoke.sh "<dev-api-url>" "<dev-bucket>" "$DEV_KEY"`
Expected: final line `PASS: Composer routes work end to end (wpup-smoke 0.0.<n>)`.

- [ ] **Step 4: Confirm the update-checker route is unchanged on dev**

Upload any existing plugin zip to `packages/<slug>/` in the dev bucket, then:

Run: `curl -s -H "Authorization: Bearer $DEV_KEY" "<dev-api-url>/<slug>/?action=get_metadata" | jq '{name, version, download_url}'`
Expected: the same JSON shape production returns today, and `download_url` is an **absolute** URL starting with `https://<dev-api-host>/` that contains `action=download` and `key=`.

Run: `curl -sI "$(curl -s -H "Authorization: Bearer $DEV_KEY" "<dev-api-url>/<slug>/?action=get_metadata" | jq -r .download_url)" | head -1`
Expected: `HTTP/2 302` (a redirect to a presigned S3 URL).

Afterwards, restore the dev environment for local work with `composer install` (the deploy step installed without dev packages).

- [ ] **Step 5: Commit**

```bash
git add tests/acceptance/composer-smoke.sh
git commit -m "Add a Composer end-to-end smoke test for deployed stages"
```

---

### Task 4: Runbook, production deploy, and merge

**Files:**
- Create: `docs/runbook.md`

- [ ] **Step 1: Write the runbook**

`docs/runbook.md`:

```markdown
# packages.reason.com runbook

## What this serves

* Update-checker route (legacy WordPress sites): `/<slug>/?action=get_metadata`
  and `?action=download`. Key via `Authorization: Bearer` or `?key=`.
* Composer routes (vnext sites): `/packages.json`, `/p2/reason-dev/<slug>.json`,
  `/dist/reason-dev/<slug>/<slug>-<version>.zip`. Key via `Authorization: Bearer`
  only. Answer 503 if `SIMPLE_UPDATE_KEY` is unset.

This server only reads the bucket. Everything under `packages/`, `dist/`,
`meta/` and `p2/` is written by `reason-wp-repo-actions`.

## Rules

* **Append-only.** Never overwrite or delete objects under `packages/`, `dist/`,
  `meta/` or `p2/` by hand, and never add an S3 lifecycle rule that expires
  them. Sites' `composer.lock` files download exact URLs forever; deleting a
  version breaks every site that locked it, including their rollbacks.
* **Rebuilding an index:** `scripts/rebuild-composer-index.sh` in
  `reason-wp-repo-actions` regenerates `p2/` from `meta/`. Use it instead of
  editing a p2 file.

## Rotating SIMPLE_UPDATE_KEY

One key serves the whole fleet. Rotation touches, in this order:

1. Every legacy site's `REASON_PACKAGES_UPDATES_KEY` constant. The server
   accepts only one key at a time, so schedule a short window: sites send
   the old key until their constant changes.
2. Every vnext site repo's `COMPOSER_AUTH_JSON` secret
   (`{"bearer":{"packages.reason.com":"<key>"}}`).
3. The org secret `REASON_PACKAGES_UPDATES_KEY` used by plugin CI.
4. SSM `/wp-update-server/prod/SIMPLE_UPDATE_KEY`, then redeploy.

## Test stage

`dev` stage: SSM `/wp-update-server/dev/SIMPLE_UPDATE_KEY`, stack
`wp-update-server-dev`. Smoke test:
`tests/acceptance/composer-smoke.sh <url> <bucket> <key>`.
```

- [ ] **Step 2: Deploy production**

```bash
composer install --no-dev --optimize-autoloader
bref deploy --env prod
composer install
```

- [ ] **Step 3: Check production, according to whether the key is enforced yet**

Run: `curl -s -o /dev/null -w '%{http_code}\n' https://packages.reason.com/packages.json`

- **`503`: the key isn't set in production yet.** This is expected while the plugin fleet still bundles a checker without key support (see Task 0). It proves the routes fail closed. Also confirm the update-checker route still works for sites:
  `curl -s "https://packages.reason.com/<any-published-slug>/?action=get_metadata" | jq '{version, download_url}'`
  Expected: a version, and an absolute `download_url`. **Stop here for this plan.** The full production smoke test (the next bullet) runs after the key is enforced, as the first step of the key-enforcement release.
- **`401`: the key is set.** Run the full smoke test:
  ```bash
  PROD_BUCKET="$(aws cloudformation describe-stack-resources --stack-name wp-update-server-prod \
    --logical-resource-id WPUpdateBucket --query 'StackResources[0].PhysicalResourceId' --output text)"
  tests/acceptance/composer-smoke.sh https://packages.reason.com "$PROD_BUCKET" "<prod key>"
  ```
  Expected: `PASS: …`. The smoke package `reason-dev/wpup-smoke` stays in the bucket (append-only); that's intended and harmless.

- [ ] **Step 4: Commit, push, open a pull request**

```bash
git add docs/runbook.md
git commit -m "Add a runbook covering Composer routes, append-only storage and key rotation"
git push -u origin feature/composer-routes
```

Open a PR into `master`; merge after review.

---

### Task 0 (not part of this branch): pre-flight before enforcing the key in production

Not a code task: a pre-flight check so legacy sites don't lose updates when `SIMPLE_UPDATE_KEY` is set.

On a legacy site, the first plugin to load its bundled `wp-update-checker` supplies it for every plugin. If that copy predates key support, the site sends no key and gets `401` for every Reason plugin.

- [ ] **Step 1: On each legacy site (reason.com, reason.org, staging), check the loaded copy**

```bash
wp eval 'echo function_exists("ReasonDev\\PluginUpdateChecker\\ReasonPackages\\filter_http_request_args") ? "key support: yes\n" : "key support: NO\n"; echo defined("REASON_PACKAGES_UPDATES_KEY") ? "key defined: yes\n" : "key defined: NO\n";'
```

Expected: `key support: yes` and `key defined: yes` on every site. Any `NO` blocks enforcing the key until the site's plugins are updated or the constant is added.
