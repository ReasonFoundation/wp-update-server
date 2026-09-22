<?php
use Aws\S3\S3Client;

class Wpup_LambdaS3UpdateServer extends Wpup_UpdateServer {
    /** @var S3Client */
    protected $s3Client;

    /** @var string */
    protected $bucketName;

    /** @var string */
    protected $prefix;

    /** @var string Shared secret required for get_metadata and download when non-empty. */
    protected $simpleUpdateKey = '';

    /**
     * @param string $serverUrl
     * @param S3Client $s3Client
     * @param string $bucketName
     * @param string $prefix
     * @param string $simpleUpdateKey Optional shared secret. When non-empty, clients must
     *                                supply a matching key to call get_metadata or download.
     */
    public function __construct($serverUrl, S3Client $s3Client, $bucketName, $prefix = '', $simpleUpdateKey = '') {
        parent::__construct($serverUrl, '/tmp'); // Package directory is not used for S3
        $this->s3Client = $s3Client;
        $this->bucketName = $bucketName;
        $this->prefix = rtrim($prefix, '/') . '/';
        $this->simpleUpdateKey = (string)$simpleUpdateKey;

        // Redefine cache to use Lambda's writable /tmp directory.
        $cacheDir = '/tmp/wp-update-server/cache';
        if ( !is_dir($cacheDir) ) {
            mkdir($cacheDir, 0755, true);
        }
        $this->cache = new Wpup_FileCache($cacheDir);
    }

    /**
     * Find a plugin or theme by slug in S3.
     *
     * @param string $slug
     * @return Wpup_Package A package object or NULL if the plugin/theme was not found.
     */
    protected function findPackage($slug) {
        $safeSlug = preg_replace('@[^a-z0-9\-_.,+!]@i', '', $slug);

        // List objects in the bucket with our prefix + slug directory.
        $results = $this->s3Client->listObjectsV2(array(
            'Bucket' => $this->bucketName,
            'Prefix' => $this->prefix . $safeSlug . '/',
        ));

        $bestKey = null;
        $highestVersion = null;

        if (isset($results['Contents'])) {
            foreach ($results['Contents'] as $object) {
                $key = $object['Key'];
                $filename = basename($key);

                // Match slug.zip or slug-version.zip
                $pattern = '/^' . preg_quote($safeSlug, '/') . '(?:-(.+))?\.zip$/i';
                if (preg_match($pattern, $filename, $matches)) {
                    $version = isset($matches[1]) ? $matches[1] : '0.0.0';

                    if ($highestVersion === null || version_compare($version, $highestVersion, '>')) {
                        $highestVersion = $version;
                        $bestKey = $key;
                    }
                }
            }
        }

        if (!$bestKey) {
            return null;
        }

        // Local cache: /tmp/wp-update-server/packages/<slug>/<basename>
        $localFile = '/tmp/wp-update-server/packages/' . $safeSlug . '/' . basename($bestKey);
        $localDir = dirname($localFile);
        if (!is_dir($localDir)) {
            mkdir($localDir, 0755, true);
        }

        // Check if we already have it locally
        if (!is_file($localFile)) {
            $this->s3Client->getObject(array(
                'Bucket' => $this->bucketName,
                'Key'    => $bestKey,
                'SaveAs' => $localFile,
            ));
        }

        $package = call_user_func($this->packageFileLoader, $localFile, $slug, $this->cache);
        if ($package instanceof Wpup_Package) {
            // Attach the S3 key to metadata so generateDownloadUrl can use it.
            $metadata = $package->getMetadata();
            $metadata['s3_key'] = $bestKey;
            
            // We need a way to set the metadata back. 
            // Since we can't easily modify Wpup_Package's protected $metadata,
            // we'll rely on our generateDownloadUrl to know about this.
            // Actually, we can just create a new Wpup_Package with the updated metadata.
            return new Wpup_Package($package->slug, $package->getFilename(), $metadata);
        }

        return $package;
    }

    /**
     * Generate the download URL that goes into the metadata response.
     *
     * This points back at this server's own `download` action rather than straight at S3,
     * so every download passes through checkAuthorization(). WordPress fetches this URL
     * with a plain GET (no custom headers), so when SIMPLE_UPDATE_KEY is set the key is
     * carried as a query parameter. It is only ever handed to clients that already
     * presented the key to get_metadata.
     *
     * Pointing at the server (instead of embedding a presigned S3 URL) also means the URL
     * doesn't go stale: WordPress may cache metadata for hours, while presigned URLs
     * expire in minutes.
     *
     * @param Wpup_Package $package
     * @return string
     */
    protected function generateDownloadUrl(Wpup_Package $package) {
        $query = array(
            'action' => 'download',
            'slug'   => $package->slug,
        );
        if ($this->simpleUpdateKey !== '') {
            $query['key'] = $this->simpleUpdateKey;
        }
        return self::addQueryArg($query, $this->serverUrl);
    }

    /**
     * Create a short-lived presigned S3 URL for a package.
     *
     * The bucket is private; this URL is signed with the Lambda role's credentials and is
     * the only way a client can fetch the ZIP directly from S3.
     *
     * @param Wpup_Package $package
     * @return string
     */
    protected function generatePresignedUrl(Wpup_Package $package) {
        $metadata = $package->getMetadata();
        $s3Key = isset($metadata['s3_key']) ? $metadata['s3_key'] : null;

        if (!$s3Key) {
            // Fallback to old behavior if s3_key is missing.
            $s3Key = $this->prefix . basename($package->getFilename());
        }

        $command = $this->s3Client->getCommand('GetObject', array(
            'Bucket' => $this->bucketName,
            'Key'    => $s3Key,
        ));

        $request = $this->s3Client->createPresignedRequest($command, '+15 minutes');
        return (string)$request->getUri();
    }

    /**
     * Gate the get_metadata and download actions behind a shared secret when
     * SIMPLE_UPDATE_KEY is set.
     *
     * The client may send the key as either:
     *   - an Authorization: Bearer <key> header, or
     *   - a "key" query parameter.
     *
     * @param Wpup_Request $request
     */
    protected function checkAuthorization($request) {
        if ($this->simpleUpdateKey === '') {
            return;
        }
        if ($request->action !== 'get_metadata' && $request->action !== 'download') {
            return;
        }

        $providedKey = $this->extractClientKey($request);
        if ($providedKey === '' || !hash_equals($this->simpleUpdateKey, $providedKey)) {
            $this->exitWithError('Invalid or missing update key.', 401);
        }
    }

    /**
     * Read the client-supplied key from the Authorization header or the `key` query arg.
     *
     * @param Wpup_Request $request
     * @return string The supplied key, or '' if none was found.
     */
    protected function extractClientKey($request) {
        $authHeader = $request->headers->get('Authorization', '');
        if (is_string($authHeader) && stripos($authHeader, 'Bearer ') === 0) {
            return trim(substr($authHeader, 7));
        }

        $queryKey = $request->param('key', '');
        return is_string($queryKey) ? $queryKey : '';
    }

    /**
     * Redirect an authorized download request to a fresh presigned S3 URL.
     *
     * @param Wpup_Request $request
     */
    protected function actionDownload(Wpup_Request $request) {
        $url = $this->generatePresignedUrl($request->package);
        header('Location: ' . $url, true, 302);
        exit;
    }

    /**
     * Logging needs to be adapted for Lambda.
     * stderr is captured by CloudWatch.
     *
     * @param Wpup_Request $request
     */
    protected function logRequest($request) {
        $loggedIp = $request->clientIp;
        if ( $this->ipAnonymizationEnabled ) {
            $loggedIp = $this->anonymizeIp($loggedIp);
        }

        $columns = array(
            'ip'                => $loggedIp,
            'http_method'       => $request->httpMethod,
            'action'            => $request->param('action', '-'),
            'slug'              => $request->slug !== '' ? $request->slug : '-',
            'installed_version' => $request->param('installed_version', '-'),
            'wp_version'        => isset($request->wpVersion) ? $request->wpVersion : '-',
            'site_url'          => isset($request->wpSiteUrl) ? $request->wpSiteUrl : '-',
            'query'             => http_build_query($request->query, '', '&'),
        );

        $columns = $this->filterLogInfo($columns, $request);
        $columns = $this->escapeLogInfo($columns);

        if ( isset($columns['ip']) ) {
            $columns['ip'] = str_pad($columns['ip'], 15, ' ');
        }
        if ( isset($columns['http_method']) ) {
            $columns['http_method'] = str_pad($columns['http_method'], 4, ' ');
        }

        // Bref/CloudWatch automatically adds its own timestamp, but the original
        // server uses its own bracketed timestamp. We'll keep it for consistency.
        $line = date('[Y-m-d H:i:s O]') . ' ' . implode("\t", $columns);
        
        // Log to stderr for Bref/CloudWatch using error_log() which is more idiomatic.
        error_log($line);
    }

    /**
     * Redact the update key from the logged query string so it doesn't leak into CloudWatch.
     *
     * @param array $columns
     * @param Wpup_Request|null $request
     * @return array
     */
    protected function filterLogInfo($columns, $request = null) {
        if ($request !== null && isset($request->query['key']) && $request->query['key'] !== '') {
            $redactedQuery = $request->query;
            $redactedQuery['key'] = 'REDACTED';
            $columns['query'] = http_build_query($redactedQuery, '', '&');
        }
        return $columns;
    }

	/**
	 * Guess the Server Url based on the current request.
	 *
	 * Defaults to the current URL minus the query and "index.php".
	 *
	 * @static
	 *
	 * @return string Url
	 */
	public static function guessServerUrl() {
		$serverUrl = parent::guessServerUrl();
		//Make sure there's a trailing slash.
		if ( substr($serverUrl, -1) !== '/' ) {
			$serverUrl .= '/';
		}
		return $serverUrl;
	}
}
