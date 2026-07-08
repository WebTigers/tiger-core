<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * PasswordHistory — retained old password hashes (see migration 0012).
 *
 * Written by Tiger_Model_UserCredential::setPassword (archives the old hash before
 * overwriting); read by Tiger_Policy_Password for reuse-prevention.
 *
 * @api
 */
class Tiger_Model_PasswordHistory extends Tiger_Model_Table
{
    protected $_name    = 'password_history';
    protected $_primary = 'password_history_id';

    /** Archive a retired password hash for a user. */
    public function archive($userId, $hash)
    {
        return $this->insert(['user_id' => $userId, 'secret' => $hash]);
    }

    /** The user's last N retired hashes, newest first. */
    public function recentForUser($userId, $limit = 5)
    {
        return $this->fetchAll(
            $this->select()->where('user_id = ?', $userId)->order('created_at DESC'),
            null, (int) $limit
        );
    }

    /** Keep only the newest $keep rows for a user (bounded history). */
    public function prune($userId, $keep)
    {
        $keep = max(0, (int) $keep);
        $ids  = $this->getAdapter()->fetchCol(
            $this->select()->from($this->_name, ['password_history_id'])
                ->where('user_id = ?', $userId)
                ->order('created_at DESC')
                ->limit(1000, $keep)   // everything past the first $keep
        );
        if ($ids) {
            $this->delete($this->getAdapter()->quoteInto('password_history_id IN (?)', $ids));
        }
    }
}
