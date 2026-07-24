<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Crypto;
use Tiger_Model_PasswordHistory;
use Tiger_Model_User;
use Tiger_Model_UserCredential;
use Zend_Config;
use Zend_Registry;

/**
 * Tiger_Model_UserCredential, part 2 — the factor-management surface the login flow leans on
 * (UserCredentialTest pins password verify / PAT / recovery / lockout-count / TOTP-at-rest). Here:
 *   - the SMS factor lifecycle: add unverified → markVerified → resolve by identifier (only VERIFIED
 *     factors authenticate — an unconfirmed phone can never log anyone in),
 *   - setPassword's REPLACE path archives the outgoing hash into password_history (reuse-prevention),
 *   - the lockout read (isLockedOut) + passwordCredential,
 *   - the TOTP admin reads (hasActiveTotp / recoveryCount / removeTotp) and the key-rotation rekey.
 *
 * Crypto/pepper are process-global config; setUp seeds a TEST key + pepper (never the dev secrets).
 */
#[CoversClass(Tiger_Model_UserCredential::class)]
final class UserCredentialFactorsTest extends IntegrationTestCase
{
    private const KEY = 'ERERERERERERERERERERERERERERERERERERERERERE=';   // 32 × 0x11, base64
    private const PEP = 'cGVwcGVyLUEtMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDA=';

    private Tiger_Model_UserCredential $cred;

    protected function setUp(): void
    {
        parent::setUp();
        Zend_Registry::set('Zend_Config', new Zend_Config([
            'tiger' => ['crypto' => ['key' => self::KEY], 'security' => ['pepper' => self::PEP]],
        ], true));
        $this->cred = new Tiger_Model_UserCredential();
    }

    private function makeUser(): string
    {
        return (new Tiger_Model_User())->insert(['email' => 'fac-' . bin2hex(random_bytes(8)) . '@example.test']);
    }

    #[Test]
    public function an_sms_factor_is_unverified_until_confirmed_and_only_then_resolves(): void
    {
        $user = $this->makeUser();
        $id   = $this->cred->addSms($user, '+15551230000');

        $row = $this->cred->factor($user, Tiger_Model_UserCredential::TYPE_SMS, '+15551230000');
        $this->assertNotNull($row, 'the factor is stored, keyed by its identifier');
        $this->assertNull($row->verified_at, 'a new SMS factor starts UNVERIFIED');

        // An unverified phone must not resolve for "log in by phone".
        $this->assertNull(
            $this->cred->findVerifiedByIdentifier(Tiger_Model_UserCredential::TYPE_SMS, '+15551230000'),
            'an unconfirmed factor cannot authenticate'
        );

        $this->cred->markVerified($id);
        $resolved = $this->cred->findVerifiedByIdentifier(Tiger_Model_UserCredential::TYPE_SMS, '+15551230000');
        $this->assertNotNull($resolved, 'once confirmed it resolves by identifier');
        $this->assertSame($user, $resolved->user_id);
    }

    #[Test]
    public function factors_for_lists_all_of_a_users_factors(): void
    {
        $user = $this->makeUser();
        $this->cred->setPassword($user, 'a strong secret here');
        $this->cred->addSms($user, '+15551239999');

        $types = [];
        foreach ($this->cred->factorsFor($user) as $f) { $types[] = $f->type; }
        sort($types);
        $this->assertSame([Tiger_Model_UserCredential::TYPE_PASSWORD, Tiger_Model_UserCredential::TYPE_SMS], $types);
    }

    #[Test]
    public function password_credential_returns_the_row_and_touch_stamps_last_used(): void
    {
        $user = $this->makeUser();
        $id   = $this->cred->setPassword($user, 'initial secret value');

        $cred = $this->cred->passwordCredential($user);
        $this->assertNotNull($cred);
        $this->assertSame($id, $cred->credential_id);
        $this->assertSame(Tiger_Model_UserCredential::TYPE_PASSWORD, $cred->type);

        $this->assertNull($cred->last_used_at, 'not used yet');
        $this->cred->touch($id);
        $this->assertNotNull($this->cred->findById($id)->last_used_at, 'touch stamps last_used_at');
    }

    #[Test]
    public function replacing_a_password_archives_the_outgoing_hash_into_history(): void
    {
        $user = $this->makeUser();
        $this->cred->setPassword($user, 'first password one');
        // Replacing the password should push the OLD hash into password_history (reuse-prevention).
        $this->cred->setPassword($user, 'second password two');

        $history = (new Tiger_Model_PasswordHistory())->recentForUser($user, 10);
        $this->assertGreaterThanOrEqual(1, count($history), 'the outgoing hash is archived on replace');
        // Still exactly one live password credential (idempotent replace, not a second row).
        $passwords = 0;
        foreach ($this->cred->factorsFor($user) as $f) {
            if ($f->type === Tiger_Model_UserCredential::TYPE_PASSWORD) { $passwords++; }
        }
        $this->assertSame(1, $passwords, 'replace keeps one password row, not two');
        $this->assertTrue($this->cred->verifyPassword($user, 'second password two'), 'the new password verifies');
    }

    #[Test]
    public function is_locked_out_reflects_the_lockout_window(): void
    {
        $user = $this->makeUser();
        $id   = $this->cred->setPassword($user, 'lockme downplease');

        $this->assertFalse($this->cred->isLockedOut($this->cred->passwordCredential($user)), 'a fresh credential is not locked');
        $this->assertFalse($this->cred->isLockedOut(null), 'a null credential is not locked');

        // Walk failures to the cap; the credential should then read as locked.
        for ($i = 0; $i < Tiger_Model_UserCredential::MAX_FAILURES; $i++) {
            $this->cred->recordFailure($id);
        }
        $this->assertTrue($this->cred->isLockedOut($this->cred->passwordCredential($user)), 'the cap trips the lockout');

        // A future locked_until reads locked; a past one does not.
        $this->cred->update(['locked_until' => date('Y-m-d H:i:s', time() - 60)],
            $this->db->quoteInto('credential_id = ?', $id));
        $this->assertFalse($this->cred->isLockedOut($this->cred->passwordCredential($user)), 'an expired lockout is not locked');
    }

    #[Test]
    public function totp_admin_reads_and_removal(): void
    {
        $user = $this->makeUser();
        $this->assertFalse($this->cred->hasActiveTotp($user), 'no TOTP to start');
        $this->assertSame(0, $this->cred->recoveryCount($user));

        $this->cred->replaceTotp($user, Tiger_Crypto::encrypt('JBSWY3DPEHPK3PXP'), ['h1', 'h2', 'h3']);
        $this->assertTrue($this->cred->hasActiveTotp($user), 'TOTP is now active');
        $this->assertSame(3, $this->cred->recoveryCount($user), 'three recovery codes were stored');

        $this->cred->removeTotp($user);
        $this->assertFalse($this->cred->hasActiveTotp($user), 'removeTotp drops the secret');
        $this->assertSame(0, $this->cred->recoveryCount($user), 'and every recovery code');
    }

    #[Test]
    public function rekey_totp_secrets_reencrypts_each_secret_losslessly(): void
    {
        $userA = $this->makeUser();
        $userB = $this->makeUser();
        $this->cred->replaceTotp($userA, Tiger_Crypto::encrypt('JBSWY3DPEHPK3PXP'), []);
        $this->cred->replaceTotp($userB, Tiger_Crypto::encrypt('KRSXG5CTMVRXEZLU'), []);

        $before = (string) $this->cred->activeTotp($userA)->secret;

        $result = $this->cred->rekeyTotpSecrets();
        $this->assertSame(2, $result['rekeyed'], 'both TOTP secrets were re-encrypted');
        $this->assertSame(0, $result['failed']);

        // Re-encryption changes the ciphertext (fresh nonce) but not the plaintext.
        $after = (string) $this->cred->activeTotp($userA)->secret;
        $this->assertSame('JBSWY3DPEHPK3PXP', Tiger_Crypto::decrypt($after), 'the secret still decrypts to the original');
        $this->assertNotSame($before, $after, 'the stored ciphertext was refreshed under the current key');
    }
}
