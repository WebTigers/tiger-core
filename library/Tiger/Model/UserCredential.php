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
    /** Single-use backup codes for TOTP recovery — one row per code, hashed secret. */
    const TYPE_RECOVERY = 'recovery';

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
                ['secret' => $hash, 'verified_at' => $this->_now()],
                $this->getAdapter()->quoteInto('credential_id = ?', $existing->credential_id)
            );
            return $existing->credential_id;
        }
        return $this->insert([
            'user_id'     => $userId,
            'type'        => self::TYPE_PASSWORD,
            'secret'      => $hash,
            'verified_at' => $this->_now(),
        ]);
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
        return $this->insert([
            'user_id'    => $userId,
            'type'       => self::TYPE_SMS,
            'identifier' => $e164,
        ]);
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
            ['verified_at' => $this->_now()],
            $this->getAdapter()->quoteInto('credential_id = ?', $credentialId)
        );
    }

    /** Record that a factor was just used to authenticate. */
    public function touch($credentialId)
    {
        return $this->update(
            ['last_used_at' => $this->_now()],
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
        $data  = ['failed_count' => $count];
        if ($count >= self::MAX_FAILURES) {
            $data['locked_until'] = date('Y-m-d H:i:s', time() + self::LOCK_MINUTES * 60);
        }
        $this->update($data, $this->getAdapter()->quoteInto('credential_id = ?', $credentialId));
    }

    /** Record a successful authentication: clear the failure counter + lockout, touch. */
    public function recordSuccess($credentialId)
    {
        $this->update(
            ['failed_count' => 0, 'locked_until' => null, 'last_used_at' => $this->_now()],
            $this->getAdapter()->quoteInto('credential_id = ?', $credentialId)
        );
    }

    // ----- TOTP (authenticator app) 2FA --------------------------------------
    //
    // The TOTP secret is REVERSIBLE (needed to compute the expected code), so it's
    // stored encrypted (Tiger_Crypto), not hashed. Orchestration — enrollment, the
    // login step-up — lives in Tiger_Service_Authentication; this model just persists
    // and retrieves the factor rows. Lifecycle writes HARD-delete because the UNIQUE
    // (user_id, type, identifier) key spans soft-deleted rows: a soft-deleted totp row
    // would collide with a fresh enrollment. Auth factors carry no audit value that the
    // login log doesn't already hold, so a hard delete is the right call here.

    /** The user's active, confirmed TOTP factor row, or null. */
    public function activeTotp($userId)
    {
        return $this->fetchRow(
            $this->activeSelect()
                ->where('user_id = ?', $userId)
                ->where('type = ?', self::TYPE_TOTP)
                ->where('identifier = ?', '')
                ->where('status = ?', 'active')
                ->where('verified_at IS NOT NULL')
        ) ?: null;
    }

    /** Does the user have a confirmed authenticator-app factor? */
    public function hasActiveTotp($userId)
    {
        return $this->activeTotp($userId) !== null;
    }

    /**
     * Enable (or re-enroll) TOTP: replace any prior TOTP + recovery rows with a fresh
     * confirmed secret and a new set of hashed backup codes, atomically-ish (one owner
     * of these rows). Call only AFTER the enrollment code has been verified.
     *
     * @param string   $userId
     * @param string   $encryptedSecret Tiger_Crypto::encrypt() of the base32 secret
     * @param string[] $recoveryHashes  sha256 hashes of the plaintext backup codes
     */
    public function replaceTotp($userId, $encryptedSecret, array $recoveryHashes)
    {
        $this->_purgeTotp($userId);
        $this->insert([
            'user_id'     => $userId,
            'type'        => self::TYPE_TOTP,
            'identifier'  => '',
            'secret'      => $encryptedSecret,
            'verified_at' => $this->_now(),
            'status'      => 'active',
        ]);
        $i = 0;
        foreach ($recoveryHashes as $hash) {
            $this->insert([
                'user_id'     => $userId,
                'type'        => self::TYPE_RECOVERY,
                'identifier'  => sprintf('%02d', ++$i),   // distinct sub-key per code (UNIQUE)
                'secret'      => $hash,
                'verified_at' => $this->_now(),
                'status'      => 'active',
            ]);
        }
    }

    /** Disable TOTP entirely: drop the secret and all remaining backup codes. */
    public function removeTotp($userId)
    {
        $this->_purgeTotp($userId);
    }

    /**
     * Redeem a single-use recovery code (constant-time match against remaining codes).
     * On success the code is consumed (deleted) so it can't be reused. Returns true iff
     * a code matched.
     */
    public function redeemRecoveryCode($userId, $plainCode)
    {
        $norm = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $plainCode));
        if ($norm === '') {
            return false;
        }
        $hash = hash('sha256', $norm);
        $rows = $this->fetchAll(
            $this->activeSelect()->where('user_id = ?', $userId)->where('type = ?', self::TYPE_RECOVERY)
        );
        foreach ($rows as $row) {
            if (hash_equals((string) $row->secret, $hash)) {
                $db = $this->getAdapter();
                $db->delete($this->_name, $db->quoteInto('credential_id = ?', $row->credential_id));
                return true;
            }
        }
        return false;
    }

    /** How many unused recovery codes the user has left (for the security screen). */
    public function recoveryCount($userId)
    {
        return count($this->fetchAll(
            $this->activeSelect()->where('user_id = ?', $userId)->where('type = ?', self::TYPE_RECOVERY)
        ));
    }

    /** Hard-remove a user's TOTP secret and every recovery code. */
    protected function _purgeTotp($userId)
    {
        $db = $this->getAdapter();
        $db->delete(
            $this->_name,
            $db->quoteInto('user_id = ?', $userId)
            . ' AND ' . $db->quoteInto('type IN (?)', [self::TYPE_TOTP, self::TYPE_RECOVERY])
        );
    }
}
