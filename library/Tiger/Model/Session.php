<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Session — gateway for the DB session store (see migration 0010).
 *
 * A system table, so this extends Zend_Db_Table_Abstract directly (NOT
 * Tiger_Model_Table — session_id is the PHP session id, not a UUID, and there are
 * no soft-delete/actor columns). Used by the save handler's GC and by admin
 * "your sessions" / force-logout flows.
 *
 * @api
 */
class Tiger_Model_Session extends Zend_Db_Table_Abstract
{
    protected $_name    = 'session';
    protected $_primary = 'session_id';

    /** Delete sessions whose (now - modified) > lifetime. Returns rows removed. */
    public function gc()
    {
        return (int) $this->delete(
            $this->getAdapter()->quoteInto('(? - modified) > lifetime', time())
        );
    }

    /** A user's sessions, newest first (for a "signed-in devices" view). */
    public function getByUserId($userId)
    {
        return $this->fetchAll($this->select()->where('user_id = ?', $userId)->order('modified DESC'));
    }

    /** Force-logout: delete all of a user's sessions. */
    public function deleteByUserId($userId)
    {
        return (int) $this->delete($this->getAdapter()->quoteInto('user_id = ?', $userId));
    }
}
