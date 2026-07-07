<?php
/**
 * Tiger_Service_Authentication — the AUTHENTICATION kernel service.
 *
 * Authentication = "who are you" (identity + session). Distinct from AUTHORIZATION
 * = "what may you do" (the ACL layer: Tiger_Acl_* + the authorization plugin).
 * Named in full to keep that boundary unambiguous.
 *
 * Ported from AskLevi's Core_Service_Auth, adapted to Tiger's substrate:
 *   - Password lives in `user_credential` (Tiger_Model_UserCredential::verifyPassword),
 *     NOT on the user row.
 *   - THE ROLE IS PER-ORG. AskLevi put a single global `role` on the user; Tiger
 *     resolves the role from the caller's org_user MEMBERSHIP for the active org.
 *     So the identity carries { user_id, org_id, role } where `role` is the role in
 *     THAT org. Switching orgs (useOrg) re-resolves the role. This is the multi-
 *     tenant heart of Tiger.
 *
 * This is a KERNEL service (`Tiger_Service_*`): reserved from /api (see
 * Tiger_Ajax_ServiceFactory), called in-process by a login controller. It is NOT
 * itself an /api service and does not extend Tiger_Service_Service.
 *
 * On success it also sets the request-wide actor (Tiger_Model_Table::setActor) so
 * created_by/updated_by stamps start flowing.
 *
 * @api
 */
class Tiger_Service_Authentication
{
    /** Role for an authenticated user who isn't (yet) a member of any org. */
    const ROLE_AUTHENTICATED = 'user';

    /** Role for an unauthenticated request. */
    const ROLE_GUEST = 'guest';

    /**
     * Sentinel returned by login() when the password was correct but a second factor
     * (TOTP/recovery) is still required. Distinguishable from an identity object and
     * from false via a strict === check.
     */
    const TWOFA_REQUIRED = '__tiger_2fa_required__';

    /** How long the pending 2FA challenge (password verified, awaiting code) lives. */
    const TWOFA_TTL = 300;    // 5 minutes
    /** Max code guesses on a pending 2FA challenge before it's abandoned (anti-brute-force). */
    const TWOFA_MAX_ATTEMPTS = 6;
    /** How long an in-progress TOTP enrollment (secret shown, awaiting confirm) lives. */
    const ENROLL_TTL = 600;   // 10 minutes
    /** How many single-use recovery codes to mint per enrollment. */
    const RECOVERY_COUNT = 10;

    /**
     * Authenticate by identifier (email) + password. Returns the identity object on
     * success, self::TWOFA_REQUIRED when a second factor is needed, or false on any
     * failure (unknown user, inactive, bad password).
     *
     * @return object|false
     */
    public function login($identifier, $password)
    {
        $password = (string) $password;
        $user     = (new Tiger_Model_User())->findByEmail($identifier);

        // Constant-time / no user-enumeration: on an unknown or inactive user, still
        // run a bcrypt verify against a dummy hash so response timing doesn't reveal
        // whether the email is registered.
        if (!$user || $user->status !== 'active') {
            password_verify($password, $this->_dummyHash());
            $this->_recordLogin(Tiger_Model_Login::RESULT_FAILURE, $identifier, null);
            return false;
        }

        $credModel = new Tiger_Model_UserCredential();
        $cred      = $credModel->passwordCredential($user->user_id);
        if (!$cred || $cred->secret === null) {
            password_verify($password, $this->_dummyHash());
            $this->_recordLogin(Tiger_Model_Login::RESULT_FAILURE, $identifier, $user->user_id);
            return false;
        }

        // Brute-force lockout: too many recent failures -> refuse without checking.
        if ($credModel->isLockedOut($cred)) {
            $this->_recordLogin(Tiger_Model_Login::RESULT_LOCKED, $identifier, $user->user_id);
            return false;
        }

        if (!password_verify($password, (string) $cred->secret)) {
            $credModel->recordFailure($cred->credential_id);
            $this->_recordLogin(Tiger_Model_Login::RESULT_FAILURE, $identifier, $user->user_id);
            return false;
        }

        $credModel->recordSuccess($cred->credential_id);

        // Second factor gate: if the user has a confirmed authenticator app, the
        // password is not enough — stash a short-lived pending challenge (bound to this
        // session) and tell the caller to collect a TOTP/recovery code. NO session is
        // established until verifyTwoFactor() succeeds, so a stolen password alone can't
        // sign in.
        if ($credModel->hasActiveTotp($user->user_id)) {
            $this->_beginPending2fa($user->user_id, $identifier);
            return self::TWOFA_REQUIRED;
        }

        return $this->_finishLogin($user, $identifier, 'password');
    }

    /**
     * Complete a login once all factors have passed: build the identity, establish the
     * session (with fixation protection), and audit the success. Shared by the
     * password path, the 2FA step-up, and OTP login.
     *
     * @return object the established identity
     */
    protected function _finishLogin($user, $identifier, $method)
    {
        $identity = $this->_buildIdentity($user);
        $this->_establish($identity, $user->user_id);
        $this->_recordLogin(Tiger_Model_Login::RESULT_SUCCESS, $identifier, $user->user_id, $identity->org_id, $method);
        return $identity;
    }

    /** Append a row to the login audit log. Never lets logging break a login. */
    protected function _recordLogin($result, $identifier, $userId = null, $orgId = null, $method = 'password')
    {
        try {
            (new Tiger_Model_Login())->record([
                'user_id'    => $userId,
                'org_id'     => $orgId,
                'identifier' => $identifier,
                'method'     => $method,
                'result'     => $result,
                'ip_address' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null,
                'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : null,
                // fingerprint: TBD — the client would supply a device hash.
            ]);
        } catch (Throwable $e) {
            error_log('Tiger login audit failed: ' . $e->getMessage());
        }
    }

    /** A valid bcrypt hash to verify against for timing-equalization (computed once). */
    private function _dummyHash()
    {
        static $hash = null;
        if ($hash === null) {
            $hash = password_hash('tiger-timing-equalizer', PASSWORD_DEFAULT);
        }
        return $hash;
    }

    /** Destroy the authenticated session. */
    public function logout()
    {
        Zend_Auth::getInstance()->clearIdentity();
        if (Zend_Session::isStarted()) {
            Zend_Session::regenerateId();
        }
        Tiger_Model_Table::setActor(null);
    }

    // ----- password reset ----------------------------------------------------

    /** Reset-link lifetime (seconds). */
    const RESET_TTL = 3600;   // 1 hour

    /**
     * Request a password reset. If the email maps to an ACTIVE user, issue a
     * password_reset challenge and email a reset link (built on $baseUrl, e.g.
     * "https://host"). ALWAYS returns void so the caller can respond success either
     * way — never revealing whether an account exists (no user enumeration).
     */
    public function requestPasswordReset($email, $baseUrl)
    {
        $user = (new Tiger_Model_User())->findByEmail($email);
        if (!$user || $user->status !== 'active' || empty($user->email)) {
            return;   // silent — do not disclose whether the address is registered
        }

        // A long random token travels in the link; the challenge stores only its hash.
        $token       = bin2hex(random_bytes(32));
        $challengeId = (new Tiger_Model_AuthChallenge())->issue($user->user_id, 'password_reset', $token, self::RESET_TTL);

        $url = rtrim((string) $baseUrl, '/') . '/auth/reset/cid/' . rawurlencode($challengeId) . '/code/' . rawurlencode($token);

        try {
            (new Tiger_Mail())
                ->to($user->email)
                ->subject('Reset your password')
                ->html($this->_resetEmailHtml($url))
                ->send();
        } catch (Throwable $e) {
            error_log('Tiger password-reset mail failed: ' . $e->getMessage());
        }
    }

    /**
     * Complete a reset. Validates the new password against the policy BEFORE burning
     * the token (so a weak password doesn't waste the link), then redeems the
     * challenge (verifies the code + single-use), sets the new password, and clears
     * any brute-force lockout.
     *
     * @return array{ok:bool,error:?string}   error = a ready-to-show message on failure
     */
    public function resetPassword($challengeId, $code, $newPassword, $confirm)
    {
        $newPassword = (string) $newPassword;
        if ($newPassword !== (string) $confirm) {
            return ['ok' => false, 'error' => 'The passwords do not match.'];
        }

        $model = new Tiger_Model_AuthChallenge();
        $row   = $model->find($challengeId)->current();
        if (!$row || $row->type !== 'password_reset' || $row->consumed_at !== null
            || strtotime($row->expires_at) < time()) {
            return ['ok' => false, 'error' => 'This reset link is invalid or has expired.'];
        }
        $userId = $row->user_id;

        // Policy (incl. reuse-prevention) BEFORE consuming the challenge.
        $violations = (new Tiger_Policy_Password())->validate($newPassword, $userId);
        if ($violations) {
            return ['ok' => false, 'error' => $this->_policyMessage($violations[0])];
        }

        // Verify the code + consume (single-use; a wrong code costs an attempt).
        if (!$model->redeem($challengeId, (string) $code)) {
            return ['ok' => false, 'error' => 'This reset link is invalid or has expired.'];
        }

        $credModel = new Tiger_Model_UserCredential();
        $credId    = $credModel->setPassword($userId, $newPassword);
        $credModel->recordSuccess($credId);   // clear any prior brute-force lockout

        return ['ok' => true, 'error' => null];
    }

    /** Map a Tiger_Policy_Password violation key to a ready-to-show message. */
    protected function _policyMessage($key)
    {
        $min = (new Tiger_Policy_Password())->config()['min_length'];
        $map = [
            'password.too_short'        => "Password must be at least {$min} characters.",
            'password.needs_complexity' => 'Use upper- and lower-case letters, a number, and a symbol.',
            'password.reused'           => "You can't reuse a recent password.",
        ];
        return isset($map[$key]) ? $map[$key] : "That password isn't allowed.";
    }

    // ----- one-time-code login (passwordless) --------------------------------
    //
    // CHANNEL = EMAIL. The challenge → verify → establish machinery is channel-
    // AGNOSTIC: only (a) how the user is identified and (b) how the code is delivered
    // differ. The substrate for SMS already exists (user_credential type 'sms' + a
    // phone identifier; auth_challenge type 'sms_otp'), so ADDING SMS OTP later is a
    // small, additive change — do NOT rework the machinery below:
    //
    //   1. Build a Tiger_Sms transport (a Tiger_Mail sibling: config-driven SNS/Twilio,
    //      creds in the DB config layer like mail.smtp.*). NOT part of this version.
    //   2. Add requestLoginCodeSms($e164): resolve the user via
    //      Tiger_Model_UserCredential::findVerifiedByIdentifier('sms', $e164), enforce
    //      the same send-cap, issue an 'sms_otp' challenge, deliver via Tiger_Sms.
    //   3. Add verifyLoginCodeSms($e164, $code): resolve the same user, then call
    //      $this->_completeCodeLogin($user, 'sms_otp', $e164, $code) — verify +
    //      _establish + audit are REUSED verbatim; no new session logic.
    //
    // See the "SMS / OTP flow" backlog item. Not implemented in this version.

    const OTP_TTL      = 600;   // one-time code lifetime, seconds (10 min)
    const OTP_SEND_CAP = 5;     // max codes emailed per user per hour (anti-bombing)

    /**
     * Email a one-time login code. If the address maps to an ACTIVE user under the
     * hourly send cap, issue an email_login challenge and email a 6-digit code. ALWAYS
     * returns void so the caller can advance the UI to code-entry either way — the
     * response never reveals whether the account exists (no enumeration).
     */
    public function requestLoginCode($email)
    {
        $user = (new Tiger_Model_User())->findByEmail($email);
        if (!$user || $user->status !== 'active' || empty($user->email)) {
            return;
        }

        $model = new Tiger_Model_AuthChallenge();
        if ($model->countRecent($user->user_id, 'email_login', 3600) >= self::OTP_SEND_CAP) {
            return;   // throttled — stop emailing, but the caller/UI can't tell
        }

        $model->invalidateActive($user->user_id, 'email_login');   // a fresh code voids the previous one
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);   // leading zeros kept
        $model->issue($user->user_id, 'email_login', $code, self::OTP_TTL);

        try {
            (new Tiger_Mail())
                ->to($user->email)
                ->subject('Your sign-in code: ' . $code)
                ->html($this->_otpEmailHtml($code))
                ->send();
        } catch (Throwable $e) {
            error_log('Tiger OTP mail failed: ' . $e->getMessage());
        }
    }

    /**
     * Verify an emailed login code and establish the session (passwordless sign-in).
     * Returns the identity on success, or false. See _completeCodeLogin (SMS reuses it).
     *
     * @return object|false
     */
    public function verifyLoginCode($email, $code)
    {
        $user = (new Tiger_Model_User())->findByEmail($email);
        return $this->_completeCodeLogin($user, 'email_login', (string) $email, $code);
    }

    /**
     * Channel-agnostic code-login completion: redeem the user's newest active code of
     * $type, and on success build + establish the session. This is the reusable core
     * that a future SMS channel calls with type 'sms_otp' (no changes needed here).
     *
     * @return object|false
     */
    protected function _completeCodeLogin($user, $type, $identifier, $code)
    {
        $code = preg_replace('/\D/', '', (string) $code);   // digits only

        if (!$user || $user->status !== 'active') {
            $this->_recordLogin(Tiger_Model_Login::RESULT_FAILURE, $identifier, null, null, 'otp');
            return false;
        }

        $model = new Tiger_Model_AuthChallenge();
        $ch    = $model->latestActive($user->user_id, $type);
        if ($code === '' || !$ch || !$model->redeem($ch->challenge_id, $code)) {
            $this->_recordLogin(Tiger_Model_Login::RESULT_FAILURE, $identifier, $user->user_id, null, 'otp');
            return false;
        }

        return $this->_finishLogin($user, $identifier, 'otp');
    }

    // ----- two-factor authentication (TOTP authenticator app) ----------------
    //
    // A confirmed TOTP factor turns login into two steps: password (login(), which
    // returns TWOFA_REQUIRED) then a code (verifyTwoFactor()). The pending state
    // between them lives in the session, bound to this browser, TTL- and
    // attempt-limited. Enrollment is also two steps — beginTotpEnrollment() shows the
    // secret/QR, activateTotp() confirms it with a live code before anything persists —
    // so a mistyped setup can never lock the user out. Recovery (backup) codes cover a
    // lost device; the secret is encrypted at rest (Tiger_Crypto).

    /** True if a password step has passed and we're waiting on a 2FA code (this session). */
    public function isTwoFactorPending()
    {
        $ns = $this->_twofaNs();
        return !empty($ns->user_id) && !empty($ns->expires) && $ns->expires >= time();
    }

    /**
     * Complete the second step of login by verifying a TOTP code OR a single-use
     * recovery code against the pending challenge. On success the session is
     * established (it's a full login from here). Returns the identity, or false
     * (no/expired pending challenge, too many attempts, or a bad code).
     *
     * @return object|false
     */
    public function verifyTwoFactor($code)
    {
        $ns = $this->_twofaNs();
        if (!$this->isTwoFactorPending()) {
            $this->_clearPending2fa();
            return false;
        }

        // Bound the guesses on a 6-digit code before abandoning the challenge.
        $ns->attempts = (int) (isset($ns->attempts) ? $ns->attempts : 0) + 1;
        if ($ns->attempts > self::TWOFA_MAX_ATTEMPTS) {
            $this->_clearPending2fa();
            return false;
        }

        $userId     = (string) $ns->user_id;
        $identifier = isset($ns->identifier) ? (string) $ns->identifier : '';
        $user       = (new Tiger_Model_User())->findById($userId);
        if (!$user || $user->status !== 'active') {
            $this->_clearPending2fa();
            return false;
        }

        $method = 'totp';
        $ok     = $this->_verifyTotpCode($userId, $code);
        if (!$ok && (new Tiger_Model_UserCredential())->redeemRecoveryCode($userId, $code)) {
            $ok     = true;
            $method = 'recovery';
        }
        if (!$ok) {
            $this->_recordLogin(Tiger_Model_Login::RESULT_FAILURE, $identifier ?: $user->email, $userId, null, 'totp');
            return false;   // keep the pending challenge so the user can retry
        }

        $this->_clearPending2fa();
        return $this->_finishLogin($user, $identifier ?: $user->email, $method);
    }

    /**
     * Begin TOTP enrollment for the signed-in user: mint a secret + recovery codes and
     * hold them in the session until confirmed. Returns the data the setup screen needs
     * — the base32 secret, the otpauth:// URI (for the QR), and the PLAINTEXT recovery
     * codes (shown once, here) — or null if not signed in / crypto unconfigured.
     *
     * NOTHING is written to the DB yet: the real credential is created only once
     * activateTotp() sees a valid code, so a botched setup never disables an existing
     * factor or half-enrolls a broken one.
     *
     * @return array{secret:string,otpauth:string,recovery:string[]}|null
     */
    public function beginTotpEnrollment()
    {
        $identity = $this->getIdentity();
        if (!$identity || empty($identity->user_id) || !Tiger_Crypto::isConfigured()) {
            return null;
        }

        $secret   = Tiger_Auth_Totp::generateSecret();
        $codes    = $this->_generateRecoveryCodes();
        $issuer   = $this->_twofaIssuer();
        $account  = (string) ($identity->email ?: ($identity->username ?: $identity->user_id));

        $ns = $this->_enrollNs();
        $ns->secret   = $secret;
        $ns->recovery = array_map([$this, '_hashRecovery'], $codes);
        $ns->expires  = time() + self::ENROLL_TTL;

        return [
            'secret'   => $secret,
            'otpauth'  => Tiger_Auth_Totp::uri($secret, $account, $issuer),
            'recovery' => $codes,
        ];
    }

    /**
     * Confirm an in-progress enrollment: the code must validate against the pending
     * secret, then the encrypted secret + hashed recovery codes are persisted and the
     * factor goes live. Returns true on success, false (expired setup or wrong code).
     */
    public function activateTotp($code)
    {
        $identity = $this->getIdentity();
        if (!$identity || empty($identity->user_id)) {
            return false;
        }
        $ns = $this->_enrollNs();
        if (empty($ns->secret) || empty($ns->expires) || $ns->expires < time()) {
            return false;
        }
        if (!Tiger_Auth_Totp::verify((string) $ns->secret, (string) $code)) {
            return false;
        }

        (new Tiger_Model_UserCredential())->replaceTotp(
            $identity->user_id,
            Tiger_Crypto::encrypt((string) $ns->secret),
            (array) $ns->recovery
        );
        $this->_clearEnroll();
        return true;
    }

    /**
     * Turn TOTP off. Requires a valid current authenticator code OR a recovery code —
     * so a merely-open session (e.g. an unlocked, unattended browser) can't silently
     * strip the user's second factor. Returns true if disabled.
     */
    public function disableTotp($code)
    {
        $identity = $this->getIdentity();
        if (!$identity || empty($identity->user_id)) {
            return false;
        }
        $model = new Tiger_Model_UserCredential();
        if (!$this->_verifyTotpCode($identity->user_id, $code)
            && !$model->redeemRecoveryCode($identity->user_id, $code)) {
            return false;
        }
        $model->removeTotp($identity->user_id);
        return true;
    }

    /**
     * 2FA state for the security screen: is it enabled, how many recovery codes remain,
     * and can it be enrolled at all (needs the app encryption key configured).
     *
     * @return array{enabled:bool,recovery:int,available:bool}
     */
    public function getTwoFactorStatus()
    {
        $identity = $this->getIdentity();
        if (!$identity || empty($identity->user_id)) {
            return ['enabled' => false, 'recovery' => 0, 'available' => false];
        }
        $model = new Tiger_Model_UserCredential();
        return [
            'enabled'   => $model->hasActiveTotp($identity->user_id),
            'recovery'  => $model->recoveryCount($identity->user_id),
            'available' => Tiger_Crypto::isConfigured(),
        ];
    }

    /** Verify a live TOTP code against the user's stored (encrypted) secret; touches on success. */
    protected function _verifyTotpCode($userId, $code)
    {
        $cred = (new Tiger_Model_UserCredential())->activeTotp($userId);
        if (!$cred || $cred->secret === null) {
            return false;
        }
        try {
            $secret = Tiger_Crypto::decrypt((string) $cred->secret);
        } catch (Throwable $e) {
            return false;   // fail closed on a bad/rotated key
        }
        if (!Tiger_Auth_Totp::verify($secret, $code)) {
            return false;
        }
        (new Tiger_Model_UserCredential())->touch($cred->credential_id);
        return true;
    }

    /** Fresh set of human-friendly single-use recovery codes ("xxxxx-xxxxx"). */
    protected function _generateRecoveryCodes($n = self::RECOVERY_COUNT)
    {
        $codes = [];
        for ($i = 0; $i < $n; $i++) {
            $hex = bin2hex(random_bytes(5));   // 10 hex chars
            $codes[] = substr($hex, 0, 5) . '-' . substr($hex, 5, 5);
        }
        return $codes;
    }

    /** Normalize (strip separators, lowercase) then hash a recovery code for storage/compare. */
    protected function _hashRecovery($code)
    {
        return hash('sha256', strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $code)));
    }

    /** The issuer label authenticator apps display — config override, else the site name. */
    protected function _twofaIssuer()
    {
        $cfg = Zend_Registry::isRegistered('Zend_Config') ? Zend_Registry::get('Zend_Config') : null;
        if ($cfg && $cfg->get('tiger')) {
            $twofa = $cfg->tiger->get('twofa');
            if ($twofa && (string) $twofa->get('issuer') !== '') {
                return (string) $twofa->issuer;
            }
            $site = $cfg->tiger->get('site');
            if ($site && (string) $site->get('name') !== '') {
                return (string) $site->name;
            }
        }
        return 'Tiger';
    }

    /** Stash the pending 2FA challenge (password verified, awaiting a code). */
    protected function _beginPending2fa($userId, $identifier)
    {
        $ns = $this->_twofaNs();
        $ns->user_id    = (string) $userId;
        $ns->identifier = (string) $identifier;
        $ns->expires    = time() + self::TWOFA_TTL;
        $ns->attempts   = 0;
    }

    /** Clear the pending 2FA challenge. */
    protected function _clearPending2fa()
    {
        $ns = $this->_twofaNs();
        unset($ns->user_id, $ns->identifier, $ns->expires, $ns->attempts);
    }

    /** Clear an in-progress enrollment. */
    protected function _clearEnroll()
    {
        $ns = $this->_enrollNs();
        unset($ns->secret, $ns->recovery, $ns->expires);
    }

    /** Session namespace for the between-steps login challenge. */
    protected function _twofaNs()
    {
        if (!Zend_Session::isStarted()) {
            Zend_Session::start();
        }
        return new Zend_Session_Namespace('Tiger_TwoFactor');
    }

    /** Session namespace for the pending (unconfirmed) TOTP enrollment. */
    protected function _enrollNs()
    {
        if (!Zend_Session::isStarted()) {
            Zend_Session::start();
        }
        return new Zend_Session_Namespace('Tiger_TotpEnroll');
    }

    /** The one-time-code email body (the code shown large + monospace). */
    protected function _otpEmailHtml($code)
    {
        $c = htmlspecialchars((string) $code, ENT_QUOTES);
        return '<div style="font-family:Inter,Arial,sans-serif;max-width:480px;margin:0 auto;color:#1f2937;line-height:1.5;">'
            . '<h2 style="color:#111827;margin:0 0 12px;">Your sign-in code</h2>'
            . '<p>Use this code to finish signing in. It expires in 10 minutes.</p>'
            . '<p style="font-size:34px;font-weight:700;letter-spacing:8px;font-family:monospace;'
            . 'background:#f3f4f6;border-radius:10px;padding:16px 0;text-align:center;margin:20px 0;color:#111827;">' . $c . '</p>'
            . '<p style="font-size:13px;color:#6b7280;">If you didn\'t request this, you can ignore this email — '
            . 'no one can sign in without the code.</p>'
            . '</div>';
    }

    /** The reset email body (inline styles for mail-client compatibility). */
    protected function _resetEmailHtml($url)
    {
        $u = htmlspecialchars((string) $url, ENT_QUOTES);
        return '<div style="font-family:Inter,Arial,sans-serif;max-width:480px;margin:0 auto;color:#1f2937;line-height:1.5;">'
            . '<h2 style="color:#111827;margin:0 0 12px;">Reset your password</h2>'
            . '<p>We received a request to reset your password. Choose a new one with the button below. '
            . 'This link expires in 1 hour.</p>'
            . '<p style="margin:24px 0;"><a href="' . $u . '" style="background:#3459e6;color:#ffffff;'
            . 'padding:12px 22px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600;">'
            . 'Reset password</a></p>'
            . '<p style="font-size:13px;color:#6b7280;">Or paste this link into your browser:<br>'
            . '<a href="' . $u . '" style="color:#3459e6;word-break:break-all;">' . $u . '</a></p>'
            . '<p style="font-size:13px;color:#6b7280;">If you didn\'t request this, you can safely ignore this '
            . 'email — your password won\'t change.</p>'
            . '</div>';
    }

    /**
     * Switch the active org for the already-authenticated user, re-resolving the
     * role from that org's membership. Returns the new identity, or false if the
     * caller isn't an active member of the target org.
     *
     * @return object|false
     */
    public function useOrg($orgId)
    {
        $current = $this->getIdentity();
        if (!$current) {
            return false;
        }
        $user = (new Tiger_Model_User())->findById($current->user_id);
        if (!$user) {
            return false;
        }
        $identity = $this->_buildIdentity($user, $orgId);
        if ($identity->org_id !== $orgId) {
            return false;   // not an active member of the requested org
        }
        Zend_Auth::getInstance()->getStorage()->write($identity);
        return $identity;
    }

    public function isAuthenticated()
    {
        return Zend_Auth::getInstance()->hasIdentity();
    }

    public function getIdentity()
    {
        $auth = Zend_Auth::getInstance();
        return $auth->hasIdentity() ? $auth->getIdentity() : null;
    }

    // ----- screen lock -------------------------------------------------------
    //
    // A locked screen keeps the authenticated session valid but holds every request
    // at the lock card (enforced by Tiger_Controller_Plugin_Authorization) until the
    // user re-verifies their password. It is NOT a logout — identity is untouched.

    /** Lock the current screen (no-op for a guest). */
    public function lock()
    {
        if (!$this->isAuthenticated()) {
            return;
        }
        $this->_lockNs()->locked = true;
    }

    /** Is the current authenticated session screen-locked? */
    public function isLocked()
    {
        return $this->isAuthenticated() && !empty($this->_lockNs()->locked);
    }

    /**
     * Re-resolve the identity's role from live membership and write it back onto the
     * session identity. While the screen is locked the authorization plugin downgrades
     * the stored identity to 'guest' (so /api services see guest too); call this right
     * after a successful unlock so the SAME request — the post-unlock redirect and any
     * _isAdmin() read — sees the real role again instead of the stale guest. (Without
     * it the role only self-corrects on the next request's _resolveRole.)
     */
    public function refreshRole()
    {
        $identity = $this->getIdentity();
        if (!$identity || empty($identity->user_id)) {
            return;
        }
        $role = self::ROLE_AUTHENTICATED;
        if (!empty($identity->org_id)) {
            try {
                $live = (new Tiger_Model_OrgUser())->roleOf($identity->org_id, $identity->user_id);
                if ($live) {
                    $role = $live;
                }
            } catch (Throwable $e) {
                // keep the base authenticated role
            }
        }
        $identity->role = $role;
    }

    /**
     * Unlock by re-verifying the current identity's password. Returns true (lock
     * cleared) or false (bad password; the session stays locked).
     */
    public function unlock($password)
    {
        $identity = $this->getIdentity();
        if (!$identity || empty($identity->user_id)) {
            return false;
        }
        if (!(new Tiger_Model_UserCredential())->verifyPassword($identity->user_id, (string) $password)) {
            return false;
        }
        unset($this->_lockNs()->locked);
        $this->refreshRole();   // undo the while-locked guest downgrade for this request
        return true;
    }

    /**
     * Email a one-time UNLOCK code to the current (locked) identity's own address —
     * for users who signed in with a code, or have no password at all. Reuses the OTP
     * machinery (send-cap + hashed/expiring/attempt-limited challenge). No-op if not
     * authenticated.
     */
    public function requestUnlockCode()
    {
        $identity = $this->getIdentity();
        if ($identity && !empty($identity->email)) {
            $this->requestLoginCode((string) $identity->email);
        }
    }

    /**
     * Unlock by verifying an emailed code for the CURRENT identity. Clears the lock
     * WITHOUT re-establishing the session — it's still you (a lock re-verify), not a
     * fresh login. Returns true on success, false otherwise.
     */
    public function unlockWithCode($code)
    {
        $identity = $this->getIdentity();
        if (!$identity || empty($identity->user_id)) {
            return false;
        }
        $model = new Tiger_Model_AuthChallenge();
        $ch    = $model->latestActive($identity->user_id, 'email_login');
        $code  = preg_replace('/\D/', '', (string) $code);
        if ($code === '' || !$ch || !$model->redeem($ch->challenge_id, $code)) {
            return false;
        }
        unset($this->_lockNs()->locked);
        $this->refreshRole();   // undo the while-locked guest downgrade for this request
        return true;
    }

    /** The session namespace carrying the screen-lock flag. */
    protected function _lockNs()
    {
        if (!Zend_Session::isStarted()) {
            Zend_Session::start();
        }
        return new Zend_Session_Namespace('Tiger_Lock');
    }

    // ----- post-auth return target -------------------------------------------
    //
    // Where to send the user after they authenticate — kept in the SESSION, never a
    // URL param. Set by the authorization plugin when it bounces a guest (to login)
    // or a locked request (to the lock card); consumed by login/unlock.

    /** Remember a local return path (ignores non-local paths + auth pages, so no loops). */
    public function setReturnTo($path)
    {
        $path = (string) $path;
        if ($path === '' || $path[0] !== '/' || strpos($path, '//') === 0 || strpos($path, '/auth/') === 0) {
            return;
        }
        $this->_authNs()->returnTo = $path;
    }

    /** Read + clear the remembered return path ('' if none). */
    public function takeReturnTo()
    {
        $ns = $this->_authNs();
        $to = isset($ns->returnTo) ? (string) $ns->returnTo : '';
        unset($ns->returnTo);
        return $to;
    }

    /** The session namespace carrying the post-auth return path. */
    protected function _authNs()
    {
        if (!Zend_Session::isStarted()) {
            Zend_Session::start();
        }
        return new Zend_Session_Namespace('Tiger_Auth');
    }

    // -------------------------------------------------------------------------

    /**
     * Build the session identity for a user, resolving the ACTIVE ORG + the ROLE
     * held in that org (role-on-membership). If $orgId is given, that org is used
     * (when the user is an active member); otherwise the user's first active
     * membership is the primary org. A user with no membership is authenticated
     * with the base role and a null org (they can then create/join an org).
     *
     * @return object
     */
    protected function _buildIdentity($user, $orgId = null)
    {
        $ouModel = new Tiger_Model_OrgUser();

        $activeOrgId = null;
        $role        = self::ROLE_AUTHENTICATED;

        if ($orgId !== null) {
            $m = $ouModel->membership($orgId, $user->user_id);
            if ($m && $m->status === 'active') {
                $activeOrgId = $m->org_id;
                $role        = $m->role;
            }
        } else {
            foreach ($ouModel->orgsForUser($user->user_id) as $m) {
                if ($m->status === 'active') {
                    $activeOrgId = $m->org_id;
                    $role        = $m->role;
                    break;   // first active membership = primary org
                }
            }
        }

        $orgName = null;
        if ($activeOrgId !== null) {
            $org = (new Tiger_Model_Org())->findById($activeOrgId);
            $orgName = $org ? $org->name : null;
        }

        return (object) [
            'user_id'  => $user->user_id,
            'email'    => $user->email,
            'username' => $user->username,
            'org_id'   => $activeOrgId,
            'org_name' => $orgName,
            'role'     => $role,   // the role IN the active org (or the base role)
        ];
    }

    /** Write the identity to session with fixation protection, and set the actor. */
    protected function _establish($identity, $userId)
    {
        if (!Zend_Session::isStarted()) {
            Zend_Session::start();
        }
        Zend_Auth::getInstance()->clearIdentity();
        Zend_Session::regenerateId(true);              // session-fixation protection
        Zend_Auth::getInstance()->getStorage()->write($identity);

        Tiger_Model_Table::setActor($userId);          // created_by/updated_by now flow
    }
}
