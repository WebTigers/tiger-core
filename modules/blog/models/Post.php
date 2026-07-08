<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Blog_Model_Post — an article is a `page` row (`type='article'`).
 *
 * Extends Tiger_Model_Page, so it inherits the whole content engine: transactional
 * save() with version snapshots, slug→301 redirects, tenancy, i18n, scheduling. This
 * subclass adds the ARTICLE layer on top:
 *   - the `article` type + article-scoped queries (list, resolve),
 *   - the `page.meta` JSON convention (kicker / subtitle / preamble / excerpt / hero /
 *     author / SEO) — packed on save, unpacked for display,
 *   - reading-time derivation.
 *
 * The one thing NOT here is taxonomy — that's Blog_Model_Taxonomy (the post↔term join).
 * The article's scalar metadata rides in page.meta (versioned for free by page_version);
 * only categories/tags are relational. See the posts-on-page design doc.
 *
 * @api
 */
class Blog_Model_Post extends Tiger_Model_Page
{
    const TYPE_ARTICLE = 'article';

    /** Default shape of an article's page.meta payload. */
    const META_DEFAULTS = [
        'kicker'           => '',
        'subtitle'         => '',
        'preamble'         => '',
        'excerpt'          => '',
        'feature_media_id' => '',
        'author_id'        => '',
        'reading_time'     => 0,
        'allow_comments'   => true,
        'seo'              => ['title' => '', 'description' => '', 'og_image_id' => '', 'canonical' => ''],
    ];

    /**
     * Save an article: build the page row (type=article) + the meta JSON from editor
     * fields and defer to Tiger_Model_Page::save() (transactional write + version
     * snapshot + slug redirect). Returns the page_id. Taxonomy links are synced
     * separately by the service (the join carries no version history).
     *
     * @param array       $fields editor values (title, slug, body, kicker, subtitle, …)
     * @param string|null $postId update this article, or null to create
     */
    public function saveArticle(array $fields, $postId = null)
    {
        $data = [
            'type'         => self::TYPE_ARTICLE,
            'page_key'     => (string) $fields['page_key'],
            'slug'         => (string) $fields['slug'],
            'locale'       => (string) ($fields['locale'] ?? 'en'),
            'title'        => (string) ($fields['title'] ?? ''),
            'body'         => (string) ($fields['body'] ?? ''),
            'format'       => self::FORMAT_HTML,   // the editor emits HTML
            'status'       => (string) ($fields['status'] ?? self::STATUS_DRAFT),
            'published_at' => ($fields['published_at'] ?? '') !== '' ? (string) $fields['published_at'] : null,
            'meta'         => json_encode($this->packMeta($fields)),
        ];
        return $this->save($data, $postId);
    }

    /** Build the meta payload from editor fields (unknown keys ignored; reading_time derived). */
    public function packMeta(array $f)
    {
        $meta = self::META_DEFAULTS;
        foreach (['kicker', 'subtitle', 'preamble', 'excerpt', 'feature_media_id', 'author_id'] as $k) {
            if (isset($f[$k])) { $meta[$k] = (string) $f[$k]; }
        }
        $meta['allow_comments'] = !empty($f['allow_comments']);
        $meta['reading_time']   = $this->readingTime((string) ($f['body'] ?? ''));
        $meta['seo'] = [
            'title'       => (string) ($f['seo_title'] ?? ''),
            'description' => (string) ($f['seo_description'] ?? ''),
            'og_image_id' => (string) ($f['og_image_id'] ?? ''),
            'canonical'   => (string) ($f['canonical'] ?? ''),
        ];
        return $meta;
    }

    /** Decode a page row's meta into a full article-meta array (defaults filled in). */
    public function unpackMeta($meta)
    {
        $data = is_array($meta) ? $meta : (array) json_decode((string) $meta, true);
        $out  = self::META_DEFAULTS;
        foreach ($out as $k => $default) {
            if ($k === 'seo') {
                $out['seo'] = array_merge($out['seo'], is_array($data['seo'] ?? null) ? $data['seo'] : []);
            } elseif (array_key_exists($k, $data)) {
                $out[$k] = $data[$k];
            }
        }
        return $out;
    }

    /** Estimated read time in minutes from HTML body (~200 wpm, min 1). */
    public function readingTime($html)
    {
        $words = str_word_count(strip_tags((string) $html));
        return max(1, (int) ceil($words / 200));
    }

    /** A published article by slug (tenant over global), for the front-end. */
    public function resolveArticle($slug, $locale, $orgId = '')
    {
        return $this->resolveBySlug($slug, $locale, $orgId, self::TYPE_ARTICLE);
    }

    /**
     * Presentation data for one article row — the reusable, view-agnostic shape the default
     * views (and any theme/3rd-party surface) render. Resolves the byline author name and the
     * feature-image URL. `body` is added by the caller (rendered via Tiger_Cms_Renderer) so
     * this stays a pure metadata projection.
     *
     * NOTE: resolves author + feature per row (fine for a single post / a short list). For big
     * listings, batch-resolve upstream — left simple on purpose; a theme may not need either.
     *
     * @return array
     */
    public function present($row)
    {
        $meta = $this->unpackMeta($row->meta);

        $authorId = (string) $meta['author_id'];
        $author   = ['id' => $authorId, 'name' => ''];
        if ($authorId !== '') {
            $u = (new Tiger_Model_User())->findById($authorId);
            if ($u) { $author['name'] = $u->username ?: $u->email; }
        }

        $feature = null;
        $fid = (string) $meta['feature_media_id'];
        if ($fid !== '') {
            $mm = new Tiger_Model_Media();
            $m  = $mm->findById($fid);
            if ($m) { $a = $m->toArray(); $feature = ['id' => $fid, 'url' => $mm->url($a), 'thumb' => $mm->thumbUrl($a)]; }
        }

        return [
            'page_id'        => $row->page_id,
            'title'          => (string) $row->title,
            'slug'           => (string) $row->slug,
            'url'            => '/blog/' . $row->slug,
            'kicker'         => (string) $meta['kicker'],
            'subtitle'       => (string) $meta['subtitle'],
            'preamble'       => (string) $meta['preamble'],
            'excerpt'        => (string) ($meta['excerpt'] !== '' ? $meta['excerpt'] : $meta['subtitle']),
            'reading_time'   => (int) $meta['reading_time'],
            'published_at'   => $row->published_at,
            'author'         => $author,
            'feature'        => $feature,
            'allow_comments' => !empty($meta['allow_comments']),
            'seo'            => $meta['seo'],
        ];
    }

    /**
     * Published articles for a listing/feed, newest first. Optionally scoped to a term
     * (its page_ids) for archive pages.
     *
     * @param array $opts {locale, orgId, limit, offset, pageIds?}
     */
    public function published(array $opts = [])
    {
        $locale = (string) ($opts['locale'] ?? 'en');
        $orgId  = (string) ($opts['orgId'] ?? '');
        $limit  = max(1, (int) ($opts['limit'] ?? 20));
        $offset = max(0, (int) ($opts['offset'] ?? 0));

        $sel = $this->activeSelect()
            ->where('type = ?', self::TYPE_ARTICLE)
            ->where('locale = ?', $locale)
            ->where('org_id IN (?)', $this->_orgScope($orgId))
            ->where('status = ?', self::STATUS_PUBLISHED)
            ->where('published_at IS NULL OR published_at <= NOW()')
            ->order('published_at DESC')
            ->limit($limit, $offset);
        if (isset($opts['pageIds'])) {
            $ids = array_filter((array) $opts['pageIds']);
            if (!$ids) { return []; }
            $sel->where('page_id IN (?)', $ids);
        }
        return $this->fetchAll($sel);
    }

    /**
     * DataTables data for the article admin list (type=article only): search on
     * title/slug, status filter, sort, paginate. Query lives here; service formats.
     *
     * @return array{total:int,filtered:int,rows:array}
     */
    public function articleDatatable(array $opts)
    {
        $db     = $this->getAdapter();
        $search = (string) ($opts['search'] ?? '');
        $status = (string) ($opts['status'] ?? '');
        $limit  = max(1, (int) ($opts['limit'] ?? 25));
        $offset = max(0, (int) ($opts['offset'] ?? 0));

        $orderCols = [0 => 'title', 1 => 'status', 2 => 'published_at', 3 => 'updated_at'];
        $col = (int) ($opts['orderCol'] ?? -1);
        $dir = (strtoupper((string) ($opts['orderDir'] ?? '')) === 'ASC') ? 'ASC' : 'DESC';
        $orderSql = isset($orderCols[$col]) ? ($orderCols[$col] . ' ' . $dir) : 'updated_at DESC';

        $scope = function ($sel) use ($status) {
            $sel->where('deleted = 0')->where('type = ?', self::TYPE_ARTICLE);
            if ($status !== '') { $sel->where('status = ?', $status); }
        };
        $searchFn = function ($sel) use ($db, $search) {
            if ($search === '') { return; }
            $like  = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $parts = [];
            foreach (['title', 'slug'] as $c) { $parts[] = $db->quoteInto("$c LIKE ?", $like); }
            $sel->where('(' . implode(' OR ', $parts) . ')');
        };

        $totalSel = $db->select()->from($this->_name, ['c' => new Zend_Db_Expr('COUNT(*)')]);
        $scope($totalSel);
        $total = (int) $db->fetchOne($totalSel);

        $filteredSel = $db->select()->from($this->_name, ['c' => new Zend_Db_Expr('COUNT(*)')]);
        $scope($filteredSel); $searchFn($filteredSel);
        $filtered = (int) $db->fetchOne($filteredSel);

        $rowsSel = $db->select()
            ->from($this->_name, ['page_id', 'title', 'slug', 'locale', 'status', 'meta', 'published_at', 'updated_at', 'created_at'])
            ->order(new Zend_Db_Expr($orderSql))
            ->limit($limit, $offset);
        $scope($rowsSel); $searchFn($rowsSel);

        return ['total' => $total, 'filtered' => $filtered, 'rows' => $db->fetchAll($rowsSel)];
    }
}
