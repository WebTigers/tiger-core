<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Security;

/**
 * Tiger_Security — the password/code pepper. The behaviors that keep a stolen credential table
 * un-crackable and a rotation non-breaking: pepper actually changes the prehash, verify tries
 * current→retired→legacy, and codeMatches is domain-separated by context.
 */
#[CoversClass(Tiger_Security::class)]
final class SecurityTest extends UnitTestCase
{
    private const PEP_A = 'cGVwcGVyLUEtMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDA='; // arbitrary base64
    private const PEP_B = 'cGVwcGVyLUItMTExMTExMTExMTExMTExMTExMTExMTE=';

    private function usePepper(string $current, ?string $retired = null): void
    {
        $sec = ['pepper' => $current];
        if ($retired !== null) {
            $sec['pepper_retired'] = $retired;
        }
        $this->setConfig(['tiger' => ['security' => $sec]]);
    }

    #[Test]
    public function without_a_pepper_prehash_is_the_raw_password(): void
    {
        // no config
        $this->assertFalse(Tiger_Security::hasPepper());
        $this->assertSame('hunter2', Tiger_Security::prehashPassword('hunter2'));
    }

    #[Test]
    public function a_pepper_changes_the_prehash_deterministically(): void
    {
        $this->usePepper(self::PEP_A);
        $this->assertTrue(Tiger_Security::hasPepper());

        $one = Tiger_Security::prehashPassword('hunter2');
        $two = Tiger_Security::prehashPassword('hunter2');
        $this->assertSame($one, $two, 'same input+pepper must be stable');
        $this->assertNotSame('hunter2', $one, 'pepper must transform the password');
        // 32-byte HMAC, base64 -> 44 chars, under bcrypt's 72-byte limit
        $this->assertSame(44, strlen($one));

        // a different pepper yields a different prehash
        $this->usePepper(self::PEP_B);
        $this->assertNotSame($one, Tiger_Security::prehashPassword('hunter2'));
    }

    #[Test]
    public function password_verifiers_are_ordered_current_then_retired_then_legacy(): void
    {
        $this->usePepper(self::PEP_A, self::PEP_B);
        $verifiers = Tiger_Security::passwordVerifiers('hunter2');

        // current pepper first…
        $this->assertSame(Tiger_Security::prehashPassword('hunter2'), $verifiers[0]);
        // …legacy raw password last (the no-pepper migration path)
        $this->assertSame('hunter2', $verifiers[array_key_last($verifiers)]);
        // current + retired + legacy = 3 candidates
        $this->assertCount(3, $verifiers);
    }

    #[Test]
    public function code_hash_and_match_round_trip(): void
    {
        $this->usePepper(self::PEP_A);
        $hash = Tiger_Security::hashCode('123456', 'login');
        $this->assertTrue(Tiger_Security::codeMatches('123456', 'login', $hash));
    }

    #[Test]
    public function code_hash_is_domain_separated_by_context(): void
    {
        $this->usePepper(self::PEP_A);
        $hash = Tiger_Security::hashCode('123456', 'recovery');
        // same code, wrong context must NOT match
        $this->assertFalse(Tiger_Security::codeMatches('123456', 'login', $hash));
        $this->assertTrue(Tiger_Security::codeMatches('123456', 'recovery', $hash));
    }

    #[Test]
    public function code_hash_keeps_the_legacy_sha256_shape(): void
    {
        $this->usePepper(self::PEP_A);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', Tiger_Security::hashCode('123456', 'login'));
    }

    #[Test]
    public function a_code_issued_before_rotation_still_matches_via_retired_pepper(): void
    {
        $this->usePepper(self::PEP_A);
        $hash = Tiger_Security::hashCode('123456', 'recovery');

        // rotate: B current, A retired — the pre-rotation code must still redeem.
        $this->usePepper(self::PEP_B, self::PEP_A);
        $this->assertTrue(Tiger_Security::codeMatches('123456', 'recovery', $hash));

        // but once A is fully dropped, it no longer matches (legacy sha256 won't either).
        $this->usePepper(self::PEP_B);
        $this->assertFalse(Tiger_Security::codeMatches('123456', 'recovery', $hash));
    }

    #[Test]
    public function without_a_pepper_code_hash_is_plain_sha256(): void
    {
        // no config
        $this->assertSame(hash('sha256', '123456'), Tiger_Security::hashCode('123456', 'login'));
        $this->assertTrue(Tiger_Security::codeMatches('123456', 'login', hash('sha256', '123456')));
    }

    #[Test]
    public function generated_pepper_is_32_bytes_base64(): void
    {
        $raw = base64_decode(Tiger_Security::generatePepper(), true);
        $this->assertNotFalse($raw);
        $this->assertSame(32, strlen($raw));
    }
}
