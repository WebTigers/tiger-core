<?php
/**
 * UserCredential — durable authentication factors (1-to-many with user).
 *
 * Each row is one way a user can prove who they are: a password, a verified SMS
 * number, a TOTP secret, a passkey, an SSO link. See migration 0004 for the schema
 * and the per-type `secret` semantics.
 *
 * This model owns the factor mechanics (hashing, verification, touch/verify state).
 * Higher-level orchestration (the login flow, session issuance) belongs in an auth
 * SERVICE, not here — the model stays a data gateway with factor-aware helpers.
 *
 * @api
 */
class Tiger_Model_UserCredential extends Tiger_Model_Table
{
    protected $_name    = 'user_credential';
    protected $_primary = 'credential_id';

    const TYPE_PASSWORD = 'password';
    const TYPE_SMS      = 'sms';
    const TYPE_TOTP     = 'totp';
    const TYPE_WEBAUTHN = 'webauthn';
    const TYPE_OAUTH    = 'oauth';

    /** Login lockout: lock the password credential after this many consecutive failures. */
    const MAX_FAILURES = 5;
    /** How long a lockout lasts, in minutes. */
    const LOCK_MINUTES = 15;

    /** How many retired password hashes to retain (bounds password_history; policy checks <= this). */
    const HISTORY_KEEP = 24;

    /**
     * Hash a plaintext password. PASSWORD_DEFAULT = bcrypt today, upgraded by PHP
     * over time. A password hash is one-way, so it's stored as-is (not encrypted).
     */
    public static function hashPassword($plain)
    {
        return password_hash($plain, PASSWORD_DEFAULT);
    }

    /**
     * Set (create or replace) the user's single password credential. Idempotent —
     * one password row per user (enforced by the UNIQUE key with identifier='').
     *
     * @return string credential_id
     */
    public function setPassword($userId, $plain)
    {
        $hash     = self::hashPassword($plain);
        $existing = $this->factor($userId, self::TYPE_PASSWORD);

        if ($existing) {
            // Archive the outgoing hash for reuse-prevention (Tiger_Policy_Password),
            // then keep the history bounded.
            if ($existing->secret !== null) {
                $history = new Tiger_Model_PasswordHistory();
                $history->archive($userId, $existing->secret);
                $history->prune($userId, self::HISTORY_KEEP);
            }
            $this->update(
                array('secret' => $hash, 'verified_at' => $this->_now()),
                $this->getAdapter()->quoteInto('credential_id = ?', $existing->credential_id)
            );
            return $existing->credential_id;
        }
        return $this->insert(array(
            'user_id'     => $userId,
            'type'        => self::TYPE_PASSWORD,
            'secret'      => $hash,
            'verified_at' => $this->_now(),
        ));
    }

    /**
     * Verify a plaintext password against the user's stored hash. Records last_used_at
     * on success. Returns false if there's no password credential.
     *
     * @return bool
     */
    public function verifyPassword($userId, $plain)
    {
        $row = $this->factor($userId, self::TYPE_PASSWORD);
        if (!$row || $row->secret === null) {
            return false;
        }
        if (password_verify($plain, $row->secret)) {
            $this->touch($row->credential_id);
            return true;
        }
        return false;
    }

    /**
     * Add an (unverified) SMS factor — a phone number for OTP/2FA. verified_at stays
     * NULL until confirmed via an auth_challenge round-trip (then markVerified()).
     *
     * @param string $userId
     * @param string $e164   phone in E.164 form, e.g. +15551234567
     * @return string credential_id
     */
    public function addSms($userId, $e164)
    {
        return $this->insert(array(
            'user_id'    => $userId,
            'type'       => self::TYPE_SMS,
            'identifier' => $e164,
        ));
    }

    /**
     * A specific factor for a user. Singleton types (password/totp) use the default
     * identifier ''; sms/oauth pass the phone/subject.
     *
     * @return Zend_Db_Table_Row_Abstract|null
     */
    public function factor($userId, $type, $identifier = '')
    {
        return $this->fetchRow(
            $this->activeSelect()
                ->where('user_id = ?', $userId)
                ->where('type = ?', $type)
                ->where('identifier = ?', $identifier)
        ) ?: null;
    }

    /** All (non-deleted) factors a user holds — e.g. for a "security settings" screen. */
    public function factorsFor($userId)
    {
        return $this->fetchAll($this->activeSelect()->where('user_id = ?', $userId));
    }

    /**
     * Reverse lookup: which credential owns this identifier for a type? Powers
     * "log in by phone" (sms) and SSO callback resolution (oauth). Only matches
     * VERIFIED factors — an unconfirmed phone can't be used to authenticate.
     *
     * @return Zend_Db_Table_Row_Abstract|null
     */
    public function findVerifiedByIdentifier($type, $identifier)
    {
        return $this->fetchRow(
            $this->activeSelect()
                ->where('type = ?', $type)
                ->where('identifier = ?', $identifier)
                ->where('verified_at IS NOT NULL')
        ) ?: null;
    }

    /** Mark a factor confirmed (e.g. after a successful SMS OTP). */
    public function markVerified($credentialId)
    {
        return $this->update(
            array('verified_at' => $this->_now()),
            $this->getAdapter()->quoteInto('credential_id = ?', $credentialId)
        );
    }

    /** Record that a factor was just used to authenticate. */
    public function touch($credentialId)
    {
        return $this->update(
            array('last_used_at' => $this->_now()),
            $this->getAdapter()->quoteInto('credential_id = ?', $credentialId)
        );
    }

    // ----- login lockout (brute-force guard) ---------------------------------

    /** The user's password credential row, or null. */
    public function passwordCredential($userId)
    {
        return $this->factor($userId, self::TYPE_PASSWORD);
    }

    /** Is this credential currently locked out (too many recent failures)? */
    public function isLockedOut($credential)
    {
        return $credential
            && $credential->locked_until !== null
            && strtotime($credential->locked_until) > time();
    }

    /** Record a failed authentication; lock the credential after MAX_FAILURES. */
    public function recordFailure($credentialId)
    {
        $cred = $this->findById($credentialId);
        if (!$cred) {
            return;
        }
        $count = (int) $cred->failed_count + 1;
        $data  = array('failed_count' => $count);
        if ($count >= self::MAX_FAILURES) {
            $data['locked_until'] = date('Y-m-d H:i:s', time() + self::LOCK_MINUTES * 60);
        }
        $this->update($data, $this->getAdapter()->quoteInto('credential_id = ?', $credentialId));
    }

    /** Record a successful authentication: clear the failure counter + lockout, touch. */
    public function recordSuccess($credentialId)
    {
        $this->update(
            array('failed_count' => 0, 'locked_until' => null, 'last_used_at' => $this->_now()),
            $this->getAdapter()->quoteInto('credential_id = ?', $credentialId)
        );
    }
}
