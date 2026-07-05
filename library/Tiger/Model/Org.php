<?php
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
}
