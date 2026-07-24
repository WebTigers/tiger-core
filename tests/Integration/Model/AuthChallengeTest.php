<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_AuthChallenge;
use Tiger_Model_User;
use Tiger_Security;
use Zend_Config;
use Zend_Registry;

/**
 * Tiger_Model_AuthChallenge — the transient, single-use hashed-OTP / reset-token store and the
 * sharp end of auth (migration 0005). The contract this locks down is the one callers must never
 * be able to forget: a correct code redeems exactly ONCE, within its TTL, and only until a
 * brute-force attempt-cap trips — and the plaintext code never touches the row (it is stored as a
 * peppered hash). Every one of those guards lives inside `redeem()`, so these tests exercise it
 * against a real row rather than a mock: expiry is forced by minting a past `expires_at`, single-use
 * by a second redeem, the attempt-lock by walking wrong codes to the cap.
 *
 * Crypto note: `issue()`/`redeem()` hash the code through `Tiger_Security` (context 'challenge'),
 * which reads a pepper from the `Zend_Config` in the registry — the base IntegrationTestCase does
 * NOT seed that, so setUp() installs a test pepper (never the dev one) before any code is hashed.
 */
#[CoversClass(Tiger_Model_AuthChallenge::class)]
final class AuthChallengeTest extends IntegrationTestCase
{
    private Tiger_Model_AuthChallenge $challenge;

    protected function setUp(): void
    {
        parent::setUp();
        // Seed a test-only pepper so hashCode()/codeMatches() run their real (peppered) path.
        Zend_Registry::set('Zend_Config', new Zend_Config([
            'tiger' => ['security' => ['pepper' => base64_encode(str_repeat("\x41", 32))]],
        ], true));
        $this->challenge = new Tiger_Model_AuthChallenge();
    }

    /** A real user to hang challenges on (user_id FK is ON DELETE CASCADE, nullable on the table). */
    private function makeUser(): string
    {
        return (new Tiger_Model_User())->insert(['email' => 'chal-' . bin2hex(random_bytes(8)) . '@example.test']);
    }

    #[Test]
    public function a_correct_code_within_ttl_redeems_and_returns_the_row(): void
    {
        $user = $this->makeUser();
        $id   = $this->challenge->issue($user, 'password_reset', '123456', 600);

        $row = $this->challenge->redeem($id, '123456');
        $this->assertNotNull($row, 'a correct, unexpired, first-time code redeems');
        $this->assertSame($id, $row->challenge_id);
        $this->assertNotNull($this->challenge->findById($id)->consumed_at, 'redeem stamps consumed_at');
    }

    #[Test]
    public function an_expired_challenge_does_not_redeem(): void
    {
        $user = $this->makeUser();
        // Negative TTL puts expires_at in the past at issue time — no clock trickery needed.
        $id = $this->challenge->issue($user, 'sms_otp', '654321', -10);

        $this->assertNull($this->challenge->redeem($id, '654321'), 'past-TTL challenge is dead on arrival');
        $this->assertNull($this->challenge->findById($id)->consumed_at, 'an expired redeem never consumes the row');
    }

    #[Test]
    public function a_redeemed_challenge_cannot_be_redeemed_again(): void
    {
        $user = $this->makeUser();
        $id   = $this->challenge->issue($user, 'magic_link', 'abcdef', 600);

        $this->assertNotNull($this->challenge->redeem($id, 'abcdef'), 'first redeem succeeds');
        $this->assertNull($this->challenge->redeem($id, 'abcdef'), 'single-use: the second redeem is refused even with the right code');
    }

    #[Test]
    public function too_many_wrong_attempts_lock_the_challenge_out(): void
    {
        $user = $this->makeUser();
        $id   = $this->challenge->issue($user, 'sms_otp', '111111', 600);

        // MAX_ATTEMPTS (5) wrong tries — each returns null and costs one attempt.
        for ($i = 0; $i < Tiger_Model_AuthChallenge::MAX_ATTEMPTS; $i++) {
            $this->assertNull($this->challenge->redeem($id, '000000'), "wrong attempt #$i fails");
        }
        $this->assertSame(Tiger_Model_AuthChallenge::MAX_ATTEMPTS, (int) $this->challenge->findById($id)->attempts);

        // Even the CORRECT code is now refused — the lock trips before the code is checked.
        $this->assertNull($this->challenge->redeem($id, '111111'), 'a locked-out challenge refuses the correct code too');
    }

    #[Test]
    public function the_stored_code_is_hashed_not_plaintext_and_a_wrong_code_fails(): void
    {
        $user  = $this->makeUser();
        $plain = '246810';
        $id    = $this->challenge->issue($user, 'email_verify', $plain, 600);

        $stored = (string) $this->challenge->findById($id)->code_hash;
        $this->assertNotSame($plain, $stored, 'the plaintext code is never persisted');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $stored, 'code_hash is a 64-hex keyed digest');
        $this->assertSame($stored, Tiger_Security::hashCode($plain, 'challenge'), 'and it is the peppered hash of the code');

        $this->assertNull($this->challenge->redeem($id, '999999'), 'a wrong code never redeems');
        $this->assertNotNull($this->challenge->redeem($id, $plain), 'the right code still redeems (wrong attempt did not consume it)');
    }
}
