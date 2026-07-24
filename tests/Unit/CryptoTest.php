<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Crypto;

/**
 * Tiger_Crypto — libsodium secretbox for reversible secrets at rest (TOTP secrets, OAuth tokens).
 * The load-bearing behaviors: a clean round-trip, tamper-evidence, and that a rotation window
 * (current + retired keys) decrypts old ciphertext while re-encrypting under the current key.
 */
#[CoversClass(Tiger_Crypto::class)]
final class CryptoTest extends UnitTestCase
{
    private const KEY_A = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='; // 32 0x00 bytes, base64
    private const KEY_B = 'AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQE='; // 32 0x01 bytes, base64

    private function useKeys(string $key, ?string $retired = null): void
    {
        $crypto = ['key' => $key];
        if ($retired !== null) {
            $crypto['key_retired'] = $retired;
        }
        $this->setConfig(['tiger' => ['crypto' => $crypto]]);
    }

    #[Test]
    public function encrypt_then_decrypt_round_trips(): void
    {
        $this->useKeys(self::KEY_A);
        $secret = 'the TOTP shared secret';
        $blob = Tiger_Crypto::encrypt($secret);

        $this->assertNotSame($secret, $blob);
        $this->assertNotFalse(base64_decode($blob, true), 'blob must be base64');
        $this->assertSame($secret, Tiger_Crypto::decrypt($blob));
    }

    #[Test]
    public function each_encryption_uses_a_fresh_nonce(): void
    {
        $this->useKeys(self::KEY_A);
        $a = Tiger_Crypto::encrypt('same input');
        $b = Tiger_Crypto::encrypt('same input');
        $this->assertNotSame($a, $b, 'identical plaintext must not produce identical ciphertext');
        $this->assertSame('same input', Tiger_Crypto::decrypt($a));
        $this->assertSame('same input', Tiger_Crypto::decrypt($b));
    }

    #[Test]
    public function decrypt_rejects_a_tampered_blob(): void
    {
        $this->useKeys(self::KEY_A);
        $blob = Tiger_Crypto::encrypt('authentic');
        $raw = base64_decode($blob, true);
        $raw[strlen($raw) - 1] = $raw[strlen($raw) - 1] ^ "\xff";   // flip the last ciphertext byte
        $tampered = base64_encode($raw);

        $this->expectException(RuntimeException::class);
        Tiger_Crypto::decrypt($tampered);
    }

    #[Test]
    public function decrypt_rejects_malformed_input(): void
    {
        $this->useKeys(self::KEY_A);
        $this->expectException(RuntimeException::class);
        Tiger_Crypto::decrypt('not base64 !!! too short');
    }

    #[Test]
    public function a_wrong_key_cannot_decrypt(): void
    {
        $this->useKeys(self::KEY_A);
        $blob = Tiger_Crypto::encrypt('secret under A');

        $this->useKeys(self::KEY_B);   // only B configured now
        $this->expectException(RuntimeException::class);
        Tiger_Crypto::decrypt($blob);
    }

    #[Test]
    public function retired_key_still_decrypts_during_a_rotation_window(): void
    {
        $this->useKeys(self::KEY_A);
        $blob = Tiger_Crypto::encrypt('secret under A');

        // rotate: B is current, A retired — old ciphertext must still open.
        $this->useKeys(self::KEY_B, self::KEY_A);
        $this->assertSame('secret under A', Tiger_Crypto::decrypt($blob));

        // reencrypt moves it under the CURRENT key; then A alone must NOT decrypt it.
        $rekeyed = Tiger_Crypto::reencrypt($blob);
        $this->useKeys(self::KEY_B);
        $this->assertSame('secret under A', Tiger_Crypto::decrypt($rekeyed));
        $this->useKeys(self::KEY_A);
        $this->expectException(RuntimeException::class);
        Tiger_Crypto::decrypt($rekeyed);
    }

    #[Test]
    public function isConfigured_reflects_key_presence(): void
    {
        $this->assertFalse(Tiger_Crypto::isConfigured(), 'no config => not configured');
        $this->useKeys(self::KEY_A);
        $this->assertTrue(Tiger_Crypto::isConfigured());
    }

    #[Test]
    public function encrypt_without_a_key_is_a_hard_error(): void
    {
        // No config at all — encryption must throw, never silently no-op.
        $this->expectException(RuntimeException::class);
        Tiger_Crypto::encrypt('anything');
    }

    #[Test]
    public function generateKey_mints_a_usable_32_byte_key(): void
    {
        $key = Tiger_Crypto::generateKey();
        $raw = base64_decode($key, true);
        $this->assertNotFalse($raw);
        $this->assertSame(32, strlen($raw));

        $this->useKeys($key);
        $this->assertSame('works', Tiger_Crypto::decrypt(Tiger_Crypto::encrypt('works')));
    }
}
