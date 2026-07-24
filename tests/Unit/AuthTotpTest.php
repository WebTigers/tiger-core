<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Auth_Totp;

/**
 * Tiger_Auth_Totp — hand-rolled RFC 6238 TOTP. The security claim ("verified against the RFC test
 * vectors") is only true if a test actually checks the vectors, so we do — against the published
 * SHA1 vectors, reduced to Tiger's 6 digits (the RFC prints 8; 6-digit == RFC value mod 1e6).
 */
#[CoversClass(Tiger_Auth_Totp::class)]
final class AuthTotpTest extends UnitTestCase
{
    /** RFC 6238 SHA1 seed: ASCII "12345678901234567890", as base32 (what codeAt() expects). */
    private const SEED_B32 = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    /** @return array<string,array{int,string}> [unix time, expected 6-digit code] */
    public static function rfcVectors(): array
    {
        // RFC 6238 Appendix B (SHA1) 8-digit values, taken mod 1e6 for Tiger's 6 digits.
        return [
            'T=59'          => [59, '287082'],          // 94287082
            'T=1111111109'  => [1111111109, '081804'],  // 07081804
            'T=1111111111'  => [1111111111, '050471'],  // 14050471
            'T=1234567890'  => [1234567890, '005924'],  // 89005924
            'T=2000000000'  => [2000000000, '279037'],  // 69279037
            'T=20000000000' => [20000000000, '353130'], // 65353130
        ];
    }

    #[Test]
    #[DataProvider('rfcVectors')]
    public function codeAt_matches_the_rfc6238_vectors(int $time, string $expected): void
    {
        $counter = intdiv($time, Tiger_Auth_Totp::PERIOD);
        $this->assertSame($expected, Tiger_Auth_Totp::codeAt(self::SEED_B32, $counter));
    }

    #[Test]
    #[DataProvider('rfcVectors')]
    public function verify_accepts_the_live_code_at_a_fixed_time(int $time, string $expected): void
    {
        $this->assertTrue(Tiger_Auth_Totp::verify(self::SEED_B32, $expected, 1, $time));
    }

    #[Test]
    public function verify_tolerates_one_step_of_drift_within_the_window(): void
    {
        $at = 1234567890;
        $prevStepCode = Tiger_Auth_Totp::codeAt(self::SEED_B32, intdiv($at, 30) - 1);
        $this->assertTrue(Tiger_Auth_Totp::verify(self::SEED_B32, $prevStepCode, 1, $at));
        // …but not two steps out, with window=1
        $twoBack = Tiger_Auth_Totp::codeAt(self::SEED_B32, intdiv($at, 30) - 2);
        $this->assertFalse(Tiger_Auth_Totp::verify(self::SEED_B32, $twoBack, 1, $at));
    }

    #[Test]
    public function verify_rejects_a_wrong_or_malformed_code(): void
    {
        $this->assertFalse(Tiger_Auth_Totp::verify(self::SEED_B32, '000000', 1, 59));
        $this->assertFalse(Tiger_Auth_Totp::verify(self::SEED_B32, '12345', 1, 59));   // too short
        $this->assertFalse(Tiger_Auth_Totp::verify(self::SEED_B32, 'abcdef', 1, 59));  // non-numeric
    }

    #[Test]
    public function generated_secret_is_usable_base32_of_the_expected_size(): void
    {
        $secret = Tiger_Auth_Totp::generateSecret();
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret, 'secret must be base32');
        // 20 bytes -> 32 base32 chars
        $this->assertSame(32, strlen($secret));
        // and a code computed from it verifies against itself
        $code = Tiger_Auth_Totp::codeAt($secret, 42);
        $this->assertTrue(Tiger_Auth_Totp::verify($secret, $code, 1, 42 * 30));
    }

    #[Test]
    public function base32_round_trips_arbitrary_bytes(): void
    {
        foreach ([1, 5, 16, 20, 31, 64] as $len) {
            $raw = random_bytes($len);
            $decoded = Tiger_Auth_Totp::base32Decode(Tiger_Auth_Totp::base32Encode($raw));
            $this->assertSame($raw, $decoded, "round-trip failed at $len bytes");
        }
    }

    #[Test]
    public function base32_decode_ignores_spaces_and_case(): void
    {
        $raw = random_bytes(20);
        $enc = Tiger_Auth_Totp::base32Encode($raw);
        $messy = strtolower(chunk_split($enc, 4, ' '));   // lowercased, space-grouped (how UIs show it)
        $this->assertSame($raw, Tiger_Auth_Totp::base32Decode($messy));
    }

    #[Test]
    public function uri_is_a_well_formed_otpauth_url(): void
    {
        $uri = Tiger_Auth_Totp::uri(self::SEED_B32, 'user@example.com', 'Tiger');
        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('secret=' . self::SEED_B32, $uri);
        $this->assertStringContainsString('issuer=Tiger', $uri);
    }
}
