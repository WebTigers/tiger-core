<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Auth_Totp;
use Tiger_Crypto;
use Tiger_Model_AuthChallenge;
use Tiger_Model_Login;
use Tiger_Model_Org;
use Tiger_Model_OrgUser;
use Tiger_Model_User;
use Tiger_Model_UserCredential;
use Tiger_Model_Table;
use Tiger_Security;
use Tiger_Service_Authentication;
use Tiger_Uuid;
use Zend_Auth;
use Zend_Auth_Storage_Session;
use Zend_Config;
use Zend_Registry;
use Zend_Session;

/**
 * Tiger_Service_Authentication — the login SPINE (kernel service, reserved from /api). This exercises
 * the engine's real public surface end-to-end against a real user + `password` credential + `org_user`
 * membership, and a real (unit-test-mode) Zend_Session so the session-establishing paths actually run:
 *
 *   - PASSWORD login: a correct identifier+password issues an identity (org-resolved role), stamps the
 *     model actor, and writes a `login` success row; a wrong password / unknown user / inactive user /
 *     no-credential all fail the SAME way (no user enumeration) with an audit failure row.
 *   - BRUTE-FORCE lockout: MAX_FAILURES failures lock the account — the right password is then refused
 *     with a `locked` audit row (the lock trips before the check).
 *   - PEPPER migration: a password stored with NO pepper still logs in once a pepper is added, and the
 *     hash migrates transparently (through the model's verifier chain).
 *   - ONE-TIME code login (passwordless): a valid emailed `email_login` code signs in; a wrong/reused
 *     code fails; requesting a code is a silent no-op for an unknown/inactive user and is send-capped.
 *   - PASSWORD reset: a redeemed reset token sets the new password and clears lockout; a mismatched
 *     confirm / weak password / wrong code fails WITHOUT burning the token; the request is enumeration-safe.
 *   - TOTP 2FA: a confirmed factor turns login into password→code (login() returns TWOFA_REQUIRED, no
 *     session yet); a live TOTP code OR a recovery code completes it; a bad code keeps the pending
 *     challenge, and too many guesses abandon it.
 *   - SESSION lifecycle: logout clears identity + actor; screen lock/unlock; setReturnTo/takeReturnTo;
 *     useOrg re-resolves the role or denies a non-member.
 *
 * Session note: Zend_Session can't start under CLI (headers already sent), so setUp enables Zend's
 * documented unit-test session mode (array-backed) and resets $_SESSION per test — so the REAL
 * _establish()/logout()/namespace paths run rather than being stubbed. Crypto key + pepper are seeded
 * into the registry config (test-only secrets) exactly like the Wave-2 model tests.
 */
#[CoversClass(Tiger_Service_Authentication::class)]
final class AuthenticationTest extends IntegrationTestCase
{
    private const KEY    = 'ERERERERERERERERERERERERERERERERERERERERERE='; // 32 × 0x11, base64
    private const PEPPER = 'cGVwcGVyLUEtMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDA='; // arbitrary valid base64

    private Tiger_Service_Authentication $auth;
    private bool $priorUnitTestMode;

    protected function setUp(): void
    {
        parent::setUp();

        // Real session ops (start/regenerateId/namespaces) via Zend's array-backed unit-test mode.
        $this->priorUnitTestMode = Zend_Session::$_unitTestEnabled;
        Zend_Session::$_unitTestEnabled = true;
        $_SESSION = [];
        // Use the production-shaped session storage so getIdentity()/write() run the real path.
        Zend_Auth::getInstance()->setStorage(new Zend_Auth_Storage_Session());
        Zend_Auth::getInstance()->clearIdentity();

        $this->useCrypto(self::PEPPER);
        $this->auth = new Tiger_Service_Authentication();
    }

    protected function tearDown(): void
    {
        Zend_Auth::getInstance()->clearIdentity();
        $_SESSION = [];
        Zend_Session::$_unitTestEnabled = $this->priorUnitTestMode;
        parent::tearDown();
    }

    /** Seed the process-global crypto key + pepper the models read from the registry (test secrets only). */
    private function useCrypto(?string $pepper): void
    {
        $tiger = ['crypto' => ['key' => self::KEY]];
        if ($pepper !== null) {
            $tiger['security'] = ['pepper' => $pepper];
        }
        Zend_Registry::set('Zend_Config', new Zend_Config(['tiger' => $tiger], true));
    }

    /** A unique-ish email for a fresh fixture user. */
    private function email(): string
    {
        return 'auth-' . bin2hex(random_bytes(8)) . '@example.test';
    }

    /** Create an ACTIVE user with a password credential; returns [user_id, email]. */
    private function makeUserWithPassword(string $password, string $status = 'active'): array
    {
        $email = $this->email();
        $uid   = (new Tiger_Model_User())->insert(['email' => $email, 'status' => $status]);
        (new Tiger_Model_UserCredential())->setPassword($uid, $password);
        return [$uid, $email];
    }

    /** Create an org and an active membership for the user with the given role; returns org_id. */
    private function makeOrgMembership(string $userId, string $role): string
    {
        $orgId = (new Tiger_Model_Org())->insert([
            'name' => 'Org ' . substr(Tiger_Uuid::v7(), 0, 8),
            'slug' => 'org-' . bin2hex(random_bytes(6)),
        ]);
        (new Tiger_Model_OrgUser())->insert([
            'org_id' => $orgId, 'user_id' => $userId, 'role' => $role, 'status' => 'active',
        ]);
        return $orgId;
    }

    /** Count login-audit rows for a user with a given result. */
    private function auditCount(?string $userId, string $result, ?string $identifier = null): int
    {
        $sel = $this->db->select()->from('login', ['c' => 'COUNT(*)'])->where('result = ?', $result);
        if ($userId !== null)     { $sel->where('user_id = ?', $userId); }
        if ($identifier !== null) { $sel->where('identifier = ?', $identifier); }
        return (int) $this->db->fetchOne($sel);
    }

    // ----- password login ----------------------------------------------------

    #[Test]
    public function a_correct_password_issues_an_identity_stamps_the_actor_and_audits_success(): void
    {
        [$uid, $email] = $this->makeUserWithPassword('correct horse battery');

        $identity = $this->auth->login($email, 'correct horse battery');

        $this->assertIsObject($identity, 'a good login returns the identity object');
        $this->assertSame($uid, $identity->user_id);
        $this->assertSame($email, $identity->email);
        $this->assertTrue($this->auth->isAuthenticated(), 'the session now carries an identity');
        $this->assertEquals($identity, $this->auth->getIdentity(), 'getIdentity() returns the established identity');
        $this->assertSame($uid, Tiger_Model_Table::actor(), 'the request actor is stamped to the signed-in user');
        $this->assertSame(1, $this->auditCount($uid, Tiger_Model_Login::RESULT_SUCCESS), 'a success audit row is written');
    }

    #[Test]
    public function login_resolves_the_role_from_the_org_membership(): void
    {
        [$uid, $email] = $this->makeUserWithPassword('battery staple horse');
        $orgId = $this->makeOrgMembership($uid, 'manager');

        $identity = $this->auth->login($email, 'battery staple horse');

        $this->assertIsObject($identity);
        $this->assertSame($orgId, $identity->org_id, 'the primary active org is resolved');
        $this->assertSame('manager', $identity->role, 'the role comes from the org_user membership, not the user');
        $this->assertNotNull($identity->org_name, 'the org name is resolved onto the identity');
    }

    #[Test]
    public function a_user_with_no_membership_gets_the_base_authenticated_role_and_no_org(): void
    {
        [, $email] = $this->makeUserWithPassword('lonely password 1');

        $identity = $this->auth->login($email, 'lonely password 1');

        $this->assertIsObject($identity);
        $this->assertNull($identity->org_id, 'no membership => no active org');
        $this->assertSame(Tiger_Service_Authentication::ROLE_AUTHENTICATED, $identity->role, 'falls back to the base role');
    }

    #[Test]
    public function a_wrong_password_fails_with_no_session_and_an_audited_failure(): void
    {
        [$uid, $email] = $this->makeUserWithPassword('the right one 99');

        $result = $this->auth->login($email, 'the WRONG one 99');

        $this->assertFalse($result, 'a wrong password strictly returns false');
        $this->assertFalse($this->auth->isAuthenticated(), 'no session is established');
        $this->assertNull($this->auth->getIdentity());
        $this->assertSame(1, $this->auditCount($uid, Tiger_Model_Login::RESULT_FAILURE), 'a failure audit row (with the user id) is written');
    }

    #[Test]
    public function an_unknown_user_fails_identically_no_enumeration(): void
    {
        $unknown = 'ghost-' . bin2hex(random_bytes(6)) . '@example.test';

        $result = $this->auth->login($unknown, 'whatever password');

        // Same strict `false` shape as a wrong password — the response can't distinguish the two.
        $this->assertFalse($result, 'an unknown identifier returns the same false as a wrong password');
        $this->assertFalse($this->auth->isAuthenticated());
        $this->assertSame(1, $this->auditCount(null, Tiger_Model_Login::RESULT_FAILURE, $unknown), 'the miss is audited with a null user_id');
    }

    #[Test]
    public function an_inactive_user_cannot_log_in(): void
    {
        [, $email] = $this->makeUserWithPassword('suspended pw 123', 'suspended');

        $this->assertFalse($this->auth->login($email, 'suspended pw 123'), 'a non-active user is refused even with the right password');
        $this->assertFalse($this->auth->isAuthenticated());
    }

    #[Test]
    public function a_user_without_a_password_credential_cannot_password_login(): void
    {
        $email = $this->email();
        (new Tiger_Model_User())->insert(['email' => $email, 'status' => 'active']);   // no credential

        $this->assertFalse($this->auth->login($email, 'anything at all'), 'no password credential => false');
    }

    // ----- brute-force lockout ----------------------------------------------

    #[Test]
    public function repeated_failures_lock_the_account_even_against_the_right_password(): void
    {
        [$uid, $email] = $this->makeUserWithPassword('unlock me please 1');

        // Walk the credential to the lockout threshold with wrong passwords.
        for ($i = 0; $i < Tiger_Model_UserCredential::MAX_FAILURES; $i++) {
            $this->assertFalse($this->auth->login($email, 'nope nope nope ' . $i));
        }

        // Now the CORRECT password is refused — the lock trips before the password is even checked.
        $this->assertFalse($this->auth->login($email, 'unlock me please 1'), 'a locked account refuses the correct password');
        $this->assertFalse($this->auth->isAuthenticated());
        $this->assertGreaterThanOrEqual(1, $this->auditCount($uid, Tiger_Model_Login::RESULT_LOCKED), 'the lockout is audited as a locked result');
    }

    // ----- pepper migration --------------------------------------------------

    #[Test]
    public function a_password_hashed_without_a_pepper_still_logs_in_after_a_pepper_is_added(): void
    {
        // Store the password with NO pepper — a legacy, un-peppered bcrypt hash.
        $this->useCrypto(null);
        [$uid, $email] = $this->makeUserWithPassword('legacy secret 88');
        $legacy = (string) (new Tiger_Model_UserCredential())->passwordCredential($uid)->secret;

        // Turn the pepper on; login must still succeed AND transparently migrate the stored hash.
        $this->useCrypto(self::PEPPER);
        $identity = $this->auth->login($email, 'legacy secret 88');

        $this->assertIsObject($identity, 'a legacy hash still verifies once a pepper is configured');
        $migrated = (string) (new Tiger_Model_UserCredential())->passwordCredential($uid)->secret;
        $this->assertNotSame($legacy, $migrated, 'the hash was re-hashed with the pepper on login');
    }

    // ----- one-time-code (passwordless) login --------------------------------

    #[Test]
    public function a_valid_emailed_login_code_signs_the_user_in(): void
    {
        $email = $this->email();
        $uid   = (new Tiger_Model_User())->insert(['email' => $email, 'status' => 'active']);
        // Seed the challenge directly with a known code (requestLoginCode's code is random + only hashed).
        (new Tiger_Model_AuthChallenge())->issue($uid, 'email_login', '135790', 600);

        $identity = $this->auth->verifyLoginCode($email, '135790');

        $this->assertIsObject($identity, 'the right code signs in passwordlessly');
        $this->assertSame($uid, $identity->user_id);
        $this->assertTrue($this->auth->isAuthenticated());
        $this->assertSame(1, $this->auditCount($uid, Tiger_Model_Login::RESULT_SUCCESS), 'the OTP login is audited as a success');
    }

    #[Test]
    public function a_wrong_then_reused_login_code_both_fail(): void
    {
        $email = $this->email();
        $uid   = (new Tiger_Model_User())->insert(['email' => $email, 'status' => 'active']);
        (new Tiger_Model_AuthChallenge())->issue($uid, 'email_login', '246800', 600);

        $this->assertFalse($this->auth->verifyLoginCode($email, '000000'), 'a wrong code fails');
        $this->assertIsObject($this->auth->verifyLoginCode($email, '246800'), 'the right code then works');
        $this->assertFalse((new Tiger_Service_Authentication())->verifyLoginCode($email, '246800'), 'single-use: the same code cannot be replayed');
    }

    #[Test]
    public function requesting_a_login_code_is_a_silent_noop_for_unknown_or_inactive_users(): void
    {
        $model = new Tiger_Model_AuthChallenge();

        // Unknown address — nothing is issued and no error surfaces.
        $this->auth->requestLoginCode('nobody-' . bin2hex(random_bytes(6)) . '@example.test');

        // Inactive user — also silent, no challenge.
        $email = $this->email();
        $uid   = (new Tiger_Model_User())->insert(['email' => $email, 'status' => 'suspended']);
        $this->auth->requestLoginCode($email);

        $this->assertSame(0, $model->countRecent($uid, 'email_login', 3600), 'no code is issued for an inactive user (no enumeration)');
    }

    #[Test]
    public function requesting_a_login_code_issues_one_for_an_active_user_and_respects_the_send_cap(): void
    {
        $email = $this->email();
        $uid   = (new Tiger_Model_User())->insert(['email' => $email, 'status' => 'active']);
        $model = new Tiger_Model_AuthChallenge();

        $this->auth->requestLoginCode($email);
        $this->assertSame(1, $model->countRecent($uid, 'email_login', 3600), 'an active user gets exactly one fresh code');

        // Pre-fill to the cap, then a further request must NOT issue another.
        for ($i = $model->countRecent($uid, 'email_login', 3600); $i < Tiger_Service_Authentication::OTP_SEND_CAP; $i++) {
            $model->issue($uid, 'email_login', str_pad((string) $i, 6, '0', STR_PAD_LEFT), 600);
        }
        $atCap = $model->countRecent($uid, 'email_login', 3600);
        $this->auth->requestLoginCode($email);
        $this->assertSame($atCap, $model->countRecent($uid, 'email_login', 3600), 'at the hourly cap no further code is issued');
    }

    // ----- password reset ----------------------------------------------------

    #[Test]
    public function resetting_the_password_redeems_the_token_sets_the_new_password_and_clears_lockout(): void
    {
        [$uid, $email] = $this->makeUserWithPassword('old password one');
        $token = bin2hex(random_bytes(16));
        $cid   = (new Tiger_Model_AuthChallenge())->issue($uid, 'password_reset', $token, 3600);

        $out = $this->auth->resetPassword($cid, $token, 'brand new password 2', 'brand new password 2');

        $this->assertTrue($out['ok'], 'a valid reset succeeds');
        $this->assertNull($out['error']);
        // The new password now logs in; the old one no longer does.
        $this->assertIsObject((new Tiger_Service_Authentication())->login($email, 'brand new password 2'), 'the new password works');
        $this->assertFalse((new Tiger_Service_Authentication())->login($email, 'old password one'), 'the old password is dead');
    }

    #[Test]
    public function a_reset_with_a_mismatched_confirmation_or_wrong_code_fails_without_burning_the_token(): void
    {
        [$uid] = $this->makeUserWithPassword('keep this one 11');
        $token = bin2hex(random_bytes(16));
        $cid   = (new Tiger_Model_AuthChallenge())->issue($uid, 'password_reset', $token, 3600);

        // Mismatched confirmation is caught BEFORE the token is touched.
        $mismatch = $this->auth->resetPassword($cid, $token, 'new one aaaa', 'new one bbbb');
        $this->assertFalse($mismatch['ok']);
        $this->assertNotNull($mismatch['error']);
        $this->assertNull((new Tiger_Model_AuthChallenge())->findById($cid)->consumed_at, 'a mismatch never consumes the token');

        // A wrong code fails but (matching confirms) still doesn't let the real token through afterward being spent.
        $wrong = $this->auth->resetPassword($cid, 'not-the-token', 'new one cccc', 'new one cccc');
        $this->assertFalse($wrong['ok']);

        // The genuine token still works — proving neither prior attempt burned it.
        $good = $this->auth->resetPassword($cid, $token, 'the real new one', 'the real new one');
        $this->assertTrue($good['ok'], 'the real token still redeems after a mismatch + a wrong code');
    }

    #[Test]
    public function a_reset_below_the_length_policy_is_rejected_before_the_token_is_spent(): void
    {
        [$uid] = $this->makeUserWithPassword('policy guard 123');
        $token = bin2hex(random_bytes(16));
        $cid   = (new Tiger_Model_AuthChallenge())->issue($uid, 'password_reset', $token, 3600);

        $out = $this->auth->resetPassword($cid, $token, 'short', 'short');   // < 8 chars

        $this->assertFalse($out['ok'], 'a too-short password is rejected');
        $this->assertNull((new Tiger_Model_AuthChallenge())->findById($cid)->consumed_at, 'a weak password never burns the reset link');
    }

    #[Test]
    public function requesting_a_password_reset_is_enumeration_safe(): void
    {
        $model = new Tiger_Model_AuthChallenge();

        // Unknown + inactive both return void and issue no challenge.
        $this->auth->requestPasswordReset('nobody-' . bin2hex(random_bytes(6)) . '@example.test', 'https://host');
        $email = $this->email();
        $uid   = (new Tiger_Model_User())->insert(['email' => $email, 'status' => 'suspended']);
        $this->auth->requestPasswordReset($email, 'https://host');

        $this->assertSame(0, $model->countRecent($uid, 'password_reset', 3600), 'no reset challenge for an inactive account');
    }

    // ----- two-factor (TOTP) -------------------------------------------------

    /** Give a user a confirmed TOTP factor; returns [secret, recoveryPlaintextCodes]. */
    private function enrollTotp(string $userId): array
    {
        $secret   = Tiger_Auth_Totp::generateSecret();
        $recovery = ['aaaa11111', 'bbbb22222'];
        $hashes   = array_map(
            fn ($c) => Tiger_Security::hashCode(strtolower(preg_replace('/[^a-z0-9]/i', '', $c)), 'recovery'),
            $recovery
        );
        (new Tiger_Model_UserCredential())->replaceTotp($userId, Tiger_Crypto::encrypt($secret), $hashes);
        return [$secret, $recovery];
    }

    #[Test]
    public function a_confirmed_totp_factor_turns_login_into_a_second_step(): void
    {
        [$uid, $email] = $this->makeUserWithPassword('two factor pw 12');
        $this->enrollTotp($uid);

        $result = $this->auth->login($email, 'two factor pw 12');

        $this->assertSame(Tiger_Service_Authentication::TWOFA_REQUIRED, $result, 'a correct password with 2FA yields the TWOFA_REQUIRED sentinel');
        $this->assertTrue($this->auth->isTwoFactorPending(), 'a pending 2FA challenge is stashed for this session');
        $this->assertFalse($this->auth->isAuthenticated(), 'NO session is established on the password step alone');
    }

    #[Test]
    public function a_live_totp_code_completes_the_second_step(): void
    {
        [$uid, $email] = $this->makeUserWithPassword('totp verify pw 1');
        [$secret] = $this->enrollTotp($uid);

        $this->assertSame(Tiger_Service_Authentication::TWOFA_REQUIRED, $this->auth->login($email, 'totp verify pw 1'));

        $code     = Tiger_Auth_Totp::codeAt($secret, intdiv(time(), 30));   // the current authenticator code
        $identity = $this->auth->verifyTwoFactor($code);

        $this->assertIsObject($identity, 'a valid TOTP code finishes the login');
        $this->assertSame($uid, $identity->user_id);
        $this->assertTrue($this->auth->isAuthenticated(), 'the session is now established');
        $this->assertFalse($this->auth->isTwoFactorPending(), 'the pending challenge is cleared on success');
    }

    #[Test]
    public function a_recovery_code_also_completes_the_second_step(): void
    {
        [$uid, $email] = $this->makeUserWithPassword('recovery pw 1234');
        [, $recovery] = $this->enrollTotp($uid);

        $this->assertSame(Tiger_Service_Authentication::TWOFA_REQUIRED, $this->auth->login($email, 'recovery pw 1234'));

        // Present the recovery code with the punctuation a UI shows — normalization must still match.
        $identity = $this->auth->verifyTwoFactor('AAAA-11111');

        $this->assertIsObject($identity, 'a single-use recovery code completes 2FA');
        $this->assertSame($uid, $identity->user_id);
        $this->assertTrue($this->auth->isAuthenticated());
    }

    #[Test]
    public function a_bad_2fa_code_keeps_the_pending_challenge_and_too_many_abandon_it(): void
    {
        [$uid, $email] = $this->makeUserWithPassword('bad code pw 1234');
        $this->enrollTotp($uid);
        $this->auth->login($email, 'bad code pw 1234');

        $this->assertFalse($this->auth->verifyTwoFactor('000000'), 'a wrong code fails');
        $this->assertTrue($this->auth->isTwoFactorPending(), 'a wrong code keeps the pending challenge so the user can retry');

        // Exhaust the remaining guesses; once over the cap the challenge is abandoned.
        for ($i = 0; $i <= Tiger_Service_Authentication::TWOFA_MAX_ATTEMPTS; $i++) {
            $this->auth->verifyTwoFactor('000000');
        }
        $this->assertFalse($this->auth->isTwoFactorPending(), 'too many guesses abandon the pending challenge');
        $this->assertFalse($this->auth->isAuthenticated(), 'and no session was ever established');
    }

    // ----- session lifecycle, lock, return-to, useOrg ------------------------

    #[Test]
    public function logout_clears_the_identity_and_the_actor(): void
    {
        [, $email] = $this->makeUserWithPassword('logout me now 12');
        $this->auth->login($email, 'logout me now 12');
        $this->assertTrue($this->auth->isAuthenticated());

        $this->auth->logout();

        $this->assertFalse($this->auth->isAuthenticated(), 'logout reverts to guest');
        $this->assertNull($this->auth->getIdentity());
        $this->assertNull(Tiger_Model_Table::actor(), 'the request actor is cleared on logout');
    }

    #[Test]
    public function lock_holds_the_session_and_unlock_requires_the_right_password(): void
    {
        [, $email] = $this->makeUserWithPassword('lock screen pw 12');
        $this->auth->login($email, 'lock screen pw 12');

        $this->auth->lock();
        $this->assertTrue($this->auth->isLocked(), 'the screen is locked while identity stays valid');

        $this->assertFalse($this->auth->unlock('the wrong password'), 'a wrong password does not unlock');
        $this->assertTrue($this->auth->isLocked(), 'still locked after a bad attempt');

        $this->assertTrue($this->auth->unlock('lock screen pw 12'), 'the right password unlocks');
        $this->assertFalse($this->auth->isLocked(), 'the screen is unlocked');
        $this->assertTrue($this->auth->isAuthenticated(), 'the identity was never dropped — a lock is not a logout');
    }

    #[Test]
    public function set_and_take_return_to_round_trips_and_refuses_unsafe_paths(): void
    {
        $this->auth->setReturnTo('/dashboard/reports');
        $this->assertSame('/dashboard/reports', $this->auth->takeReturnTo(), 'a local path round-trips');
        $this->assertSame('', $this->auth->takeReturnTo(), 'and is cleared after being taken (single read)');

        // Unsafe / loop-inducing targets are ignored (nothing stored).
        $this->auth->setReturnTo('//evil.example/phish');   // protocol-relative
        $this->assertSame('', $this->auth->takeReturnTo(), 'a protocol-relative path is refused');
        $this->auth->setReturnTo('/auth/login');            // an auth page (loop guard)
        $this->assertSame('', $this->auth->takeReturnTo(), 'an /auth/ path is refused');
        $this->auth->setReturnTo('relative/no/slash');      // not root-local
        $this->assertSame('', $this->auth->takeReturnTo(), 'a non-local path is refused');
    }

    #[Test]
    public function use_org_switches_the_role_for_a_member_and_denies_a_non_member(): void
    {
        [$uid, $email] = $this->makeUserWithPassword('multi org pw 123');
        $orgA = $this->makeOrgMembership($uid, 'admin');
        $orgB = $this->makeOrgMembership($uid, 'viewer');
        // A third org the user is NOT a member of.
        $orgC = (new Tiger_Model_Org())->insert(['name' => 'Outside', 'slug' => 'org-' . bin2hex(random_bytes(6))]);

        $this->auth->login($email, 'multi org pw 123');

        $switched = $this->auth->useOrg($orgB);
        $this->assertIsObject($switched, 'switching to another membership succeeds');
        $this->assertSame($orgB, $switched->org_id);
        $this->assertSame('viewer', $switched->role, 'the role is re-resolved for the target org');
        $this->assertSame('viewer', $this->auth->getIdentity()->role, 'the switched identity is written back to the session');

        $this->assertFalse($this->auth->useOrg($orgC), 'switching into an org you are not a member of is denied');
        // Sanity: orgA was the seed for coverage of the multi-membership case.
        $this->assertNotSame($orgA, $orgB);
    }

    #[Test]
    public function two_factor_status_reflects_a_confirmed_factor(): void
    {
        [$uid, $email] = $this->makeUserWithPassword('status check pw 1');
        $this->auth->login($email, 'status check pw 1');
        $this->assertFalse($this->auth->getTwoFactorStatus()['enabled'], 'no factor yet => 2FA reported off');

        $this->enrollTotp($uid);
        $status = $this->auth->getTwoFactorStatus();
        $this->assertTrue($status['enabled'], 'a confirmed TOTP factor reports enabled');
        $this->assertSame(2, $status['recovery'], 'the remaining recovery-code count is reported');
        $this->assertTrue($status['available'], '2FA is available when crypto is configured');
    }
}
