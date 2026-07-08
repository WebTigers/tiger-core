<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * UserAddress — the user ↔ address link.
 *
 * The user-side twin of Tiger_Model_OrgAddress: joins a user to an owner-agnostic
 * address (Tiger_Model_Address) and carries the relationship metadata (`label`,
 * `is_primary`) on the link row. A user has at most one link per (user, address) pair.
 *
 * @api
 */
class Tiger_Model_UserAddress extends Tiger_Model_Table
{
    protected $_name    = 'user_address';
    protected $_primary = 'user_address_id';

    /**
     * All address links for a user.
     *
     * @param  string $userId
     * @return Zend_Db_Table_Rowset_Abstract
     */
    public function findByUser($userId)
    {
        return $this->fetchAll($this->activeSelect()->where('user_id = ?', $userId));
    }
}
