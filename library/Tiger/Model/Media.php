<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Media — the file store's metadata (see migration 0018 + MEDIA.md).
 *
 * The bytes live behind a Tiger_Media_Storage adapter (by `disk` + `storage_key`); this
 * model is the queryable metadata gateway: it classifies uploads by kind, owns the library
 * query, and resolves URLs (a public object's direct/CDN URL, else the ACL-checked streamer
 * route). Variants (thumbnails, previews) are a JSON map of derivative keys.
 *
 * @api
 */
class Tiger_Model_Media extends Tiger_Model_Table
{
    protected $_name    = 'media';
    protected $_primary = 'media_id';

    const KIND_IMAGE    = 'image';
    const KIND_DOCUMENT = 'document';
    const KIND_PDF      = 'pdf';
    const KIND_VIDEO    = 'video';
    const KIND_AUDIO    = 'audio';
    const KIND_ARCHIVE  = 'archive';
    const KIND_OTHER    = 'other';

    /** Storage sub-folder per kind (tenant path grouping) — see kindFolder(). */
    const KIND_FOLDERS = [
        self::KIND_IMAGE    => 'images',
        self::KIND_VIDEO    => 'videos',
        self::KIND_AUDIO    => 'audio',
        self::KIND_PDF      => 'docs',
        self::KIND_DOCUMENT => 'docs',
        self::KIND_ARCHIVE  => 'files',
        self::KIND_OTHER    => 'files',
    ];

    const VISIBILITY_PUBLIC  = 'public';
    const VISIBILITY_PRIVATE = 'private';

    const SCAN_SKIPPED   = 'skipped';
    const SCAN_PENDING   = 'pending';
    const SCAN_CLEAN     = 'clean';
    const SCAN_INFECTED  = 'infected';
    const SCAN_REJECTED  = 'rejected';
    const SCAN_IN_REVIEW = 'in_review';
    const SCAN_APPROVED  = 'approved';

    /**
     * Classify an upload by extension against the configured allowlists (`media.allow.*`).
     * Returns the kind and whether it's allowed at all. `pdf` is split out of documents so
     * it gets the pdf.js preview path.
     *
     * @return array{kind:string, allowed:bool}
     */
    public static function classify($extension)
    {
        $ext = strtolower(ltrim((string) $extension, '.'));
        if ($ext === 'pdf') {
            return ['kind' => self::KIND_PDF, 'allowed' => self::_extAllowed('document', 'pdf')];
        }
        $groups = [
            self::KIND_IMAGE   => 'image',
            self::KIND_VIDEO   => 'video',
            self::KIND_AUDIO   => 'audio',
            self::KIND_ARCHIVE => 'archive',
        ];
        foreach ($groups as $kind => $group) {
            if (self::_extAllowed($group, $ext)) {
                return ['kind' => $kind, 'allowed' => true];
            }
        }
        if (self::_extAllowed('document', $ext)) {
            return ['kind' => self::KIND_DOCUMENT, 'allowed' => true];
        }
        return ['kind' => self::KIND_OTHER, 'allowed' => false];
    }

    /**
     * The storage sub-folder for a kind — groups a tenant's files by type under its org path
     * (`<org>/images/…`, `/videos/…`, `/docs/…`, `/files/…`): pdf+document → docs, archive+other
     * → files. Used to build the storage key; the adapter adds the visibility root (public/ |
     * private/) on top.
     */
    public static function kindFolder($kind)
    {
        return self::KIND_FOLDERS[$kind] ?? 'files';
    }

    /** Is $ext in the `media.allow.<group>` list? */
    protected static function _extAllowed($group, $ext)
    {
        $cfg = Zend_Registry::isRegistered('Zend_Config') ? Zend_Registry::get('Zend_Config') : null;
        $allow = ($cfg && $cfg->get('media') && $cfg->media->get('allow')) ? (string) $cfg->media->allow->get($group) : '';
        if ($allow === '') {
            return false;
        }
        return in_array(strtolower($ext), array_map('trim', explode(',', strtolower($allow))), true);
    }

    /**
     * A usable URL for a media item (or one of its variants): the adapter's direct/CDN URL
     * for public objects, else the ACL-checked streamer route. Accepts a row array.
     *
     * @param array       $media   a media row (array)
     * @param string|null $variant a variants key (e.g. 'thumbnail'); null/'original' = the file itself
     */
    public function url(array $media, $variant = null)
    {
        $visibility = (string) ($media['visibility'] ?? self::VISIBILITY_PUBLIC);
        $key        = (string) ($media['storage_key'] ?? '');

        if ($variant !== null && $variant !== 'original') {
            $variants = $this->variants($media);
            if (!empty($variants[$variant]['key'])) {
                $key = (string) $variants[$variant]['key'];
            } else {
                $variant = null;   // no such variant -> fall back to the original
            }
        }
        if ($key === '') {
            return '';
        }

        try {
            $direct = Tiger_Media_Storage::disk($media['disk'] ?? null)->url($key, $visibility);
        } catch (Throwable $e) {
            $direct = '';
        }
        if ($direct !== '') {
            return $direct;
        }
        // Private (or an adapter that can't mint a URL) -> stream through the ACL route
        // (Media_FileController::serveAction; zero-config module path).
        $route = '/media/file/serve/id/' . rawurlencode((string) ($media['media_id'] ?? ''));
        if ($variant !== null && $variant !== 'original') {
            $route .= '/v/' . rawurlencode($variant);
        }
        return $route;
    }

    /** Decode the variants JSON to an array. */
    public function variants(array $media)
    {
        $v = $media['variants'] ?? null;
        if (is_array($v)) {
            return $v;
        }
        return $v ? (array) json_decode((string) $v, true) : [];
    }

    /** The best display URL for a thumbnail-sized preview (thumbnail variant, else original). */
    public function thumbUrl(array $media)
    {
        $variants = $this->variants($media);
        return isset($variants['thumbnail']) ? $this->url($media, 'thumbnail') : $this->url($media);
    }

    /**
     * DataTables source for the Media Library: kind filter, search across filename/title/
     * caption, sort, paginate. Query lives here; the service formats + ACL-gates.
     *
     * @return array{total:int,filtered:int,rows:array}
     */
    public function datatable(array $opts)
    {
        $db     = $this->getAdapter();
        $search = (string) ($opts['search'] ?? '');
        $kind   = (string) ($opts['kind'] ?? '');
        $limit  = max(1, (int) ($opts['limit'] ?? 24));
        $offset = max(0, (int) ($opts['offset'] ?? 0));

        $orderCols = [1 => 'filename', 2 => 'kind', 3 => 'file_size', 4 => 'created_at'];
        $col = (int) ($opts['orderCol'] ?? -1);
        $dir = (strtoupper((string) ($opts['orderDir'] ?? '')) === 'ASC') ? 'ASC' : 'DESC';
        $orderSql = isset($orderCols[$col]) ? ($orderCols[$col] . ' ' . $dir) : 'created_at DESC';

        $scope = function ($sel) use ($kind) {
            $sel->where('deleted = 0');
            if ($kind !== '') { $sel->where('kind = ?', $kind); }
        };
        $searchFn = function ($sel) use ($db, $search) {
            if ($search === '') { return; }
            $like  = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $parts = [];
            foreach (['filename', 'title', 'caption'] as $c) { $parts[] = $db->quoteInto("$c LIKE ?", $like); }
            $sel->where('(' . implode(' OR ', $parts) . ')');
        };

        $totalSel = $db->select()->from($this->_name, ['c' => new Zend_Db_Expr('COUNT(*)')]);
        $scope($totalSel);
        $total = (int) $db->fetchOne($totalSel);

        $filteredSel = $db->select()->from($this->_name, ['c' => new Zend_Db_Expr('COUNT(*)')]);
        $scope($filteredSel); $searchFn($filteredSel);
        $filtered = (int) $db->fetchOne($filteredSel);

        $rowsSel = $db->select()
            ->from($this->_name, ['media_id', 'disk', 'storage_key', 'visibility', 'kind', 'mime_type',
                'extension', 'file_size', 'width', 'height', 'filename', 'title', 'caption', 'alt_text',
                'variants', 'scan_status', 'created_at'])
            ->order(new Zend_Db_Expr($orderSql))
            ->limit($limit, $offset);
        $scope($rowsSel); $searchFn($rowsSel);

        return ['total' => $total, 'filtered' => $filtered, 'rows' => $db->fetchAll($rowsSel)];
    }
}
