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
