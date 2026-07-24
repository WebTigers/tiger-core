<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Crypto;
use Tiger_Model_User;
use Tiger_Model_UserCredential;
use Tiger_Security;
use Zend_Config;
use Zend_Registry;

/**
 * Tiger_Model_UserCredential — the durable auth-factor gateway (migration 0004): passwords, TOTP
 * secrets, recovery codes, personal access tokens, and the login-lockout counter. This is the model
 * that must keep a stolen credential table useless, so the tests pin the guarantees that do that:
 *
 *   - a password verifies constant-time, and adding/rotating the install PEPPER migrates each hash
 *     transparently on the owner's next verify (a no-pepper hash still verifies, then is re-hashed
 *     WITH the pepper);
 *   - a personal access token and a single-use recovery code each redeem exactly once and burn;
 *   - the lockout counter climbs on failure and resets on success;
 *   - a TOTP shared secret is ENCRYPTED at rest (the raw column is not the plaintext) yet decrypts
 *     back losslessly.
 *
 * Crypto/pepper config is process-global (`Zend_Config` in the registry); the base case does not
 * seed it, so setUp() installs a test key + pepper, and the pepper-migration test re-seeds mid-test
 * to cross the no-pepper → pepper boundary. Test secrets only — never the dev key/pepper.
 */
#[CoversClass(Tiger_Model_UserCredential::class)]
final class UserCredentialTest extends IntegrationTestCase
{
    private const KEY   = 'ERERERERERERERERERERERERERERERERERERERERERE='; // 32 × 0x11, base64
    private const PEP_A = 'cGVwcGVyLUEtMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDA='; // arbitrary valid base64

    private Tiger_Model_UserCredential $cred;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useCrypto(self::PEP_A);           // default: pepper ON, key present
        $this->cred = new Tiger_Model_UserCredential();
    }

    /** Seed the process-global crypto key + (optional) pepper the models read from the registry. */
    private function useCrypto(?string $pepper): void
    {
        $tiger = ['crypto' => ['key' => self::KEY]];
        if ($pepper !== null) {
            $tiger['security'] = ['pepper' => $pepper];
        }
        Zend_Registry::set('Zend_Config', new Zend_Config(['tiger' => $tiger], true));
    }

    private function makeUser(): string
    {
        return (new Tiger_Model_User())->insert(['email' => 'cred-' . bin2hex(random_bytes(8)) . '@example.test']);
    }

    #[Test]
    public function verify_password_accepts_the_right_one_and_rejects_the_wrong_one(): void
    {
        $user = $this->makeUser();
        $this->cred->setPassword($user, 'correct horse battery');

        $this->assertTrue($this->cred->verifyPassword($user, 'correct horse battery'), 'the right password verifies');
        $this->assertFalse($this->cred->verifyPassword($user, 'Tr0ub4dour&3'), 'a wrong password fails');
        $this->assertFalse($this->cred->verifyPassword($this->makeUser(), 'anything'), 'no credential => false');
    }

    #[Test]
    public function a_no_pepper_hash_still_verifies_and_is_rehashed_with_the_pepper(): void
    {
        // Store the password with NO pepper configured — a legacy, un-peppered bcrypt hash.
        $this->useCrypto(null);
        $user = $this->makeUser();
        $this->cred->setPassword($user, 'legacySecret99');
        $legacyHash = (string) $this->cred->passwordCredential($user)->secret;
        $this->assertTrue(password_verify('legacySecret99', $legacyHash), 'baseline: legacy hash is over the raw password');

        // Now turn the pepper ON and verify: it must still pass (legacy fallback) AND re-hash.
        $this->useCrypto(self::PEP_A);
        $this->assertTrue($this->cred->verifyPassword($user, 'legacySecret99'), 'legacy hash still verifies after adding a pepper');

        $migrated = (string) $this->cred->passwordCredential($user)->secret;
        $this->assertNotSame($legacyHash, $migrated, 'the hash was transparently re-hashed on verify');
        $this->assertFalse(password_verify('legacySecret99', $migrated), 'the new hash is no longer over the raw password');
        $this->assertTrue(
            password_verify(Tiger_Security::prehashPassword('legacySecret99'), $migrated),
            'the new hash is over the PEPPERED prehash'
        );
        $this->assertTrue($this->cred->verifyPassword($user, 'legacySecret99'), 'and it keeps verifying under the pepper');
    }

    #[Test]
    public function a_personal_access_token_verifies_once_and_a_revoked_one_never_does(): void
    {
        $user = $this->makeUser();
        $minted = $this->cred->createToken($user);

        $this->assertMatchesRegularExpression('/^tgr_[a-f0-9]{12}_[a-f0-9]{48}$/', $minted['token']);
        $this->assertSame($user, $this->cred->verifyToken($minted['token']), 'a valid token resolves its owner');
        $this->assertNull($this->cred->verifyToken('tgr_deadbeef0000_' . str_repeat('0', 48)), 'an unknown token fails');
        $this->assertNull($this->cred->verifyToken('not-a-token'), 'a malformed token fails');

        $this->cred->revokeToken($user, $minted['credential_id']);
        $this->assertNull($this->cred->verifyToken($minted['token']), 'a revoked (soft-deleted) token never verifies again');
    }

    #[Test]
    public function a_recovery_code_redeems_once_then_burns(): void
    {
        $user = $this->makeUser();
        // replaceTotp stores recovery secrets exactly as codeMatches(...,'recovery') will hash them.
        $hashes = [
            Tiger_Security::hashCode('aaaa1111', 'recovery'),
            Tiger_Security::hashCode('bbbb2222', 'recovery'),
        ];
        $this->cred->replaceTotp($user, Tiger_Crypto::encrypt('JBSWY3DPEHPK3PXP'), $hashes);
        $this->assertSame(2, $this->cred->recoveryCount($user));

        $this->assertFalse($this->cred->redeemRecoveryCode($user, 'zzzz9999'), 'a wrong recovery code fails');
        // Present it with the punctuation a UI shows — normalization must still match.
        $this->assertTrue($this->cred->redeemRecoveryCode($user, 'AAAA-1111'), 'a correct code redeems (normalized, case-insensitive)');
        $this->assertFalse($this->cred->redeemRecoveryCode($user, 'aaaa1111'), 'single-use: the same code cannot be redeemed twice');
        $this->assertSame(1, $this->cred->recoveryCount($user), 'exactly one code was burned');
    }

    #[Test]
    public function the_lockout_counter_climbs_on_failure_and_resets_on_success(): void
    {
        $user = $this->makeUser();
        $id   = $this->cred->setPassword($user, 'whatever123');

        $this->assertFalse($this->cred->isLockedOut($this->cred->passwordCredential($user)), 'fresh credential is not locked');

        for ($i = 0; $i < Tiger_Model_UserCredential::MAX_FAILURES; $i++) {
            $this->cred->recordFailure($id);
        }
        $locked = $this->cred->passwordCredential($user);
        $this->assertSame(Tiger_Model_UserCredential::MAX_FAILURES, (int) $locked->failed_count, 'each failure increments the counter');
        $this->assertNotNull($locked->locked_until, 'hitting MAX_FAILURES sets a lockout expiry');
        $this->assertTrue($this->cred->isLockedOut($locked), 'the credential is locked out');

        $this->cred->recordSuccess($id);
        $cleared = $this->cred->passwordCredential($user);
        $this->assertSame(0, (int) $cleared->failed_count, 'success clears the failure counter');
        $this->assertNull($cleared->locked_until, 'success clears the lockout');
        $this->assertFalse($this->cred->isLockedOut($cleared));
    }

    #[Test]
    public function a_totp_secret_is_encrypted_at_rest_and_decrypts_back(): void
    {
        $user      = $this->makeUser();
        $plainB32  = 'JBSWY3DPEHPK3PXP';          // an RFC 4648 base32 shared secret
        $this->cred->replaceTotp($user, Tiger_Crypto::encrypt($plainB32), []);

        $stored = (string) $this->cred->activeTotp($user)->secret;
        $this->assertNotSame($plainB32, $stored, 'the raw column is NOT the plaintext secret');
        $this->assertStringNotContainsString($plainB32, $stored, 'the plaintext does not appear inside the ciphertext');
        $this->assertSame($plainB32, Tiger_Crypto::decrypt($stored), 'and it decrypts back to the original, losslessly');
    }
}
