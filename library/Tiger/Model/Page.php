<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Page — the CMS content store (see migration 0014).
 *
 * One model for all three rendering primitives (`type`): page (routed by slug),
 * layout (chrome), partial (fragment). Resolution honors the tenant cascade
 * (org row wins over global '') and, for pages, the publish + schedule gate.
 *
 * Rendering itself (turning `body` into HTML via the format + a view context) is
 * the CMS service's job, on top of the non-file Zend_View enhancement — this model
 * is the data gateway.
 *
 * @api
 */
class Tiger_Model_Page extends Tiger_Model_Table
{
    protected $_name    = 'page';
    protected $_primary = 'page_id';

    const TYPE_PAGE    = 'page';
    const TYPE_LAYOUT  = 'layout';
    const TYPE_PARTIAL = 'partial';

    const STATUS_DRAFT     = 'draft';
    const STATUS_PUBLISHED = 'published';
    const STATUS_ARCHIVED  = 'archived';

    const FORMAT_HTML     = 'html';      // safe
    const FORMAT_MARKDOWN = 'markdown';  // safe
    const FORMAT_PHTML    = 'phtml';     // code — trusted authors only
    const FORMAT_BUILDER  = 'builder';   // safe — GrapesJS visual builder (self-contained HTML+CSS); <script> stripped on save

    /**
     * Resolve a live page by slug for an org. Walks org_id IN (current, '') and the
     * TENANT row wins over global; only published rows whose schedule has arrived.
     * Returns the row or null.
     *
     * @param string      $slug   the page slug
     * @param string      $locale the locale to match
     * @param string      $orgId  tenant scope ('' = global)
     * @param string|null $type   restrict to a TYPE_* constant, or null for any
     * @return Zend_Db_Table_Row_Abstract|null the live page row, or null
     */
    public function resolveBySlug($slug, $locale, $orgId = '', $type = null)
    {
        $select = $this->activeSelect()
            ->where('slug = ?', (string) $slug)
            ->where('locale = ?', (string) $locale)
            ->where('org_id IN (?)', $this->_orgScope($orgId))
            ->where('status = ?', self::STATUS_PUBLISHED)
            ->where('published_at IS NULL OR published_at <= NOW()')
            ->order('org_id DESC')   // non-empty (tenant) sorts before '' (global)
            ->limit(1);
        // Root dispatch resolves only real pages; posts/articles are routed under /blog by
        // their module, so they don't answer at the site root even though they share the slug
        // space. Callers that want a specific kind pass it (e.g. Blog_Model_Post::resolveArticle).
        if ($type !== null) {
            $select->where('type = ?', (string) $type);
        }
        return $this->fetchRow($select);
    }

    /**
     * Full-text search of live content (the seam behind the CMS/blog Tiger_Search providers).
     *
     * Reproduces resolveBySlug's exact visibility gate — type + locale + org cascade + published +
     * schedule — so it can never surface a draft, a scheduled-but-not-live page, or another tenant's
     * content. Uses the `ft_page(title, body)` FULLTEXT index for relevance; falls back to LIKE when
     * FULLTEXT returns nothing (short terms below `innodb_ft_min_token_size`, which can't be tuned on
     * shared hosting, otherwise silently return zero).
     *
     * @param  string $term   the query
     * @param  string $locale the locale to match
     * @param  string $orgId  tenant scope ('' = global)
     * @param  int    $limit  max rows (clamped 1..50)
     * @param  string $type   the page type to search (default TYPE_PAGE; e.g. 'article' for the blog)
     * @return array          matching rows with page_id, slug, title, body, type, org_id, score
     */
    public function search($term, $locale, $orgId = '', $limit = 20, $type = self::TYPE_PAGE)
    {
        $term = trim((string) $term);
        if ($term === '') { return []; }
        $db    = $this->getAdapter();
        $scope = $this->_orgScope($orgId);
        $limit = max(1, min(50, (int) $limit));

        $gate = function (Zend_Db_Select $select) use ($type, $locale, $scope) {
            return $select
                ->where('deleted = ?', 0)
                ->where('type = ?', (string) $type)
                ->where('locale = ?', (string) $locale)
                ->where('org_id IN (?)', $scope)
                ->where('status = ?', self::STATUS_PUBLISHED)
                ->where('published_at IS NULL OR published_at <= NOW()');
        };
        $cols = ['page_id', 'slug', 'title', 'body', 'type', 'org_id'];

        // 1) FULLTEXT (NATURAL LANGUAGE MODE ignores operators, so the quoted term is injection-safe).
        $match = 'MATCH(`title`,`body`) AGAINST (' . $db->quote($term) . ' IN NATURAL LANGUAGE MODE)';
        $ft = $db->select()->from($this->_name, $cols + ['score' => new Zend_Db_Expr($match)]);
        $gate($ft)->where($match)->order('score DESC')->order('org_id DESC')->limit($limit);
        $rows = $db->fetchAll($ft);
        if ($rows) { return $rows; }

        // 2) LIKE fallback — escape LIKE wildcards in the term.
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term) . '%';
        $lk = $db->select()->from($this->_name, $cols + ['score' => new Zend_Db_Expr('0')]);
        $gate($lk)
            ->where($db->quoteInto('title LIKE ?', $like) . ' OR ' . $db->quoteInto('body LIKE ?', $like))
            ->order('org_id DESC')->limit($limit);
        return $db->fetchAll($lk);
    }

    /**
     * Fetch by stable handle (layouts, partials, or a page by key). Not publish-
     * gated — layouts/partials are infrastructure, fetched regardless of status.
     * Tenant row wins over global.
     *
     * @param string      $key    the stable page_key handle
     * @param string      $locale the locale to match
     * @param string      $orgId  tenant scope ('' = global)
     * @param string|null $type   restrict to a TYPE_* constant, or null for any
     * @return Zend_Db_Table_Row_Abstract|null the row, or null
     */
    public function fetchByKey($key, $locale, $orgId = '', $type = null)
    {
        $select = $this->activeSelect()
            ->where('page_key = ?', (string) $key)
            ->where('locale = ?', (string) $locale)
            ->where('org_id IN (?)', $this->_orgScope($orgId))
            ->order('org_id DESC')
            ->limit(1);
        if ($type !== null) {
            $select->where('type = ?', (string) $type);
        }
        return $this->fetchRow($select);
    }

    /**
     * All published pages under a parent, ordered — for nav/menus.
     *
     * @param string $parentId the parent page id
     * @param string $locale   the locale to match
     * @param string $orgId    tenant scope ('' = global)
     * @return Zend_Db_Table_Rowset_Abstract the ordered child pages
     */
    public function children($parentId, $locale, $orgId = '')
    {
        return $this->fetchAll(
            $this->activeSelect()
                ->where('parent_id = ?', (string) $parentId)
                ->where('locale = ?', (string) $locale)
                ->where('org_id IN (?)', $this->_orgScope($orgId))
                ->where('type = ?', self::TYPE_PAGE)
                ->where('status = ?', self::STATUS_PUBLISHED)
                ->order(['sort_order ASC', 'title ASC'])
        );
    }

    /**
     * Save a page (insert or update) AND snapshot the result to page_version. When an
     * existing page's slug changes, a page_redirect (old -> new) is recorded so the old
     * URL 301s. Wrapped in a transaction. Returns the page_id.
     *
     * @param array       $data   page columns to write
     * @param string|null $pageId update this page, or null to insert a new one
     * @return string the saved page_id
     * @throws Throwable on a DB failure (the transaction is rolled back and rethrown)
     */
    public function save(array $data, $pageId = null)
    {
        $db = $this->getAdapter();
        $db->beginTransaction();
        try {
            if ($pageId) {
                $current = $this->findById($pageId);
                // Slug change on an existing page -> leave a 301 behind (and clear any
                // stale redirect FROM the new slug so reclaiming a slug can't loop).
                if ($current && !empty($data['slug']) && $data['slug'] !== $current->slug && !empty($current->slug)) {
                    $redirect = new Tiger_Model_PageRedirect();
                    $redirect->clearFrom($data['slug'], $current->locale, $current->org_id);
                    $redirect->add($current->slug, $data['slug'], $current->locale, $current->org_id);
                }
                unset($data['page_id']);
                $this->update($data, $db->quoteInto('page_id = ?', $pageId));
            } else {
                $pageId = $this->insert($data);
            }

            $row = $this->findById($pageId);
            (new Tiger_Model_PageVersion())->snapshot($pageId, [
                'title'  => $row->title,
                'body'   => $row->body,
                'format' => $row->format,
                'meta'   => $row->meta,
                'status' => $row->status,
            ]);

            $db->commit();
            return $pageId;
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Restore a page to a prior version — copies that version's content back onto the
     * page (which snapshots as a new version). Returns the page_id. (Named
     * restoreVersion to avoid Tiger_Model_Table::restore(), the soft-delete undelete.)
     *
     * @param string $pageId  the page id to restore
     * @param int    $version the version number to restore
     * @return string the saved page_id
     * @throws RuntimeException if the version doesn't exist for the page
     */
    public function restoreVersion($pageId, $version)
    {
        $ver = (new Tiger_Model_PageVersion())->get($pageId, $version);
        if (!$ver) {
            throw new RuntimeException("page_version {$version} not found for page {$pageId}.");
        }
        return $this->save([
            'title'  => $ver->title,
            'body'   => $ver->body,
            'format' => $ver->format,
            'meta'   => $ver->meta,
            'status' => $ver->status,
        ], $pageId);
    }

    /**
     * DataTables data for the CMS content admin: pages/layouts/partials with search,
     * status/type filters, sort, and paging. Owns the query; the service handles
     * presentation + ACL. Returns total (scoped), filtered (scoped + search), and rows.
     *
     * @param array{search?:string,status?:string,type?:string,orderCol?:int,orderDir?:string,offset?:int,limit?:int} $opts
     * @return array{total:int,filtered:int,rows:array}
     */
    public function datatable(array $opts)
    {
        $db     = $this->getAdapter();
        $search = (string) ($opts['search'] ?? '');
        $status = (string) ($opts['status'] ?? '');
        $type   = (string) ($opts['type'] ?? '');
        $limit  = max(1, (int) ($opts['limit'] ?? 25));
        $offset = max(0, (int) ($opts['offset'] ?? 0));

        // Sortable columns (index -> SQL); Slug/Key sorts on whichever is set.
        $orderCols = [0 => 'title', 1 => 'type', 2 => 'COALESCE(slug, page_key)', 3 => 'locale', 4 => 'status', 5 => 'updated_at'];
        $col = (int) ($opts['orderCol'] ?? -1);
        $dir = (strtoupper((string) ($opts['orderDir'] ?? '')) === 'ASC') ? 'ASC' : 'DESC';
        $orderSql = isset($orderCols[$col]) ? ($orderCols[$col] . ' ' . $dir) : 'updated_at DESC';

        $scope = function ($sel) use ($status, $type) {
            $sel->where('deleted = 0');
            if ($status !== '') { $sel->where('status = ?', $status); }
            if ($type   !== '') { $sel->where('type = ?', $type); }
        };
        $searchFn = function ($sel) use ($db, $search) {
            if ($search === '') { return; }
            $like  = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $parts = [];
            foreach (['title', 'slug', 'page_key', 'type', 'locale', 'status'] as $c) { $parts[] = $db->quoteInto("$c LIKE ?", $like); }
            $sel->where('(' . implode(' OR ', $parts) . ')');
        };

        $totalSel = $db->select()->from($this->_name, ['c' => new Zend_Db_Expr('COUNT(*)')]);
        $scope($totalSel);
        $total = (int) $db->fetchOne($totalSel);

        $filteredSel = $db->select()->from($this->_name, ['c' => new Zend_Db_Expr('COUNT(*)')]);
        $scope($filteredSel); $searchFn($filteredSel);
        $filtered = (int) $db->fetchOne($filteredSel);

        $pageSel = $db->select()
            ->from($this->_name, ['page_id', 'title', 'type', 'slug', 'page_key', 'locale', 'status', 'published_at', 'updated_at', 'created_at'])
            ->order(new Zend_Db_Expr($orderSql))
            ->limit($limit, $offset);
        $scope($pageSel); $searchFn($pageSel);

        return ['total' => $total, 'filtered' => $filtered, 'rows' => $db->fetchAll($pageSel)];
    }

    /**
     * The org scope for a cascade lookup: [<org>, ''] (deduped; '' = shared-across-sites fallback, which
     * loses to a real org via `ORDER BY org_id DESC`). A blank org means "the current site" — it resolves
     * to Tiger_Model_Org::siteOrgId() so a public read (which passes '') scopes to the site's own content
     * now that content carries a real org_id, not just the shared '' rows.
     */
    protected function _orgScope($orgId)
    {
        $orgId = (string) $orgId;
        if ($orgId === '') {
            $orgId = Tiger_Model_Org::siteOrgId();
        }
        return array_values(array_unique([$orgId, '']));
    }
}
