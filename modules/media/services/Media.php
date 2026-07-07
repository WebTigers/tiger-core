<?php
/**
 * Media_Service_Media — the /api service for the Media Library (upload / list / update /
 * delete). Thin + ACL-gated (admin+); the storage and metadata live in the engine
 * (Tiger_Media_Storage, Tiger_Model_Media). See MEDIA.md for the upload pipeline.
 *
 * Uploads are multipart POSTs to /api (module=media, service=media, method=upload) — one
 * file per request so the client can show per-file progress; the file rides in $_FILES.
 * Scan hooks (ClamAV / AI) are P4 and config-gated; here uploads are stored + recorded.
 */
class Media_Service_Media extends Tiger_Service_Service
{
    /** Receive one uploaded file: validate -> store -> record. Returns the media row. */
    public function upload(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }

        $file = isset($_FILES['file']) ? $_FILES['file'] : null;
        if (!$file || !is_uploaded_file((string) $file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
            $this->_error('media.error.upload'); return;
        }

        $original = (string) $file['name'];
        $tmp      = (string) $file['tmp_name'];
        $size     = (int) $file['size'];
        $ext      = strtolower((string) pathinfo($original, PATHINFO_EXTENSION));

        $max = $this->_cfgInt('max_upload', 52428800);
        if ($size > $max) { $this->_error('media.error.too_large'); return; }

        $class = Tiger_Model_Media::classify($ext);
        if (!$class['allowed']) { $this->_error('media.error.type'); return; }

        // TODO(P4): virus scan (media.scan.clamav) + AI image review (media.scan.image) here,
        // before store; reject with a message on infected / over-threshold.

        $mime = $this->_mime($tmp, $ext);
        $visibility = (($params['visibility'] ?? 'public') === Tiger_Model_Media::VISIBILITY_PRIVATE)
            ? Tiger_Model_Media::VISIBILITY_PRIVATE : Tiger_Model_Media::VISIBILITY_PUBLIC;

        // Opaque, collision-free storage key sharded by month: 2026/07/<random>.<ext>
        $key  = date('Y/m') . '/' . bin2hex(random_bytes(16)) . ($ext !== '' ? '.' . $ext : '');
        $disk = Tiger_Media_Storage::defaultDisk();

        try {
            Tiger_Media_Storage::disk($disk)->put($key, $tmp, $visibility, $mime);
        } catch (Throwable $e) {
            $this->_error(APPLICATION_ENV !== 'production' ? $e->getMessage() : 'core.api.error.general'); return;
        }

        $dims = ($class['kind'] === Tiger_Model_Media::KIND_IMAGE) ? @getimagesize($tmp) : false;

        $model = new Tiger_Model_Media();
        try {
            $id = $model->insert([
                'org_id'      => $this->_orgId(),
                'locale'      => '',
                'disk'        => $disk,
                'storage_key' => $key,
                'visibility'  => $visibility,
                'kind'        => $class['kind'],
                'mime_type'   => $mime,
                'extension'   => $ext,
                'file_size'   => $size,
                'checksum'    => @hash_file('sha256', $tmp) ?: null,
                'width'       => $dims ? (int) $dims[0] : null,
                'height'      => $dims ? (int) $dims[1] : null,
                'filename'    => $original,
                'title'       => (string) pathinfo($original, PATHINFO_FILENAME),
                'scan_status' => Tiger_Model_Media::SCAN_SKIPPED,
            ]);
        } catch (Throwable $e) {
            Tiger_Media_Storage::disk($disk)->delete($key, $visibility);   // don't orphan the bytes
            $this->_error(APPLICATION_ENV !== 'production' ? $e->getMessage() : 'core.api.error.general'); return;
        }

        // Variants: server-side (GD) when available + enabled, else a browser-made thumbnail
        // (posted as $_FILES['thumbnail']). Failure here is non-fatal — the original is safe.
        try {
            $media = $model->findById($id)->toArray();
            if (Tiger_Media_Image::supports($mime) && $this->_serverEnabled()) {
                $this->_makeServerVariants($model, $id, $media, $tmp, $mime);
            } elseif (isset($_FILES['thumbnail']) && is_uploaded_file((string) $_FILES['thumbnail']['tmp_name'])) {
                $this->_storeThumbnail($model, $id, $media, (string) $_FILES['thumbnail']['tmp_name']);
            }
        } catch (Throwable $e) {
            // keep the upload; variants are best-effort
        }

        $this->_success(['media' => $this->_present($model->findById($id))], 'media.uploaded');
    }

    /** DataTables source for the Library grid (thumbnails + metadata). */
    public function datatable(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }

        $dt   = $this->_dtParams($params);
        $kinds = [Tiger_Model_Media::KIND_IMAGE, Tiger_Model_Media::KIND_DOCUMENT, Tiger_Model_Media::KIND_PDF,
                  Tiger_Model_Media::KIND_VIDEO, Tiger_Model_Media::KIND_AUDIO, Tiger_Model_Media::KIND_ARCHIVE, Tiger_Model_Media::KIND_OTHER];
        $data = (new Tiger_Model_Media())->datatable([
            'search'   => $dt['search'],
            'kind'     => in_array(($params['kind'] ?? ''), $kinds, true) ? (string) $params['kind'] : '',
            'orderCol' => isset($dt['order'][0]) ? $dt['order'][0]['column'] : -1,
            'orderDir' => isset($dt['order'][0]) ? $dt['order'][0]['dir'] : '',
            'offset'   => $dt['start'],
            'limit'    => $dt['length'],
        ]);

        $canDelete = $this->_isAdmin(static::class, 'delete');
        $rows = [];
        foreach ($data['rows'] as $r) {
            $rows[] = $this->_present($r) + ['can_delete' => $canDelete];
        }
        $this->_dtResponse($dt['draw'], $data['total'], $data['filtered'], $rows);
    }

    /** Edit editorial fields (title / caption / alt / visibility). */
    public function update(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }
        $id = (string) ($params['media_id'] ?? '');
        $model = new Tiger_Model_Media();
        if ($id === '' || !$model->findById($id)) { $this->_error('core.api.error.general'); return; }

        $data = [];
        foreach (['title', 'caption', 'alt_text'] as $f) {
            if (array_key_exists($f, $params)) { $data[$f] = trim((string) $params[$f]); }
        }
        if (isset($params['visibility'])) {
            $data['visibility'] = ($params['visibility'] === Tiger_Model_Media::VISIBILITY_PRIVATE)
                ? Tiger_Model_Media::VISIBILITY_PRIVATE : Tiger_Model_Media::VISIBILITY_PUBLIC;
        }
        if (!$data) { $this->_error('core.api.error.general'); return; }

        try {
            $model->update($data, $model->getAdapter()->quoteInto('media_id = ?', $id));
            $this->_success(['media' => $this->_present($model->findById($id))], 'media.saved');
        } catch (Throwable $e) {
            $this->_error(APPLICATION_ENV !== 'production' ? $e->getMessage() : 'core.api.error.general');
        }
    }

    /** Soft-delete the row AND remove the stored bytes (+ variants). */
    public function delete(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }
        $id  = (string) ($params['media_id'] ?? '');
        $model = new Tiger_Model_Media();
        $row = $id !== '' ? $model->findById($id) : null;
        if (!$row) { $this->_error('core.api.error.general'); return; }
        $media = $row->toArray();

        try {
            $adapter = Tiger_Media_Storage::disk($media['disk']);
            $adapter->delete($media['storage_key'], $media['visibility']);
            foreach ($model->variants($media) as $v) {
                if (!empty($v['key'])) { $adapter->delete($v['key'], $media['visibility']); }
            }
        } catch (Throwable $e) {
            // bytes may already be gone — proceed to soft-delete the row regardless
        }
        $model->softDelete($model->getAdapter()->quoteInto('media_id = ?', $id));
        $this->_success(['media_id' => $id], 'media.deleted');
    }

    /** Generate + store the configured image variants (GD) and record the variants JSON. */
    protected function _makeServerVariants(Tiger_Model_Media $model, $id, array $media, $sourcePath, $mime)
    {
        $variants = Tiger_Media_Image::variants($sourcePath, $mime, $this->_presets(), $this->_quality());
        if (!$variants) {
            return;
        }
        $adapter = Tiger_Media_Storage::disk($media['disk']);
        $stored  = [];
        foreach ($variants as $name => $v) {
            $key = $this->_variantKey($media['storage_key'], $name, (string) $media['extension']);
            try {
                $adapter->put($key, $v['path'], $media['visibility'], $v['mime']);
                $stored[$name] = ['key' => $key, 'w' => $v['width'], 'h' => $v['height']];
            } catch (Throwable $e) {
                // skip this variant
            }
            @unlink($v['path']);
        }
        if ($stored) {
            $model->update(['variants' => json_encode($stored)], $model->getAdapter()->quoteInto('media_id = ?', $id));
        }
    }

    /** Store a browser-generated thumbnail as the single 'thumbnail' variant (no-GD fallback). */
    protected function _storeThumbnail(Tiger_Model_Media $model, $id, array $media, $thumbPath)
    {
        $info = @getimagesize($thumbPath);
        $key  = $this->_variantKey($media['storage_key'], 'thumbnail', 'jpg');
        Tiger_Media_Storage::disk($media['disk'])->put($key, $thumbPath, $media['visibility'], 'image/jpeg');
        $entry = ['key' => $key, 'w' => $info ? (int) $info[0] : null, 'h' => $info ? (int) $info[1] : null];
        $model->update(
            ['variants' => json_encode(['thumbnail' => $entry])],
            $model->getAdapter()->quoteInto('media_id = ?', $id)
        );
    }

    /** Variant storage key: `<base>.<preset>.<ext>` alongside the original. */
    protected function _variantKey($storageKey, $preset, $ext)
    {
        $base = preg_replace('/\.[^.\/]+$/', '', (string) $storageKey);
        return $base . '.' . $preset . '.' . ($ext !== '' ? strtolower($ext) : 'img');
    }

    /** Configured presets as [name => longest-edge px], only the positive ones. */
    protected function _presets()
    {
        $node = $this->_variantsCfg();
        $out  = [];
        foreach (['thumbnail', 'small', 'medium', 'large'] as $p) {
            $v = $node ? (int) $node->get($p) : 0;
            if ($v > 0) { $out[$p] = $v; }
        }
        return $out;
    }

    /** JPEG/WebP quality (default 90). */
    protected function _quality()
    {
        $node = $this->_variantsCfg();
        $q = $node ? (int) $node->get('quality') : 0;
        return $q > 0 ? $q : 90;
    }

    /** Use GD server-side when available? (media.variants.server; default on.) */
    protected function _serverEnabled()
    {
        $node = $this->_variantsCfg();
        if (!$node) { return true; }
        $s = $node->get('server');
        return ($s === null) ? true : ((int) $s !== 0);
    }

    protected function _variantsCfg()
    {
        $cfg = Zend_Registry::isRegistered('Zend_Config') ? Zend_Registry::get('Zend_Config') : null;
        return ($cfg && $cfg->get('media') && $cfg->media->get('variants')) ? $cfg->media->variants : null;
    }

    /** Shape a media row for the client (adds URLs; hides storage internals). */
    protected function _present($row)
    {
        $m = is_array($row) ? $row : $row->toArray();
        $model = new Tiger_Model_Media();
        return [
            'media_id'   => $m['media_id'],
            'kind'       => $m['kind'],
            'mime_type'  => $m['mime_type'],
            'extension'  => $m['extension'],
            'file_size'  => (int) $m['file_size'],
            'filename'   => $m['filename'],
            'title'      => $m['title'],
            'caption'    => $m['caption'] ?? null,
            'alt_text'   => $m['alt_text'] ?? null,
            'visibility' => $m['visibility'],
            'width'      => isset($m['width']) ? (int) $m['width'] : null,
            'height'     => isset($m['height']) ? (int) $m['height'] : null,
            'scan_status'=> $m['scan_status'] ?? null,
            'url'        => $model->url($m),
            'thumb'      => $model->thumbUrl($m),
            'large'      => $model->url($m, 'large'),   // lightbox source (falls back to original)
        ];
    }

    /** The uploading admin's org scope ('' when org-less / global). */
    protected function _orgId()
    {
        $idn = Zend_Auth::getInstance()->getIdentity();
        return ($idn && !empty($idn->org_id)) ? (string) $idn->org_id : '';
    }

    /** Best-effort MIME from the file (finfo), falling back to the extension map. */
    protected function _mime($tmp, $ext)
    {
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            $m  = $fi ? finfo_file($fi, $tmp) : false;
            if ($fi) { finfo_close($fi); }
            if ($m) { return (string) $m; }
        }
        return 'application/octet-stream';
    }

    protected function _cfgInt($key, $default)
    {
        $cfg = Zend_Registry::isRegistered('Zend_Config') ? Zend_Registry::get('Zend_Config') : null;
        $media = $cfg ? $cfg->get('media') : null;
        return ($media && $media->get($key) !== null) ? (int) $media->get($key) : $default;
    }
}
