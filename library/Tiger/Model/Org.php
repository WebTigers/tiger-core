<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Org — the TENANT.
 *
 * An Org is the unit of tenancy in Tiger. Everything a customer owns hangs off an
 * org, and cross-tenant isolation is enforced by the org_user membership table
 * (see Tiger_Model_OrgUser), NOT by anything on this row.
 *
 * Orgs form a hierarchy via the self-referential `parent_org_id` (null = a root
 * org). This supports parent/child structures — e.g. an enterprise with
 * departments, or a reseller with sub-accounts — without a separate table. Keep
 * the Org row THIN: it's identity + hierarchy + status only. Anything richer
 * (billing profile, branding, settings) belongs to a MODULE that extends Org via
 * its own FK-linked table, or to the org-scoped config layer — never new columns
 * here, so the platform stays updatable.
 *
 * @api
 */
class Tiger_Model_Org extends Tiger_Model_Table
{
    protected $_name    = 'org';
    protected $_primary = 'org_id';

    /** @var string|null the current-site org id, resolved once per request */
    private static $_siteOrgId = null;

    /**
     * The current site's org — the tenant that owns the PUBLIC site being served. Public read dispatch
     * (CMS pages, blog articles) scopes to this; content writes are stamped from the authenticated org
     * (Tiger_Model_Table::setOrg). Resolution: the configured `tiger.site.org_id` (recorded at install /
     * settable in Site settings), else the **founding org** (the oldest) as a heuristic. NB: org hierarchy
     * (`parent_org_id`) is future — right now every org is a root, so "root" isn't a distinguishing query;
     * the site org is the platform's founding org. A multi-site module overrides this per request from the
     * request host (setSiteOrgId). Cached per request.
     *
     * @return string the site org id, or '' if no org exists yet (a pre-install install)
     */
    public static function siteOrgId()
    {
        if (self::$_siteOrgId !== null) {
            return self::$_siteOrgId;
        }
        $cfg = Zend_Registry::isRegistered('Zend_Config') ? Zend_Registry::get('Zend_Config') : null;
        $t   = $cfg ? $cfg->get('tiger') : null;
        $s   = $t ? $t->get('site') : null;
        $id  = ($s && $s->get('org_id')) ? (string) $s->org_id : '';
        if ($id === '') {
            try {
                $m   = new self();
                $row = $m->fetchRow($m->activeSelect()->order('created_at ASC')->limit(1));   // the founding org
                $id  = $row ? (string) $row->org_id : '';
            } catch (Throwable $e) {
                $id = '';
            }
        }
        return self::$_siteOrgId = $id;
    }

    /**
     * Override the current-site org (a multi-site module resolves this from the request host, early).
     *
     * @param  string $orgId
     * @return void
     */
    public static function setSiteOrgId($orgId)
    {
        self::$_siteOrgId = (string) $orgId;
    }

    /**
     * Find an org by its URL-safe slug (the human/route-facing identifier).
     *
     * @param  string $slug
     * @return Zend_Db_Table_Row_Abstract|null
     */
    public function findBySlug($slug)
    {
        return $this->fetchRow($this->activeSelect()->where('slug = ?', $slug)) ?: null;
    }

    /**
     * Direct children of an org (one level down the hierarchy).
     *
     * @param  string $orgId
     * @return Zend_Db_Table_Rowset_Abstract
     */
    public function children($orgId)
    {
        return $this->fetchAll($this->activeSelect()->where('parent_org_id = ?', $orgId));
    }

    /**
     * DataTables data for the Organizations admin: org + parent name + a live member
     * count (via org_user). Owns the query; the service handles presentation + ACL.
     *
     * @param array{search?:string,status?:string,orderCol?:int,orderDir?:string,offset?:int,limit?:int} $opts
     * @return array{total:int,filtered:int,rows:array}
     */
    public function datatable(array $opts)
    {
        $db     = $this->getAdapter();
        $search = (string) ($opts['search'] ?? '');
        $status = (string) ($opts['status'] ?? '');
        $limit  = max(1, (int) ($opts['limit'] ?? 25));
        $offset = max(0, (int) ($opts['offset'] ?? 0));

        $orderCols = [0 => 'o.name', 1 => 'o.slug', 2 => 'parent_name', 3 => 'member_count', 4 => 'o.status', 5 => 'o.created_at'];
        $col = (int) ($opts['orderCol'] ?? -1);
        $dir = (strtoupper((string) ($opts['orderDir'] ?? '')) === 'ASC') ? 'ASC' : 'DESC';
        $orderSql = isset($orderCols[$col]) ? ($orderCols[$col] . ' ' . $dir) : 'o.name ASC';

        $scope = function ($sel) use ($status) {
            $sel->where('o.deleted = 0');
            if ($status !== '') { $sel->where('o.status = ?', $status); }
        };
        $searchFn = function ($sel) use ($db, $search) {
            if ($search === '') { return; }
            $like  = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $parts = [];
            foreach (['o.name', 'o.slug'] as $c) { $parts[] = $db->quoteInto("$c LIKE ?", $like); }
            $sel->where('(' . implode(' OR ', $parts) . ')');
        };

        $totalSel = $db->select()->from(['o' => $this->_name], ['c' => new Zend_Db_Expr('COUNT(*)')]);
        $scope($totalSel);
        $total = (int) $db->fetchOne($totalSel);

        $filteredSel = $db->select()->from(['o' => $this->_name], ['c' => new Zend_Db_Expr('COUNT(*)')]);
        $scope($filteredSel); $searchFn($filteredSel);
        $filtered = (int) $db->fetchOne($filteredSel);

        $pageSel = $db->select()
            ->from(['o' => $this->_name], ['org_id', 'name', 'slug', 'status', 'parent_org_id', 'created_at'])
            ->joinLeft(['p' => $this->_name], 'p.org_id = o.parent_org_id', ['parent_name' => 'p.name'])
            ->joinLeft(['ou' => 'org_user'], 'ou.org_id = o.org_id AND ou.deleted = 0', ['member_count' => new Zend_Db_Expr('COUNT(DISTINCT ou.user_id)')])
            ->group('o.org_id')
            ->order(new Zend_Db_Expr($orderSql))
            ->limit($limit, $offset);
        $scope($pageSel); $searchFn($pageSel);

        return ['total' => $total, 'filtered' => $filtered, 'rows' => $db->fetchAll($pageSel)];
    }

    /**
     * Is $slug already used by a different, non-deleted org?
     *
     * @param string      $slug      the slug to check
     * @param string|null $excludeId an org id to exclude (e.g. the org being edited)
     * @return bool true if the slug is taken by another live org
     */
    public function slugTaken($slug, $excludeId = null)
    {
        $sel = $this->activeSelect()->where('slug = ?', (string) $slug);
        if ($excludeId) { $sel->where('org_id != ?', (string) $excludeId); }
        return (bool) $this->fetchRow($sel);
    }
}
