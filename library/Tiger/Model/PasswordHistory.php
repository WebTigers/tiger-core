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

    /**
     * Archive a retired password hash for a user.
     *
     * @param string $userId the user id
     * @param string $hash   the retired password hash to archive
     * @return string the password_history_id
     */
    public function archive($userId, $hash)
    {
        return $this->insert(['user_id' => $userId, 'secret' => $hash]);
    }

    /**
     * The user's last N retired hashes, newest first.
     *
     * @param string $userId the user id
     * @param int    $limit  the maximum number of hashes to return
     * @return Zend_Db_Table_Rowset_Abstract the retired hashes, newest first
     */
    public function recentForUser($userId, $limit = 5)
    {
        // The limit MUST live on the Select: Zend_Db_Table_Abstract::fetchAll() ignores its
        // $count/$offset args when the first arg is already a Select (it only honors them when
        // building the Select itself). Passing `null, $limit` alongside a Select was a silent no-op.
        return $this->fetchAll(
            $this->select()->where('user_id = ?', $userId)->order('created_at DESC')->limit((int) $limit)
        );
    }

    /**
     * Keep only the newest $keep rows for a user (bounded history).
     *
     * @param string $userId the user id
     * @param int    $keep   the number of newest rows to retain
     * @return void
     */
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
