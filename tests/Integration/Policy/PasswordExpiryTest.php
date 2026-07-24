<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Policy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_User;
use Tiger_Model_UserCredential;
use Tiger_Policy_Password;
use Zend_Config;
use Zend_Registry;

/**
 * Tiger_Policy_Password::isExpired() — the max-age (forced-rotation) lever PasswordPolicyTest leaves
 * uncovered. Off by default (NIST-informed), so the common path is "never expires"; when an org turns
 * it on, a password whose credential was verified longer ago than the window is expired. Drives the
 * policy through the resolved `tiger.password.max_age_days` config against a real credential row,
 * ageing `verified_at` to cross the boundary.
 */
#[CoversClass(Tiger_Policy_Password::class)]
final class PasswordExpiryTest extends IntegrationTestCase
{
    private Tiger_Policy_Password $policy;
    private Tiger_Model_UserCredential $cred;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new Tiger_Policy_Password();
        $this->cred   = new Tiger_Model_UserCredential();
    }

    private function configure(array $password): void
    {
        Zend_Registry::set('Zend_Config', new Zend_Config(['tiger' => ['password' => $password]], true));
    }

    private function makeUserWithPassword(): string
    {
        $userId = (new Tiger_Model_User())->insert(['email' => 'exp-' . bin2hex(random_bytes(8)) . '@example.test']);
        $this->cred->setPassword($userId, 'initialpass1');
        return $userId;
    }

    /** Move the user's password credential verified_at back by $days days. */
    private function ageCredential(string $userId, int $days): void
    {
        $row = $this->cred->passwordCredential($userId);
        $this->cred->update(
            ['verified_at' => date('Y-m-d H:i:s', time() - ($days * 86400))],
            $this->db->quoteInto('credential_id = ?', $row->credential_id)
        );
    }

    #[Test]
    public function expiry_is_off_by_default(): void
    {
        $this->configure([]);   // no max_age_days
        $user = $this->makeUserWithPassword();
        $this->ageCredential($user, 3650);   // 10 years old — but expiry is off
        $this->assertFalse($this->policy->isExpired($user), 'with rotation off, a password never expires');
    }

    #[Test]
    public function a_fresh_password_is_not_expired_when_a_max_age_is_set(): void
    {
        $this->configure(['max_age_days' => 90]);
        $user = $this->makeUserWithPassword();   // verified just now
        $this->assertFalse($this->policy->isExpired($user), 'a just-set password is inside the window');
    }

    #[Test]
    public function a_password_older_than_the_window_is_expired(): void
    {
        $this->configure(['max_age_days' => 90]);
        $user = $this->makeUserWithPassword();
        $this->ageCredential($user, 120);   // older than 90 days
        $this->assertTrue($this->policy->isExpired($user));
    }

    #[Test]
    public function a_user_without_a_password_credential_is_never_expired(): void
    {
        $this->configure(['max_age_days' => 90]);
        $userId = (new Tiger_Model_User())->insert(['email' => 'nopw-' . bin2hex(random_bytes(8)) . '@example.test']);
        $this->assertFalse($this->policy->isExpired($userId), 'no credential → no expiry');
    }
}
