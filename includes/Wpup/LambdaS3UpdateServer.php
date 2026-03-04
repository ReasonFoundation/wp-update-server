<?php
use Aws\S3\S3Client;

class Wpup_LambdaS3UpdateServer extends Wpup_UpdateServer {
    /** @var S3Client */
    protected $s3Client;

    /** @var string */
    protected $bucketName;

    /** @var string */
    protected $prefix;

    /**
     * @param string $serverUrl
     * @param S3Client $s3Client
     * @param string $bucketName
     * @param string $prefix
     */
    public function __construct($serverUrl, S3Client $s3Client, $bucketName, $prefix = '') {
        parent::__construct($serverUrl, '/tmp'); // Package directory is not used for S3
        $this->s3Client = $s3Client;
        $this->bucketName = $bucketName;
        $this->prefix = rtrim($prefix, '/') . '/';

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

        // List objects in the bucket with our prefix.
        $results = $this->s3Client->listObjectsV2(array(
            'Bucket' => $this->bucketName,
            'Prefix' => $this->prefix . $safeSlug,
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

        $localFile = '/tmp/wp-update-server/packages/' . basename($bestKey);
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

        return call_user_func($this->packageFileLoader, $localFile, $slug, $this->cache);
    }

    /**
     * Generate a download URL for a package.
     *
     * For S3, we use a pre-signed URL to offload the download traffic.
     *
     * @param Wpup_Package $package
     * @return string
     */
    protected function generateDownloadUrl(Wpup_Package $package) {
        // The package filename is the local path in /tmp.
        // We need to map it back to the S3 key.
        $s3Key = $this->prefix . basename($package->getFilename());

        $command = $this->s3Client->getCommand('GetObject', array(
            'Bucket' => $this->bucketName,
            'Key'    => $s3Key,
        ));

        $request = $this->s3Client->createPresignedRequest($command, '+15 minutes');
        return (string)$request->getUri();
    }

    /**
     * For S3, we might want to override download logic to redirect to S3 Presigned URL
     * or stream it from S3.
     *
     * We've implemented generateDownloadUrl to use presigned URLs, so this
     * actionDownload might not be called if the client follows the URL directly.
     * However, if it is called, we can still stream it or redirect.
     */
    protected function actionDownload(Wpup_Request $request) {
        $url = $this->generateDownloadUrl($request->package);
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
            'slug'              => $request->param('slug', '-'),
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
}
