<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Media_Storage_S3 — Amazon S3 (and S3-compatible) media storage.
 *
 * One bucket holds everything; `visibility` selects a key prefix (not a separate bucket) so a
 * single bucket policy can expose only the public prefix:
 *   - PUBLIC files land under `public_prefix` (default `public/`) → url() returns a direct
 *     CDN/S3 URL (make that prefix world-readable via a CloudFront distribution or a bucket
 *     policy — modern buckets block object ACLs, so we don't rely on `public-read` by default).
 *   - PRIVATE files land under `private_prefix` (default `private/`) → url() returns a
 *     short-lived **presigned** GET URL (`presign_ttl`), so S3 serves the bytes directly while
 *     the app still gates *who gets handed a URL*. If presigning is disabled, url() returns ''
 *     and the media layer streams through the ACL-checked /media/file/<id> route instead.
 *
 * Credentials come from the default AWS provider chain (an EC2/ECS IAM role, env, or shared
 * config) unless `key`+`secret` are set in the disk config. `endpoint` + `use_path_style`
 * point it at an S3-compatible service (MinIO, DigitalOcean Spaces, R2).
 *
 * Requires `aws/aws-sdk-php` (optional dependency — see composer suggest / MEDIA.md); the
 * adapter throws a clear message if it's missing, and only loads when the `s3` disk is used.
 *
 * @api
 */
class Tiger_Media_Storage_S3 implements Tiger_Media_Storage_Interface
{
    const DEFAULT_TTL = 900;   // 15 min presigned-URL lifetime for private objects

    protected $_bucket;
    protected $_region;
    protected $_prefix;          // base prefix prepended to every key
    protected $_publicPrefix;
    protected $_privatePrefix;
    protected $_cdn;             // public host (CloudFront/custom) — no scheme
    protected $_presignTtl;
    protected $_publicAcl;       // send ACL=public-read on public puts (off by default)
    protected $_settings;        // raw config (for lazy client build)

    /** @var \Aws\S3\S3Client|null memoized */
    protected $_client;

    public function __construct(array $config)
    {
        $this->_bucket = (string) ($config['bucket'] ?? '');
        if ($this->_bucket === '') {
            throw new RuntimeException('Tiger_Media_Storage_S3: media.disks.<name>.bucket is required.');
        }
        $this->_region        = (string) ($config['region'] ?? 'us-east-1');
        $this->_prefix        = $this->_normPrefix($config['prefix'] ?? '');
        $this->_publicPrefix  = $this->_normPrefix($config['public_prefix']  ?? 'public/');
        $this->_privatePrefix = $this->_normPrefix($config['private_prefix'] ?? 'private/');
        $this->_cdn           = rtrim((string) ($config['cdn'] ?? ''), '/');
        $this->_presignTtl    = (int) ($config['presign_ttl'] ?? self::DEFAULT_TTL) ?: self::DEFAULT_TTL;
        $this->_publicAcl     = (int) ($config['public_acl'] ?? 0) === 1;
        $this->_settings      = $config;
    }

    public function put($key, $sourcePath, $visibility, $mime = null)
    {
        $this->_putObject($key, $visibility, ['SourceFile' => $sourcePath], $mime, $key);
    }

    public function write($key, $bytes, $visibility, $mime = null)
    {
        $this->_putObject($key, $visibility, ['Body' => $bytes], $mime, $key);
    }

    public function get($key, $visibility)
    {
        try {
            $r = $this->_c()->getObject(['Bucket' => $this->_bucket, 'Key' => $this->_fullKey($key, $visibility)]);
            return (string) $r['Body'];
        } catch (Throwable $e) {
            throw new RuntimeException('Tiger_Media_Storage_S3: not found ' . $key, 0, $e);
        }
    }

    public function stream($key, $visibility)
    {
        try {
            $r = $this->_c()->getObject([
                'Bucket' => $this->_bucket,
                'Key'    => $this->_fullKey($key, $visibility),
                '@http'  => ['stream' => true],
            ]);
        } catch (Throwable $e) {
            throw new RuntimeException('Tiger_Media_Storage_S3: not found ' . $key, 0, $e);
        }
        $body = $r['Body'];
        // Prefer the live socket resource (no full buffering); fall back to a temp buffer.
        if ($body instanceof \Psr\Http\Message\StreamInterface) {
            $res = $body->detach();
            if (is_resource($res)) {
                return $res;
            }
        }
        $fh = fopen('php://temp', 'r+b');
        fwrite($fh, (string) $body);
        rewind($fh);
        return $fh;
    }

    public function delete($key, $visibility)
    {
        // S3 delete of a missing key still succeeds — idempotent, like the interface asks.
        try {
            $this->_c()->deleteObject(['Bucket' => $this->_bucket, 'Key' => $this->_fullKey($key, $visibility)]);
        } catch (Throwable $e) {
            // swallow — matches the filesystem adapter's best-effort delete
        }
    }

    public function exists($key, $visibility)
    {
        try {
            return (bool) $this->_c()->doesObjectExist($this->_bucket, $this->_fullKey($key, $visibility));
        } catch (Throwable $e) {
            return false;
        }
    }

    public function size($key, $visibility)
    {
        try {
            $r = $this->_c()->headObject(['Bucket' => $this->_bucket, 'Key' => $this->_fullKey($key, $visibility)]);
            return (int) $r['ContentLength'];
        } catch (Throwable $e) {
            return 0;
        }
    }

    public function url($key, $visibility, $ttl = null)
    {
        $full = $this->_fullKey($key, $visibility);

        if ($visibility === Tiger_Model_Media::VISIBILITY_PUBLIC) {
            return $this->_publicUrl($full);
        }
        // Private: a short-lived presigned GET so S3 serves it directly. presign_ttl=0 disables
        // it (url() returns '' -> the media layer streams via the ACL-checked route instead).
        if ($this->_presignTtl <= 0) {
            return '';
        }
        try {
            $cmd = $this->_c()->getCommand('GetObject', ['Bucket' => $this->_bucket, 'Key' => $full]);
            $ttl = ($ttl !== null && (int) $ttl > 0) ? (int) $ttl : $this->_presignTtl;
            $req = $this->_c()->createPresignedRequest($cmd, '+' . $ttl . ' seconds');
            return (string) $req->getUri();
        } catch (Throwable $e) {
            return '';   // fall back to the streamer route
        }
    }

    /** Shared put path: build args, set content-type + optional public ACL, upload. */
    protected function _putObject($key, $visibility, array $body, $mime, $origKey)
    {
        $args = ['Bucket' => $this->_bucket, 'Key' => $this->_fullKey($origKey, $visibility)] + $body;
        if ($mime) {
            $args['ContentType'] = $mime;
        }
        if ($visibility === Tiger_Model_Media::VISIBILITY_PUBLIC && $this->_publicAcl) {
            $args['ACL'] = 'public-read';
        }
        try {
            $this->_c()->putObject($args);
        } catch (Throwable $e) {
            throw new RuntimeException('Tiger_Media_Storage_S3: could not store ' . $key . ' — ' . $e->getMessage(), 0, $e);
        }
    }

    /** Direct public URL: CDN host if set, else the S3/endpoint URL (path segments encoded). */
    protected function _publicUrl($full)
    {
        $path = implode('/', array_map('rawurlencode', explode('/', $full)));
        if ($this->_cdn !== '') {
            return 'https://' . $this->_cdn . '/' . $path;
        }
        $endpoint = rtrim((string) ($this->_settings['endpoint'] ?? ''), '/');
        if ($endpoint !== '') {
            $pathStyle = (int) ($this->_settings['use_path_style'] ?? 0) === 1;
            return $pathStyle ? $endpoint . '/' . $this->_bucket . '/' . $path
                              : preg_replace('#^(https?://)#', '$1' . $this->_bucket . '.', $endpoint) . '/' . $path;
        }
        return 'https://' . $this->_bucket . '.s3.' . $this->_region . '.amazonaws.com/' . $path;
    }

    /** Map a storage key + visibility to the full S3 object key (traversal-guarded). */
    protected function _fullKey($key, $visibility)
    {
        $key = ltrim(str_replace('\\', '/', (string) $key), '/');
        if ($key === '' || strpos($key, '..') !== false) {
            throw new RuntimeException('Tiger_Media_Storage_S3: invalid key');
        }
        $vis = ($visibility === Tiger_Model_Media::VISIBILITY_PUBLIC) ? $this->_publicPrefix : $this->_privatePrefix;
        return $this->_prefix . $vis . $key;
    }

    /** Build (memoized) the S3 client, honoring optional creds / endpoint / path-style. */
    protected function _c()
    {
        if ($this->_client !== null) {
            return $this->_client;
        }
        if (!class_exists('Aws\\S3\\S3Client')) {
            throw new RuntimeException('Tiger_Media_Storage_S3: aws/aws-sdk-php is not installed (composer require aws/aws-sdk-php).');
        }
        $conf = ['version' => 'latest', 'region' => $this->_region];
        if (!empty($this->_settings['key']) && !empty($this->_settings['secret'])) {
            $conf['credentials'] = ['key' => $this->_settings['key'], 'secret' => $this->_settings['secret']];
        }
        if (!empty($this->_settings['endpoint'])) {
            $conf['endpoint'] = $this->_settings['endpoint'];
        }
        if ((int) ($this->_settings['use_path_style'] ?? 0) === 1) {
            $conf['use_path_style_endpoint'] = true;
        }
        return $this->_client = new \Aws\S3\S3Client($conf);
    }

    /** Normalize a prefix to '' or 'segment/…/' (no leading slash, single trailing slash). */
    protected function _normPrefix($p)
    {
        $p = trim(str_replace('\\', '/', (string) $p), '/');
        return $p === '' ? '' : $p . '/';
    }
}
