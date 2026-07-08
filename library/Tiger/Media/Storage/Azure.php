<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Media_Storage_Azure — Azure Blob Storage media storage.
 *
 * Same contract as the S3/GCS adapters, adapted to Azure's model where **public access is a
 * container-level setting** (not per-object/prefix). Two layouts:
 *   - Two containers (set BOTH `public_container` + `private_container`): visibility picks the
 *     container; make the public one's access level "blob". Blob name = `<prefix><key>`.
 *   - One container (`container`): visibility picks a key prefix (`public_prefix`/
 *     `private_prefix`) within it, like S3/GCS. "Public" is only truly public if that
 *     container's access level allows it — else front it with a CDN.
 *
 * PUBLIC → a direct CDN / `*.blob.core.windows.net` URL. PRIVATE → a short-lived **SAS** URL
 * (`presign_ttl`, needs the account key), or '' when the key is absent / `presign_ttl=0` (the
 * media layer then streams through the ACL-checked /media/file/<id> route).
 *
 * Auth: a `connection_string`, or `account` + `key`. Requires `microsoft/azure-storage-blob`
 * (optional dependency — composer suggest / MEDIA.md); the adapter throws a clear message if
 * it's missing and only loads when an `azure` disk is used.
 *
 * @api
 */
class Tiger_Media_Storage_Azure implements Tiger_Media_Storage_Interface
{
    const DEFAULT_TTL = 900;

    protected $_container;         // single-container mode base
    protected $_publicContainer;
    protected $_privateContainer;
    protected $_twoContainer;      // bool: separate public/private containers
    protected $_prefix;
    protected $_publicPrefix;
    protected $_privatePrefix;
    protected $_cdn;
    protected $_presignTtl;
    protected $_settings;

    /** @var \MicrosoftAzure\Storage\Blob\BlobRestProxy|null memoized */
    protected $_client;

    public function __construct(array $config)
    {
        $this->_container        = (string) ($config['container'] ?? '');
        $this->_publicContainer  = (string) ($config['public_container'] ?? '');
        $this->_privateContainer = (string) ($config['private_container'] ?? '');
        $this->_twoContainer     = $this->_publicContainer !== '' && $this->_privateContainer !== '';
        if (!$this->_twoContainer && $this->_container === '') {
            throw new RuntimeException('Tiger_Media_Storage_Azure: set media.disks.<name>.container (or both public_container + private_container).');
        }
        $this->_prefix        = $this->_normPrefix($config['prefix'] ?? '');
        $this->_publicPrefix  = $this->_normPrefix($config['public_prefix']  ?? 'public/');
        $this->_privatePrefix = $this->_normPrefix($config['private_prefix'] ?? 'private/');
        $this->_cdn           = rtrim((string) ($config['cdn'] ?? ''), '/');
        $this->_presignTtl    = (int) ($config['presign_ttl'] ?? self::DEFAULT_TTL) ?: self::DEFAULT_TTL;
        $this->_settings      = $config;
    }

    public function put($key, $sourcePath, $visibility, $mime = null)
    {
        $h = @fopen($sourcePath, 'rb');
        if (!$h) {
            throw new RuntimeException('Tiger_Media_Storage_Azure: cannot read source ' . $sourcePath);
        }
        try {
            $this->_create($key, $visibility, $h, $mime);
        } finally {
            if (is_resource($h)) { fclose($h); }
        }
    }

    public function write($key, $bytes, $visibility, $mime = null)
    {
        $this->_create($key, $visibility, $bytes, $mime);
    }

    public function get($key, $visibility)
    {
        try {
            $r = $this->_c()->getBlob($this->_container($visibility), $this->_blob($key, $visibility));
            return (string) stream_get_contents($r->getContentStream());
        } catch (Throwable $e) {
            throw new RuntimeException('Tiger_Media_Storage_Azure: not found ' . $key, 0, $e);
        }
    }

    public function stream($key, $visibility)
    {
        try {
            $r = $this->_c()->getBlob($this->_container($visibility), $this->_blob($key, $visibility));
        } catch (Throwable $e) {
            throw new RuntimeException('Tiger_Media_Storage_Azure: not found ' . $key, 0, $e);
        }
        $body = $r->getContentStream();
        if (is_resource($body)) {
            return $body;
        }
        $fh = fopen('php://temp', 'r+b');
        fwrite($fh, (string) $body);
        rewind($fh);
        return $fh;
    }

    public function delete($key, $visibility)
    {
        try {
            $this->_c()->deleteBlob($this->_container($visibility), $this->_blob($key, $visibility));
        } catch (Throwable $e) {
            // swallow — idempotent best-effort delete
        }
    }

    public function exists($key, $visibility)
    {
        try {
            $this->_c()->getBlobProperties($this->_container($visibility), $this->_blob($key, $visibility));
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function size($key, $visibility)
    {
        try {
            $p = $this->_c()->getBlobProperties($this->_container($visibility), $this->_blob($key, $visibility));
            return (int) $p->getProperties()->getContentLength();
        } catch (Throwable $e) {
            return 0;
        }
    }

    public function url($key, $visibility, $ttl = null)
    {
        $container = $this->_container($visibility);
        $blob      = $this->_blob($key, $visibility);
        $path      = rawurlencode($container) . '/' . implode('/', array_map('rawurlencode', explode('/', $blob)));

        if ($visibility === Tiger_Model_Media::VISIBILITY_PUBLIC) {
            return ($this->_cdn !== '') ? 'https://' . $this->_cdn . '/' . $path : $this->_baseUrl() . '/' . $path;
        }
        // Private: a short-lived SAS URL (needs the account key). 0 -> stream via the app.
        if ($this->_presignTtl <= 0) {
            return '';
        }
        $account = $this->_accountName();
        $accKey  = $this->_accountKey();
        if ($account === '' || $accKey === '') {
            return '';   // can't sign -> streamer route
        }
        try {
            $ttl    = ($ttl !== null && (int) $ttl > 0) ? (int) $ttl : $this->_presignTtl;
            $helper = new \MicrosoftAzure\Storage\Blob\BlobSharedAccessSignatureHelper($account, $accKey);
            $sas    = $helper->generateBlobServiceSharedAccessSignatureToken(
                \MicrosoftAzure\Storage\Common\Internal\Resources::RESOURCE_TYPE_BLOB,   // 'b'
                $container . '/' . $blob,
                'r',                                      // read-only
                new \DateTime('+' . $ttl . ' seconds'),   // expiry
                new \DateTime('-5 minutes')               // start (clock-skew slack)
            );
            return $this->_baseUrl() . '/' . $path . '?' . $sas;
        } catch (Throwable $e) {
            return '';
        }
    }

    /** Create a block blob from a string or stream resource. */
    protected function _create($key, $visibility, $content, $mime)
    {
        $opts = new \MicrosoftAzure\Storage\Blob\Models\CreateBlockBlobOptions();
        if ($mime) {
            $opts->setContentType($mime);
        }
        try {
            $this->_c()->createBlockBlob($this->_container($visibility), $this->_blob($key, $visibility), $content, $opts);
        } catch (Throwable $e) {
            throw new RuntimeException('Tiger_Media_Storage_Azure: could not store ' . $key . ' — ' . $e->getMessage(), 0, $e);
        }
    }

    /** The container for a visibility (two-container mode) or the single base container. */
    protected function _container($visibility)
    {
        if ($this->_twoContainer) {
            return ($visibility === Tiger_Model_Media::VISIBILITY_PUBLIC) ? $this->_publicContainer : $this->_privateContainer;
        }
        return $this->_container;
    }

    /** Blob name: base prefix + (visibility prefix only in single-container mode) + key. */
    protected function _blob($key, $visibility)
    {
        $key = ltrim(str_replace('\\', '/', (string) $key), '/');
        if ($key === '' || strpos($key, '..') !== false) {
            throw new RuntimeException('Tiger_Media_Storage_Azure: invalid key');
        }
        $vis = '';
        if (!$this->_twoContainer) {
            $vis = ($visibility === Tiger_Model_Media::VISIBILITY_PUBLIC) ? $this->_publicPrefix : $this->_privatePrefix;
        }
        return $this->_prefix . $vis . $key;
    }

    /** The blob-service base URL (endpoint override, else the account's public endpoint). */
    protected function _baseUrl()
    {
        $endpoint = rtrim((string) ($this->_settings['endpoint'] ?? ''), '/');
        if ($endpoint !== '') {
            return $endpoint;
        }
        return 'https://' . $this->_accountName() . '.blob.core.windows.net';
    }

    protected function _accountName()
    {
        if (!empty($this->_settings['account'])) {
            return (string) $this->_settings['account'];
        }
        return $this->_fromConnStr('AccountName');
    }

    protected function _accountKey()
    {
        if (!empty($this->_settings['key'])) {
            return (string) $this->_settings['key'];
        }
        return $this->_fromConnStr('AccountKey');
    }

    /** Pull a field out of a `connection_string` (AccountName / AccountKey / BlobEndpoint). */
    protected function _fromConnStr($field)
    {
        $cs = (string) ($this->_settings['connection_string'] ?? '');
        if ($cs === '') {
            return '';
        }
        foreach (explode(';', $cs) as $pair) {
            $eq = strpos($pair, '=');
            if ($eq !== false && strcasecmp(trim(substr($pair, 0, $eq)), $field) === 0) {
                return trim(substr($pair, $eq + 1));
            }
        }
        return '';
    }

    /** Build (memoized) the blob-service client from a connection string or account+key. */
    protected function _c()
    {
        if ($this->_client !== null) {
            return $this->_client;
        }
        if (!class_exists('MicrosoftAzure\\Storage\\Blob\\BlobRestProxy')) {
            throw new RuntimeException('Tiger_Media_Storage_Azure: microsoft/azure-storage-blob is not installed (composer require microsoft/azure-storage-blob).');
        }
        $cs = (string) ($this->_settings['connection_string'] ?? '');
        if ($cs === '') {
            $account = $this->_accountName();
            $accKey  = $this->_accountKey();
            if ($account === '' || $accKey === '') {
                throw new RuntimeException('Tiger_Media_Storage_Azure: need connection_string, or account + key.');
            }
            $cs = 'DefaultEndpointsProtocol=https;AccountName=' . $account . ';AccountKey=' . $accKey . ';EndpointSuffix=core.windows.net';
            if (!empty($this->_settings['endpoint'])) {
                $cs .= ';BlobEndpoint=' . rtrim((string) $this->_settings['endpoint'], '/');
            }
        }
        return $this->_client = \MicrosoftAzure\Storage\Blob\BlobRestProxy::createBlobService($cs);
    }

    protected function _normPrefix($p)
    {
        $p = trim(str_replace('\\', '/', (string) $p), '/');
        return $p === '' ? '' : $p . '/';
    }
}
