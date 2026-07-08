<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Media_Storage_Gcs — Google Cloud Storage media storage.
 *
 * Mirrors the S3 adapter (see Tiger_Media_Storage_S3): one bucket, `visibility` selects a key
 * prefix (`public_prefix`/`private_prefix`) so a bucket IAM policy can expose only the public
 * prefix. PUBLIC objects → a direct CDN/`storage.googleapis.com` URL; PRIVATE objects → a
 * short-lived V4 **signed** URL (`presign_ttl`), or '' when signing is off/`presign_ttl=0`, in
 * which case the media layer streams through the ACL-checked /media/file/<id> route.
 *
 * Credentials: a service-account JSON via `key_file` (needed to *sign* private URLs), else
 * Application Default Credentials (GCE/GKE metadata, `GOOGLE_APPLICATION_CREDENTIALS`).
 *
 * Requires `google/cloud-storage` (optional dependency — composer suggest / MEDIA.md); the
 * adapter throws a clear message if it's missing, and only loads when a `gcs` disk is used.
 *
 * @api
 */
class Tiger_Media_Storage_Gcs implements Tiger_Media_Storage_Interface
{
    const DEFAULT_TTL = 900;

    protected $_bucketName;
    protected $_prefix;
    protected $_publicPrefix;
    protected $_privatePrefix;
    protected $_cdn;
    protected $_presignTtl;
    protected $_publicAcl;
    protected $_settings;

    /** @var \Google\Cloud\Storage\Bucket|null memoized */
    protected $_bucket;

    public function __construct(array $config)
    {
        $this->_bucketName = (string) ($config['bucket'] ?? '');
        if ($this->_bucketName === '') {
            throw new RuntimeException('Tiger_Media_Storage_Gcs: media.disks.<name>.bucket is required.');
        }
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
        $h = @fopen($sourcePath, 'rb');
        if (!$h) {
            throw new RuntimeException('Tiger_Media_Storage_Gcs: cannot read source ' . $sourcePath);
        }
        try {
            $this->_upload($key, $visibility, $h, $mime);
        } finally {
            if (is_resource($h)) { fclose($h); }
        }
    }

    public function write($key, $bytes, $visibility, $mime = null)
    {
        $this->_upload($key, $visibility, $bytes, $mime);
    }

    public function get($key, $visibility)
    {
        try {
            return (string) $this->_object($key, $visibility)->downloadAsString();
        } catch (Throwable $e) {
            throw new RuntimeException('Tiger_Media_Storage_Gcs: not found ' . $key, 0, $e);
        }
    }

    public function stream($key, $visibility)
    {
        try {
            $body = $this->_object($key, $visibility)->downloadAsStream();
        } catch (Throwable $e) {
            throw new RuntimeException('Tiger_Media_Storage_Gcs: not found ' . $key, 0, $e);
        }
        if ($body instanceof \Psr\Http\Message\StreamInterface) {
            $res = $body->detach();
            if (is_resource($res)) {
                return $res;
            }
            $body = (string) $body;
        }
        $fh = fopen('php://temp', 'r+b');
        fwrite($fh, (string) $body);
        rewind($fh);
        return $fh;
    }

    public function delete($key, $visibility)
    {
        try {
            $this->_object($key, $visibility)->delete();   // idempotent: missing is not an error
        } catch (Throwable $e) {
            // swallow — best-effort delete, like the filesystem adapter
        }
    }

    public function exists($key, $visibility)
    {
        try {
            return (bool) $this->_object($key, $visibility)->exists();
        } catch (Throwable $e) {
            return false;
        }
    }

    public function size($key, $visibility)
    {
        try {
            $info = $this->_object($key, $visibility)->info();
            return (int) ($info['size'] ?? 0);
        } catch (Throwable $e) {
            return 0;
        }
    }

    public function url($key, $visibility, $ttl = null)
    {
        $full = $this->_fullKey($key, $visibility);

        if ($visibility === Tiger_Model_Media::VISIBILITY_PUBLIC) {
            $path = implode('/', array_map('rawurlencode', explode('/', $full)));
            if ($this->_cdn !== '') {
                return 'https://' . $this->_cdn . '/' . $path;
            }
            return 'https://storage.googleapis.com/' . rawurlencode($this->_bucketName) . '/' . $path;
        }
        // Private: a short-lived V4 signed URL (needs signing creds). 0 -> stream via the app.
        if ($this->_presignTtl <= 0) {
            return '';
        }
        try {
            $ttl = ($ttl !== null && (int) $ttl > 0) ? (int) $ttl : $this->_presignTtl;
            return (string) $this->_object($key, $visibility)->signedUrl(
                new \DateTime('+' . $ttl . ' seconds'),
                ['version' => 'v4']
            );
        } catch (Throwable $e) {
            return '';   // fall back to the streamer route
        }
    }

    /** Upload a string or stream resource under the mapped key. */
    protected function _upload($key, $visibility, $data, $mime)
    {
        $opts = ['name' => $this->_fullKey($key, $visibility)];
        if ($mime) {
            $opts['metadata'] = ['contentType' => $mime];
        }
        if ($visibility === Tiger_Model_Media::VISIBILITY_PUBLIC && $this->_publicAcl) {
            $opts['predefinedAcl'] = 'publicRead';
        }
        try {
            $this->_bkt()->upload($data, $opts);
        } catch (Throwable $e) {
            throw new RuntimeException('Tiger_Media_Storage_Gcs: could not store ' . $key . ' — ' . $e->getMessage(), 0, $e);
        }
    }

    /** A StorageObject for the mapped key. */
    protected function _object($key, $visibility)
    {
        return $this->_bkt()->object($this->_fullKey($key, $visibility));
    }

    /** Map a storage key + visibility to the full object name (traversal-guarded). */
    protected function _fullKey($key, $visibility)
    {
        $key = ltrim(str_replace('\\', '/', (string) $key), '/');
        if ($key === '' || strpos($key, '..') !== false) {
            throw new RuntimeException('Tiger_Media_Storage_Gcs: invalid key');
        }
        $vis = ($visibility === Tiger_Model_Media::VISIBILITY_PUBLIC) ? $this->_publicPrefix : $this->_privatePrefix;
        return $this->_prefix . $vis . $key;
    }

    /** Build (memoized) the GCS bucket handle. */
    protected function _bkt()
    {
        if ($this->_bucket !== null) {
            return $this->_bucket;
        }
        if (!class_exists('Google\\Cloud\\Storage\\StorageClient')) {
            throw new RuntimeException('Tiger_Media_Storage_Gcs: google/cloud-storage is not installed (composer require google/cloud-storage).');
        }
        $conf = [];
        if (!empty($this->_settings['project_id'])) {
            $conf['projectId'] = $this->_settings['project_id'];
        }
        if (!empty($this->_settings['key_file'])) {
            $conf['keyFilePath'] = $this->_settings['key_file'];
        }
        $client = new \Google\Cloud\Storage\StorageClient($conf);
        return $this->_bucket = $client->bucket($this->_bucketName);
    }

    /** Normalize a prefix to '' or 'segment/…/' (no leading slash, single trailing slash). */
    protected function _normPrefix($p)
    {
        $p = trim(str_replace('\\', '/', (string) $p), '/');
        return $p === '' ? '' : $p . '/';
    }
}
