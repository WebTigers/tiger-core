<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * OrgContact — the org ↔ contact link.
 *
 * Joins an org to an owner-agnostic contact channel (Tiger_Model_Contact) and carries
 * the relationship metadata (`label`, `is_primary`) on the link row, so one shared
 * contact can mean different things to different orgs. A user has at most one link per
 * (org, contact) pair.
 *
 * @api
 */
class Tiger_Model_OrgContact extends Tiger_Model_Table
{
    protected $_name    = 'org_contact';
    protected $_primary = 'org_contact_id';

    /**
     * All contact links for an org.
     *
     * @param  string $orgId
     * @return Zend_Db_Table_Rowset_Abstract
     */
    public function findByOrg($orgId)
    {
        return $this->fetchAll($this->activeSelect()->where('org_id = ?', $orgId));
    }
}
