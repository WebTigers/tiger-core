<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * AuthChallenge — transient, single-use auth proofs (OTP codes, reset/verify/magic
 * tokens). See migration 0005 for the security rationale.
 *
 * The PK is a **v4** UUID (opaque) — challenge ids can appear in URLs, so they must
 * not leak timing. Codes are stored HASHED and compared with hash_equals(); reuse,
 * expiry, and brute-force are all enforced here so callers can't forget them.
 *
 * @api
 */
class Tiger_Model_AuthChallenge extends Tiger_Model_Table
{
    protected $_name    = 'auth_challenge';
    protected $_primary = 'challenge_id';

    /** Opaque ids: a challenge id must not reveal when it was minted (v4, not v7). */
    protected $_uuidVersion = 4;

    /** Lock a challenge after this many wrong attempts (brute-force guard). */
    const MAX_ATTEMPTS = 5;

    /**
     * Issue a challenge: store the HASH of the code and a TTL. The plaintext code is
     * the caller's to deliver (SMS/email) and is never persisted.
     *
     * @param  string|null $userId      may be null in pre-login flows
     * @param  string      $type        sms_otp | email_verify | password_reset | magic_link
     * @param  string      $plainCode   the code/token delivered out-of-band
     * @param  int         $ttlSeconds  lifetime (default 10 min)
     * @return string      challenge_id
     */
    public function issue($userId, $type, $plainCode, $ttlSeconds = 600)
    {
        return $this->insert([
            'user_id'    => $userId,
            'type'       => $type,
            'code_hash'  => $this->hashCode($plainCode),
            'expires_at' => date('Y-m-d H:i:s', time() + $ttlSeconds),
        ]);
    }

    /**
     * Verify and CONSUME a challenge (single-use). Returns the challenge row on
     * success, or null on any failure (missing / already used / expired / locked /
     * wrong code). A wrong code increments the attempt counter.
     *
     * @return Zend_Db_Table_Row_Abstract|null
     */
    public function redeem($challengeId, $plainCode)
    {
        $row = $this->find($challengeId)->current();
        if (!$row
            || $row->consumed_at !== null                       // already used
            || strtotime($row->expires_at) < time()             // expired
            || (int) $row->attempts >= self::MAX_ATTEMPTS) {     // locked out
            return null;
        }

        // Timing-safe comparison (current pepper, retired peppers, or legacy — rotation-safe);
        // a mismatch costs an attempt.
        if (!Tiger_Security::codeMatches($plainCode, 'challenge', (string) $row->code_hash)) {
            $this->update(
                ['attempts' => (int) $row->attempts + 1],
                $this->getAdapter()->quoteInto('challenge_id = ?', $challengeId)
            );
            return null;
        }

        $this->update(
            ['consumed_at' => $this->_now()],
            $this->getAdapter()->quoteInto('challenge_id = ?', $challengeId)
        );
        return $row;
    }

    /**
     * The newest still-usable challenge for a user+type (not consumed, not expired,
     * not attempt-locked), or null. Used to verify a code by email (no id in the URL).
     *
     * @return Zend_Db_Table_Row_Abstract|null
     */
    public function latestActive($userId, $type)
    {
        return $this->fetchRow(
            $this->activeSelect()
                ->where('user_id = ?', (string) $userId)
                ->where('type = ?', (string) $type)
                ->where('consumed_at IS NULL')
                ->where('expires_at > ?', date('Y-m-d H:i:s'))
                ->where('attempts < ?', self::MAX_ATTEMPTS)
                ->order('created_at DESC')
                ->limit(1)
        );
    }

    /**
     * Invalidate (consume) a user's outstanding challenges of a type — call before
     * issuing a fresh one so only the newest code is ever valid, which also keeps
     * latestActive() unambiguous.
     *
     * @return int rows invalidated
     */
    public function invalidateActive($userId, $type)
    {
        $db    = $this->getAdapter();
        $where = $db->quoteInto('user_id = ?', (string) $userId)
               . ' AND ' . $db->quoteInto('type = ?', (string) $type)
               . ' AND consumed_at IS NULL AND deleted = 0';
        return $this->update(['consumed_at' => $this->_now()], $where);
    }

    /** How many challenges of a type a user got in the last $seconds (send-rate guard). */
    public function countRecent($userId, $type, $seconds)
    {
        $db  = $this->getAdapter();
        $sel = $db->select()
            ->from($this->_name, ['c' => new Zend_Db_Expr('COUNT(*)')])
            ->where('user_id = ?', (string) $userId)
            ->where('type = ?', (string) $type)
            ->where('created_at >= ?', date('Y-m-d H:i:s', time() - (int) $seconds))
            ->where('deleted = 0');
        return (int) $db->fetchOne($sel);
    }

    /**
     * Delete expired/consumed challenges. Call periodically (cron / bin/tiger).
     * Hard delete — dead challenges have no audit value.
     *
     * @return int rows removed
     */
    public function purgeExpired()
    {
        return $this->delete(
            $this->getAdapter()->quoteInto('expires_at < ?', date('Y-m-d H:i:s'))
        );
    }

    /**
     * Keyed hash of the code (peppered via Tiger_Security when a pepper is configured,
     * else plain SHA-256). Peppering matters here: a 6-digit code hash is otherwise
     * trivially brute-forced from a DB leak within the code's TTL — the pepper puts it
     * out of offline reach. (Existing pre-pepper codes are transient; they just expire.)
     */
    private function hashCode($plainCode)
    {
        return Tiger_Security::hashCode($plainCode, 'challenge');
    }
}
