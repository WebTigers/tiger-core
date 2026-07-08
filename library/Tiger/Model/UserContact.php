<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * UserContact — the user ↔ contact link.
 *
 * The user-side twin of Tiger_Model_OrgContact: joins a user to an owner-agnostic
 * contact channel (Tiger_Model_Contact) and carries the relationship metadata
 * (`label`, `is_primary`) on the link row. A user has at most one link per
 * (user, contact) pair. Contact-as-DATA, not the `sms` auth factor.
 *
 * @api
 */
class Tiger_Model_UserContact extends Tiger_Model_Table
{
    protected $_name    = 'user_contact';
    protected $_primary = 'user_contact_id';

    /**
     * All contact links for a user.
     *
     * @param  string $userId
     * @return Zend_Db_Table_Rowset_Abstract
     */
    public function findByUser($userId)
    {
        return $this->fetchAll($this->activeSelect()->where('user_id = ?', $userId));
    }
}
