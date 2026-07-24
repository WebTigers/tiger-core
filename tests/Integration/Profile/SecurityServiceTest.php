<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Profile;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Profile_Service_Security;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_User;
use Tiger_Model_UserCredential;
use Zend_Registry;

/**
 * Profile_Service_Security — the self-service change-password tab (/api).
 *
 * Wave-4 coverage: the self-scope gate (a guest with no identity is refused), the happy path (a
 * logged-in user sets a new password with NO current-password step — the session is the authority —
 * creating a `password` credential row), and the form guards (confirm must match; the policy
 * validator rejects a weak password). History archival lives in Tiger_Model_UserCredential.
 */
#[CoversClass(Profile_Service_Security::class)]
final class SecurityServiceTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Zend_Registry::set('tiger.auth.stateless', true);
    }

    protected function tearDown(): void
    {
        $reg = Zend_Registry::getInstance();
        if ($reg->offsetExists('tiger.auth.stateless')) { $reg->offsetUnset('tiger.auth.stateless'); }
        parent::tearDown();
    }

    private function call(string $action, array $params = []): object
    {
        return (new Profile_Service_Security(['action' => $action] + $params))->getResponse();
    }

    #[Test]
    public function a_guest_with_no_identity_is_refused(): void
    {
        $res = $this->call('changePassword', ['new_password' => 'S3cure-P@ssw0rd-9x', 'confirm_password' => 'S3cure-P@ssw0rd-9x']);
        $this->assertSame(0, (int) $res->result);
        $this->assertStringContainsString('not_allowed', json_encode($res->messages));
    }

    #[Test]
    public function a_logged_in_user_sets_a_new_password_creating_a_credential_row(): void
    {
        $id = (new Tiger_Model_User())->insert(['email' => 'pw@w4test.com', 'status' => 'active']);
        $this->login($id, 'org-test', 'user');

        $res = $this->call('changePassword', [
            'new_password'     => 'S3cure-P@ssw0rd-9x',
            'confirm_password' => 'S3cure-P@ssw0rd-9x',
        ]);
        $this->assertSame(1, (int) $res->result, 'a valid new password is accepted');
        $this->assertStringContainsString('password_changed', json_encode($res->messages));

        $count = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM user_credential WHERE user_id = ? AND type = 'password' AND deleted = 0",
            [$id]
        );
        $this->assertSame(1, $count, 'a password credential row exists');

        // And the stored hash verifies the plaintext (round-trip through the model).
        $cred = new Tiger_Model_UserCredential();
        $this->assertTrue($cred->verifyPassword($id, 'S3cure-P@ssw0rd-9x'), 'the new password verifies');
    }

    #[Test]
    public function a_mismatched_confirmation_returns_form_errors(): void
    {
        $id = (new Tiger_Model_User())->insert(['email' => 'mm@w4test.com', 'status' => 'active']);
        $this->login($id, 'org-test', 'user');

        $res = $this->call('changePassword', [
            'new_password'     => 'S3cure-P@ssw0rd-9x',
            'confirm_password' => 'Different-P@ss-1234',
        ]);
        $this->assertSame(0, (int) $res->result, 'a mismatch is rejected');
        $this->assertNotNull($res->form);
        $this->assertArrayHasKey('confirm_password', $res->form);

        $count = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM user_credential WHERE user_id = ? AND type = 'password'",
            [$id]
        );
        $this->assertSame(0, $count, 'no credential written on a form error');
    }

    #[Test]
    public function a_weak_password_is_rejected_by_the_policy_validator(): void
    {
        $id = (new Tiger_Model_User())->insert(['email' => 'weak@w4test.com', 'status' => 'active']);
        $this->login($id, 'org-test', 'user');

        $res = $this->call('changePassword', ['new_password' => 'abc', 'confirm_password' => 'abc']);
        $this->assertSame(0, (int) $res->result, 'a weak password is rejected');
        $this->assertNotNull($res->form);
        $this->assertArrayHasKey('new_password', $res->form);
    }
}
