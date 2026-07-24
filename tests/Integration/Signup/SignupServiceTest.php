<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Signup;

use PHPUnit\Framework\Attributes\Test;
use Signup_Service_Signup;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_AuthChallenge;
use Tiger_Model_Option;
use Tiger_Model_ResponseObject;
use Tiger_Model_User;
use Zend_Config;
use Zend_Registry;

// `Signup_Service_Signup` (+ its Form/Models) resolve via the harness module autoloader
// (tests/bootstrap.php) — no require_once.

/**
 * Signup_Service_Signup — the public guest signup path (/api service=signup, action=create), plus
 * the direct-call email verification (`verifyEmail`, invoked by Signup_IndexController, not the /api
 * action dispatcher). Characterizes the REAL shipped behavior end to end: a valid guest submission
 * builds the whole tenant graph (org → user(pending) → org_user(role=user) → password credential →
 * address/contact joins → an email_verify challenge) and returns the `signup.check_email` envelope;
 * the reference form's validators (required, format, password policy, InArray, DB-uniqueness) reject
 * bad input with keyed form errors and write NOTHING; the admin "public signup off" flag refuses; and
 * the ACL posture (guest is allowed to dispatch) matches modules/signup/configs/acl.ini.
 *
 * TWO harness accommodations, both faithful to production semantics (see WAVE3-FINDINGS-signup.md):
 *   1. `/api` calls carry a CSRF token; the browser has a guest session, a CLI test does not. We set
 *      the documented `tiger.auth.stateless` registry flag (Tiger_Form skips CSRF for stateless
 *      bearer callers) so the form's REAL field validators run instead of every dispatch dying at the
 *      CSRF gate. One test deliberately leaves it off to characterize that gate.
 *   2. `create()` opens its own DB transaction (`_transaction`), which cannot nest inside the base's
 *      per-test transaction (MySQL/PDO throws "already an active transaction"). The happy-path tests
 *      therefore END the base transaction first, let the service COMMIT for real, assert, and purge
 *      the committed rows in tearDown. Validation/flag/ACL tests never reach `_transaction`, so they
 *      keep the base's clean rollback isolation.
 */
final class SignupServiceTest extends IntegrationTestCase
{
    /** user_ids the happy-path tests committed for real (outside the base txn) — purged in tearDown. */
    private array $_committed = [];

    /** whatever Zend_Config was registered before this test (restored in tearDown). */
    private ?Zend_Config $_priorConfig = null;

    protected function setUp(): void
    {
        parent::setUp();
        // Pin a known password policy so the policy assertions are deterministic regardless of what
        // an earlier test in the suite left in the Zend_Config registry (min 8, complexity off).
        $this->_priorConfig = Zend_Registry::isRegistered('Zend_Config') ? Zend_Registry::get('Zend_Config') : null;
        Zend_Registry::set('Zend_Config', new Zend_Config([
            'tiger' => ['password' => ['min_length' => 8, 'require_complexity' => 0, 'history' => 0, 'max_age_days' => 0]],
        ]));
    }

    protected function tearDown(): void
    {
        // Undo any REAL rows a committing (happy-path) test wrote outside the base transaction.
        foreach ($this->_committed as $userId) {
            $this->purgeTenant($userId);
        }
        $this->_committed = [];
        Zend_Registry::set('tiger.auth.stateless', false);
        Zend_Registry::set('Zend_Config', $this->_priorConfig ?? new Zend_Config([]));
        parent::tearDown();
    }

    // ---- helpers ------------------------------------------------------------

    /** A complete, valid signup payload with a per-call unique tag; $overrides replace any field. */
    private function validParams(array $overrides = []): array
    {
        $tag = bin2hex(random_bytes(6));
        return array_merge([
            'action'     => 'create',
            'first_name' => 'Jane',
            'last_name'  => 'Doe',
            'company'    => 'Acme ' . $tag,
            'username'   => 'jane' . $tag,
            'password'   => 'SuperSecret123',
            'email'      => 'jane' . $tag . '@example.test',
            'street'     => '123 Main St',
            'city'       => 'Springfield',
            'region'     => 'IL',
            'postal'     => '62704',
            'country'    => 'US',
            'phone_type' => 'mobile',
            'phone'      => '+1 555 123 4567',
        ], $overrides);
    }

    /** Dispatch create with CSRF skipped (stateless) — for validation/flag tests that never commit. */
    private function dispatch(array $params): Tiger_Model_ResponseObject
    {
        Zend_Registry::set('tiger.auth.stateless', true);
        return (new Signup_Service_Signup($params))->getResponse();
    }

    /** Dispatch create for REAL: skip CSRF, end the base txn so `_transaction()` can begin+commit. */
    private function dispatchCommitting(array $params): Tiger_Model_ResponseObject
    {
        Zend_Registry::set('tiger.auth.stateless', true);
        try { $this->db->rollBack(); } catch (\Throwable $e) { /* base txn already closed */ }
        $res = (new Signup_Service_Signup($params))->getResponse();
        $userId = $this->db->fetchOne('SELECT user_id FROM user WHERE email = ?', [strtolower($params['email'] ?? '')]);
        if ($userId) { $this->_committed[] = $userId; }   // register for cleanup regardless of assertions
        return $res;
    }

    /** Hard-delete a signup's whole tenant graph (committed rows) so the shared DB stays clean. */
    private function purgeTenant(string $userId): void
    {
        $orgId      = $this->db->fetchOne('SELECT org_id FROM org_user WHERE user_id = ?', [$userId]);
        $addressIds = $this->db->fetchCol('SELECT address_id FROM user_address WHERE user_id = ?', [$userId]);
        $contactIds = $this->db->fetchCol('SELECT contact_id FROM user_contact WHERE user_id = ?', [$userId]);
        foreach (['org_user', 'user_credential', 'user_address', 'user_contact', 'auth_challenge'] as $t) {
            $this->db->query("DELETE FROM $t WHERE user_id = ?", [$userId]);
        }
        $this->db->query('DELETE FROM user WHERE user_id = ?', [$userId]);
        if ($orgId) {
            foreach (['org_address', 'org_contact', 'org_user'] as $t) {
                $this->db->query("DELETE FROM $t WHERE org_id = ?", [$orgId]);
            }
            $this->db->query('DELETE FROM org WHERE org_id = ?', [$orgId]);
        }
        foreach ($addressIds as $aid) { $this->db->query('DELETE FROM address WHERE address_id = ?', [$aid]); }
        foreach ($contactIds as $cid) { $this->db->query('DELETE FROM contact WHERE contact_id = ?', [$cid]); }
    }

    private function messagesJson(Tiger_Model_ResponseObject $res): string
    {
        return json_encode($res->messages ?? []);
    }

    // ---- happy path ---------------------------------------------------------

    #[Test]
    public function a_valid_guest_signup_builds_the_whole_tenant_graph(): void
    {
        $p   = $this->validParams();
        $res = $this->dispatchCommitting($p);

        // Envelope: success + the "check your email" message + the sent/email payload.
        $this->assertSame(1, (int) $res->result, 'a valid signup succeeds');
        $this->assertStringContainsString('signup.check_email', $this->messagesJson($res), 'the check-email message is returned');
        $this->assertSame(1, (int) $res->data['sent'], 'the payload reports the verification was sent');
        $this->assertSame(strtolower($p['email']), $res->data['email'], 'the payload echoes the (lowercased) email');

        // user — created PENDING (guest until the email is verified), not active.
        $user = $this->db->fetchRow('SELECT * FROM user WHERE email = ?', [strtolower($p['email'])]);
        $this->assertNotEmpty($user, 'the user row exists');
        $this->assertSame('pending', $user['status'], 'the account is pending until email verification');
        $this->assertSame($p['username'], $user['username'], 'the chosen username is stored');

        // org — name kept, slug derived from the company name.
        $ou = $this->db->fetchRow('SELECT * FROM org_user WHERE user_id = ?', [$user['user_id']]);
        $this->assertNotEmpty($ou, 'a membership row links the user to an org');
        $this->assertSame('user', $ou['role'], 'the founder joins their org as role=user');
        $org = $this->db->fetchRow('SELECT * FROM org WHERE org_id = ?', [$ou['org_id']]);
        $this->assertSame($p['company'], $org['name'], 'the company name becomes the org name');
        $this->assertSame(\Tiger_Install::slugify($p['company']), $org['slug'], 'the slug is derived from the company');

        // credential — a verified password factor (verified_at stamped at set).
        $cred = $this->db->fetchRow('SELECT * FROM user_credential WHERE user_id = ?', [$user['user_id']]);
        $this->assertSame('password', $cred['type'], 'a password credential is stored');
        $this->assertNotNull($cred['verified_at'], 'the password factor is marked verified');
        $this->assertNotSame($p['password'], $cred['secret'], 'the password is hashed, never stored in the clear');

        // address + contact — each linked to BOTH the org and the user.
        $this->assertNotEmpty($this->db->fetchOne('SELECT 1 FROM user_address WHERE user_id = ?', [$user['user_id']]), 'user_address join written');
        $this->assertNotEmpty($this->db->fetchOne('SELECT 1 FROM org_address WHERE org_id = ?', [$ou['org_id']]), 'org_address join written');
        $this->assertNotEmpty($this->db->fetchOne('SELECT 1 FROM user_contact WHERE user_id = ?', [$user['user_id']]), 'user_contact join written');
        $this->assertNotEmpty($this->db->fetchOne('SELECT 1 FROM org_contact WHERE org_id = ?', [$ou['org_id']]), 'org_contact join written');

        // an email_verify challenge was issued for the verification link.
        $this->assertSame('email_verify', $this->db->fetchOne('SELECT type FROM auth_challenge WHERE user_id = ?', [$user['user_id']]), 'an email_verify challenge is issued');
    }

    #[Test]
    public function a_self_signup_stamps_no_actor_and_leaves_locale_timezone_unset(): void
    {
        // No login() → a true anonymous guest: there is no authenticated actor, so created_by is NULL
        // (system/genesis), and signup collects no locale/timezone, so those carve-out columns stay unset.
        $p    = $this->validParams();
        $res  = $this->dispatchCommitting($p);
        $this->assertSame(1, (int) $res->result);

        $user = $this->db->fetchRow('SELECT created_by, locale, timezone FROM user WHERE email = ?', [strtolower($p['email'])]);
        $this->assertNull($user['created_by'], 'a self-signup has no actor to stamp — created_by is NULL');
        $this->assertNull($user['locale'], 'signup does not set the user locale');
        $this->assertNull($user['timezone'], 'signup does not set the user timezone');

        $org = $this->db->fetchOne('SELECT created_by FROM org WHERE org_id = (SELECT org_id FROM org_user WHERE user_id = (SELECT user_id FROM user WHERE email = ?))', [strtolower($p['email'])]);
        $this->assertNull($org, 'the org row is likewise unattributed (created_by NULL)');
    }

    #[Test]
    public function the_email_is_normalized_to_lowercase(): void
    {
        $tag = bin2hex(random_bytes(6));
        $p   = $this->validParams(['email' => 'MixedCase' . $tag . '@Example.TEST', 'username' => 'mc' . $tag]);
        $res = $this->dispatchCommitting($p);

        $this->assertSame(1, (int) $res->result, 'a valid signup with an upper-case email succeeds');
        $this->assertSame('mixedcase' . $tag . '@example.test', $res->data['email'], 'the returned email is lowercased');
        $this->assertNotEmpty(
            $this->db->fetchOne('SELECT 1 FROM user WHERE email = ?', ['mixedcase' . $tag . '@example.test']),
            'the stored email is lowercased'
        );
    }

    // ---- validation failures (reach the form, never the transaction) --------

    #[Test]
    public function a_missing_email_is_a_keyed_form_error_and_writes_nothing(): void
    {
        $p = $this->validParams();
        unset($p['email']);
        $res = $this->dispatch($p);

        $this->assertSame(0, (int) $res->result, 'a missing email fails');
        $this->assertArrayHasKey('email', (array) $res->form, 'the error is keyed to the email field');
        $this->assertStringContainsString('core.api.error.form', $this->messagesJson($res), 'the generic form-error message is returned');
        $this->assertNoTenantFor($p['username'], $p['company']);
    }

    #[Test]
    public function an_invalid_email_format_is_rejected(): void
    {
        $res = $this->dispatch($this->validParams(['email' => 'not-an-email']));

        $this->assertSame(0, (int) $res->result);
        $this->assertArrayHasKey('email', (array) $res->form, 'a malformed email is a field error');
    }

    #[Test]
    public function a_password_that_violates_policy_is_rejected(): void
    {
        // The policy floor is 8 chars (Tiger_Policy_Password default) — a 5-char password fails.
        $res = $this->dispatch($this->validParams(['password' => 'short']));

        $this->assertSame(0, (int) $res->result, 'a weak password fails');
        $this->assertArrayHasKey('password', (array) $res->form, 'the error is keyed to the password field');
    }

    #[Test]
    public function a_username_that_breaks_the_pattern_is_rejected(): void
    {
        // The username regex is /^[a-zA-Z0-9._-]{3,32}$/ — a space is illegal.
        $res = $this->dispatch($this->validParams(['username' => 'has space']));

        $this->assertSame(0, (int) $res->result);
        $this->assertArrayHasKey('username', (array) $res->form, 'an illegal username character is a field error');
    }

    #[Test]
    public function an_invalid_country_is_rejected(): void
    {
        // 'QZ' is not in Tiger_I18n_Country::codes() → the InArray validator fails.
        $res = $this->dispatch($this->validParams(['country' => 'QZ']));

        $this->assertSame(0, (int) $res->result);
        $this->assertArrayHasKey('country', (array) $res->form, 'an unknown country code is a field error');
    }

    #[Test]
    public function a_malformed_phone_is_rejected(): void
    {
        $res = $this->dispatch($this->validParams(['phone' => 'call-me']));

        $this->assertSame(0, (int) $res->result);
        $this->assertArrayHasKey('phone', (array) $res->form, 'a non-numeric phone is a field error');
    }

    // ---- DB-uniqueness (the NoRecordExists validators) ----------------------

    #[Test]
    public function a_duplicate_email_is_caught_before_any_write(): void
    {
        // Seed an existing account (inside the base txn — rolled back after the test).
        $p = $this->validParams();
        (new Tiger_Model_User())->insert(['email' => strtolower($p['email']), 'username' => 'pre' . bin2hex(random_bytes(4))]);

        $res = $this->dispatch($p);

        $this->assertSame(0, (int) $res->result, 'a taken email fails');
        $this->assertArrayHasKey('email', (array) $res->form, 'the collision is reported on the email field');
        // No second (signup-created) account, and no org, exists for this attempt.
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM user WHERE username = ?', [$p['username']]), 'the signup created no user');
        $this->assertNoTenantFor($p['username'], $p['company']);
    }

    #[Test]
    public function a_duplicate_username_is_caught_before_any_write(): void
    {
        $p = $this->validParams();
        (new Tiger_Model_User())->insert(['email' => 'other' . bin2hex(random_bytes(4)) . '@example.test', 'username' => $p['username']]);

        $res = $this->dispatch($p);

        $this->assertSame(0, (int) $res->result, 'a taken username fails');
        $this->assertArrayHasKey('username', (array) $res->form, 'the collision is reported on the username field');
        // The seed is the ONLY holder of the username — the signup added none — and no org was created.
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM user WHERE username = ?', [$p['username']]), 'only the pre-seeded user holds the username');
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM org WHERE name = ?', [$p['company']]), 'no org row was written');
    }

    // ---- the "public signup disabled" flag ----------------------------------

    #[Test]
    public function the_admin_disable_flag_refuses_signup_before_validation(): void
    {
        (new Tiger_Model_Option())->set(Tiger_Model_Option::SCOPE_GLOBAL, '', 'signup.public_disabled', '1');

        // Even a perfectly valid payload is refused — the flag is not cosmetic, and it short-circuits
        // ahead of the form, so nothing is created.
        $p   = $this->validParams();
        $res = $this->dispatch($p);

        $this->assertSame(0, (int) $res->result, 'signup is refused when disabled');
        $this->assertStringContainsString('signup.disabled', $this->messagesJson($res), 'the "turned off" message is returned');
        $this->assertNull($res->form, 'the refusal is not a form error');
        $this->assertNoTenantFor($p['username'], $p['company']);
    }

    #[Test]
    public function signup_proceeds_when_the_disable_flag_is_absent(): void
    {
        // Default state (no option row): isPublicDisabled() is false, so a valid payload passes the gate.
        $this->assertFalse(Signup_Service_Signup::isPublicDisabled(), 'public signup is enabled by default');
    }

    // ---- CSRF gate ----------------------------------------------------------

    #[Test]
    public function without_a_csrf_token_the_form_is_refused(): void
    {
        // NOT stateless: the reference form carries a CSRF hash element, and a bodyless /api call has no
        // token — the base maps that to the dedicated csrf message (not a per-field error).
        Zend_Registry::set('tiger.auth.stateless', false);
        $p   = $this->validParams();
        $res = (new Signup_Service_Signup($p))->getResponse();

        $this->assertSame(0, (int) $res->result, 'a missing CSRF token fails the call');
        $this->assertStringContainsString('core.api.error.csrf', $this->messagesJson($res), 'the CSRF-specific message fires');
        $this->assertNoTenantFor($p['username'], $p['company']);
    }

    // ---- ACL posture (guest is allowed to sign up) --------------------------

    #[Test]
    public function the_shipped_acl_allows_a_guest_to_reach_the_signup_service(): void
    {
        $this->login('anon', 'org-test', 'guest');
        $acl = Zend_Registry::get('Zend_Acl');

        $this->assertTrue($acl->has('Signup_Service_Signup'), 'the service is a governed resource (loaded from signup/acl.ini)');
        $this->assertTrue($acl->isAllowed('guest', 'Signup_Service_Signup'), 'a guest is allowed — public signup');
    }

    #[Test]
    public function a_guest_dispatch_is_not_denied_by_the_acl(): void
    {
        // The create() method carries no in-service ACL gate (the /api ServiceFactory authorizes it),
        // so a guest dispatch reaches the form — it must never come back as the not_allowed refusal.
        $this->login('anon', 'org-1', 'guest');
        $res = $this->dispatch($this->validParams(['password' => 'short']));   // fails at the FORM, not the ACL

        $this->assertStringNotContainsString('not_allowed', $this->messagesJson($res), 'a guest is not denied — it reaches the service');
        $this->assertArrayHasKey('password', (array) $res->form, 'proof the dispatch ran the form (not an ACL bounce)');
    }

    // ---- verifyEmail (direct call — the controller invokes it, not /api) ----

    #[Test]
    public function verify_email_redeems_a_valid_challenge_and_activates_the_account(): void
    {
        // Build a pending account + its email_verify challenge (inside the base txn).
        $userId = (new Tiger_Model_User())->insert(['email' => 'verify' . bin2hex(random_bytes(5)) . '@example.test', 'status' => 'pending']);
        $token  = bin2hex(random_bytes(16));
        $cid    = (new Tiger_Model_AuthChallenge())->issue($userId, 'email_verify', $token, 86400);

        $result = (new Signup_Service_Signup())->verifyEmail($cid, $token);

        $this->assertTrue($result['ok'], 'a correct token verifies');
        $this->assertSame($userId, $result['user_id'], 'the owning user is returned');
        $this->assertSame('active', $this->db->fetchOne('SELECT status FROM user WHERE user_id = ?', [$userId]), 'the account is flipped to active');
    }

    #[Test]
    public function verify_email_rejects_a_wrong_code_and_leaves_the_account_pending(): void
    {
        $userId = (new Tiger_Model_User())->insert(['email' => 'verify' . bin2hex(random_bytes(5)) . '@example.test', 'status' => 'pending']);
        $token  = bin2hex(random_bytes(16));
        $cid    = (new Tiger_Model_AuthChallenge())->issue($userId, 'email_verify', $token, 86400);

        $result = (new Signup_Service_Signup())->verifyEmail($cid, 'the-wrong-code');

        $this->assertFalse($result['ok'], 'a wrong token does not verify');
        $this->assertSame('pending', $this->db->fetchOne('SELECT status FROM user WHERE user_id = ?', [$userId]), 'the account stays pending');
    }

    #[Test]
    public function verify_email_is_safe_against_an_unknown_challenge_id(): void
    {
        $result = (new Signup_Service_Signup())->verifyEmail('not-a-real-challenge', 'whatever');
        $this->assertFalse($result['ok'], 'an unknown challenge id resolves to a clean failure, no throw');
    }

    // ---- shared assertion ---------------------------------------------------

    /** Assert a rejected signup left NO tenant rows behind (no user by username, no org by company). */
    private function assertNoTenantFor(string $username, string $company): void
    {
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM user WHERE username = ?', [$username]), 'no user row was written');
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM org WHERE name = ?', [$company]), 'no org row was written');
    }
}
