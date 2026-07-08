<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * OrgAddress — the org ↔ address link.
 *
 * Joins an org to an owner-agnostic address (Tiger_Model_Address) and carries the
 * relationship metadata (`label`, `is_primary`) on the link row, so one shared address
 * can mean different things to different orgs. A user has at most one link per
 * (org, address) pair.
 *
 * @api
 */
class Tiger_Model_OrgAddress extends Tiger_Model_Table
{
    protected $_name    = 'org_address';
    protected $_primary = 'org_address_id';

    /**
     * All address links for an org.
     *
     * @param  string $orgId
     * @return Zend_Db_Table_Rowset_Abstract
     */
    public function findByOrg($orgId)
    {
        return $this->fetchAll($this->activeSelect()->where('org_id = ?', $orgId));
    }
}
