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
 * Tiger_Policy_Password — the configurable, per-org password policy. It reads the RESOLVED
 * `tiger.password.*` config, so these tests drive it by seeding that config in the registry, then
 * assert the three levers that matter: min-length, optional complexity, and reuse-prevention. The
 * sharp one is reuse across the PEPPER boundary: a password stored before a pepper was added must
 * still be caught as reused after the pepper is turned on (the policy verifies over both the
 * peppered and the legacy-raw schemes), covering the current hash AND the retired-hash archive.
 */
#[CoversClass(Tiger_Policy_Password::class)]
final class PasswordPolicyTest extends IntegrationTestCase
{
    private const PEP_A = 'cGVwcGVyLUEtMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDA=';

    private Tiger_Policy_Password $policy;
    private Tiger_Model_UserCredential $cred;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new Tiger_Policy_Password();
        $this->cred   = new Tiger_Model_UserCredential();
        $this->configure([]);   // defaults, no pepper
    }

    /** Seed tiger.password.* (and optionally a pepper) into the resolved config the policy reads. */
    private function configure(array $password, ?string $pepper = null): void
    {
        $tiger = ['password' => $password];
        if ($pepper !== null) {
            $tiger['security'] = ['pepper' => $pepper];
        }
        Zend_Registry::set('Zend_Config', new Zend_Config(['tiger' => $tiger], true));
    }

    private function makeUser(): string
    {
        return (new Tiger_Model_User())->insert(['email' => 'pol-' . bin2hex(random_bytes(8)) . '@example.test']);
    }

    #[Test]
    public function min_length_is_enforced(): void
    {
        $this->configure(['min_length' => 10]);
        $this->assertContains('password.too_short', $this->policy->validate('short'), 'a sub-min password is rejected');
        $this->assertNotContains('password.too_short', $this->policy->validate('longenoughpassword'), 'a long password passes length');
        $this->assertTrue($this->policy->isValid('longenoughpassword'), 'and isValid agrees when nothing else applies');
    }

    #[Test]
    public function complexity_is_enforced_only_when_enabled(): void
    {
        $this->configure(['min_length' => 4, 'require_complexity' => 0]);
        $this->assertNotContains('password.needs_complexity', $this->policy->validate('alllowercase'), 'complexity off: a simple password passes');

        $this->configure(['min_length' => 4, 'require_complexity' => 1]);
        $this->assertContains('password.needs_complexity', $this->policy->validate('alllowercase'), 'complexity on: a simple password is rejected');
        $this->assertNotContains('password.needs_complexity', $this->policy->validate('Aa1!aaaa'), 'a mixed-class password satisfies complexity');
    }

    #[Test]
    public function reuse_is_caught_against_the_current_and_retired_hashes(): void
    {
        $this->configure(['min_length' => 4, 'history' => 5], self::PEP_A);
        $user = $this->makeUser();

        // First password → current; second → archives the first into history, current becomes the second.
        $this->cred->setPassword($user, 'firstpass1');
        $this->cred->setPassword($user, 'secondpass2');

        $this->assertContains('password.reused', $this->policy->validate('secondpass2', $user), 'the CURRENT password is reuse');
        $this->assertContains('password.reused', $this->policy->validate('firstpass1', $user), 'a RETIRED (archived) password is reuse');
        $this->assertNotContains('password.reused', $this->policy->validate('brandnew3', $user), 'an unused password is not reuse');
        // With no userId, reuse-prevention is not engaged at all.
        $this->assertSame([], $this->policy->validate('secondpass2'), 'without a user, reuse is not checked');
    }

    #[Test]
    public function reuse_is_caught_across_the_pepper_boundary(): void
    {
        // Store the passwords with NO pepper (legacy hashes), then turn the pepper ON.
        $this->configure(['min_length' => 4, 'history' => 5]);   // no pepper
        $user = $this->makeUser();
        $this->cred->setPassword($user, 'legacyfirst1');
        $this->cred->setPassword($user, 'legacysecond2');       // archives legacyfirst1

        $this->configure(['min_length' => 4, 'history' => 5], self::PEP_A);   // pepper now ON
        $this->assertContains('password.reused', $this->policy->validate('legacysecond2', $user), 'a pre-pepper current hash is still caught as reuse');
        $this->assertContains('password.reused', $this->policy->validate('legacyfirst1', $user), 'a pre-pepper RETIRED hash is still caught after the pepper is added');
    }
}
