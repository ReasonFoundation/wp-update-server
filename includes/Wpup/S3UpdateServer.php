<?php
use Aws\S3\S3Client;

class Wpup_S3UpdateServer extends Wpup_UpdateServer {
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
    }

    /**
     * Find a plugin or theme by slug in S3.
     *
     * @param string $slug
     * @return Wpup_Package A package object or NULL if the plugin/theme was not found.
     */
    protected function findPackage($slug) {
        $safeSlug = preg_replace('@[^a-z0-9\-_\.,+!]@i', '', $slug);
        $s3Key = $this->prefix . $safeSlug . '.zip';

        if (!$this->s3Client->doesObjectExist($this->bucketName, $s3Key)) {
            return null;
        }

        $localFile = '/tmp/' . $safeSlug . '.zip';
        
        // Check if we already have it locally and if it's up to date
        // Note: For Lambda this is probably just for the current request context unless /tmp persists
        $this->s3Client->getObject(array(
            'Bucket' => $this->bucketName,
            'Key'    => $s3Key,
            'SaveAs' => $localFile,
        ));

        return call_user_func($this->packageFileLoader, $localFile, $slug, $this->cache);
    }

    /**
     * For S3, we might want to override download logic to redirect to S3 Presigned URL
     * or stream it from S3.
     * 
     * Streaming it from S3:
     */
    protected function actionDownload(Wpup_Request $request) {
        $package = $request->package;
        $safeSlug = preg_replace('@[^a-z0-9\-_\.,+!]@i', $package->slug);
        $s3Key = $this->prefix . $safeSlug . '.zip';

        try {
            $result = $this->s3Client->getObject(array(
                'Bucket' => $this->bucketName,
                'Key'    => $s3Key,
            ));

            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $package->slug . '.zip"');
            header('Content-Transfer-Encoding: binary');
            header('Content-Length: ' . $result['ContentLength']);

            echo $result['Body'];
        } catch (Exception $e) {
            $this->exitWithError('Failed to download package from S3.', 500);
        }
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
