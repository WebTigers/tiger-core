<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\Crypto;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Crypto_Signature;

/**
 * Tiger_Crypto_Signature — detached Ed25519. The load-bearing behavior is fail-safe verification: a valid
 * signature verifies, and ANY tamper (message, signature, key) or malformed input reports false without
 * throwing — a forged package must be indistinguishable from a merely-wrong one.
 */
#[CoversClass(Tiger_Crypto_Signature::class)]
final class SignatureTest extends UnitTestCase
{
    private function keys(): array
    {
        return Tiger_Crypto_Signature::generateKeypair();
    }

    #[Test]
    public function roundTripVerifies(): void
    {
        $k = $this->keys();
        $sig = Tiger_Crypto_Signature::sign('the payload', $k['secret_key']);
        $this->assertTrue(Tiger_Crypto_Signature::verify('the payload', $sig, $k['public_key']));
    }

    #[Test]
    public function tamperedMessageFails(): void
    {
        $k = $this->keys();
        $sig = Tiger_Crypto_Signature::sign('the payload', $k['secret_key']);
        $this->assertFalse(Tiger_Crypto_Signature::verify('the paylo0d', $sig, $k['public_key']));
    }

    #[Test]
    public function wrongKeyFails(): void
    {
        $a = $this->keys();
        $b = $this->keys();
        $sig = Tiger_Crypto_Signature::sign('msg', $a['secret_key']);
        $this->assertFalse(Tiger_Crypto_Signature::verify('msg', $sig, $b['public_key']));
    }

    #[Test]
    public function malformedInputsReturnFalseNeverThrow(): void
    {
        $k = $this->keys();
        $this->assertFalse(Tiger_Crypto_Signature::verify('m', 'not-base64!!', $k['public_key']));
        $this->assertFalse(Tiger_Crypto_Signature::verify('m', base64_encode('too-short'), $k['public_key']));
        $this->assertFalse(Tiger_Crypto_Signature::verify('m', 'AAAA', 'AAAA'));
        $this->assertFalse(Tiger_Crypto_Signature::verify('m', '', ''));
    }

    #[Test]
    public function fileRoundTripWithHash(): void
    {
        $k = $this->keys();
        $path = tempnam(sys_get_temp_dir(), 'sigtest');
        file_put_contents($path, "module-bytes\x00\x01");
        try {
            $sig = Tiger_Crypto_Signature::signFile($path, $k['secret_key']);
            $sha = Tiger_Crypto_Signature::sha256File($path);
            $this->assertTrue(Tiger_Crypto_Signature::verifyFile($path, $sig, $k['public_key']));
            $this->assertTrue(Tiger_Crypto_Signature::verifyFile($path, $sig, $k['public_key'], $sha));
            // A correct signature but the WRONG expected hash still fails.
            $this->assertFalse(Tiger_Crypto_Signature::verifyFile($path, $sig, $k['public_key'], str_repeat('0', 64)));
            // Tamper the file → the signature no longer verifies.
            file_put_contents($path, 'tampered');
            $this->assertFalse(Tiger_Crypto_Signature::verifyFile($path, $sig, $k['public_key']));
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function verifyFileMissingPathIsFalse(): void
    {
        $k = $this->keys();
        $this->assertFalse(Tiger_Crypto_Signature::verifyFile('/no/such/file', 'AAAA', $k['public_key']));
    }

    #[Test]
    public function fingerprintIsStableAndGuardsMalformed(): void
    {
        $k = $this->keys();
        $fp1 = Tiger_Crypto_Signature::fingerprint($k['public_key']);
        $fp2 = Tiger_Crypto_Signature::fingerprint($k['public_key']);
        $this->assertNotSame('', $fp1);
        $this->assertSame($fp1, $fp2);
        $this->assertSame('', Tiger_Crypto_Signature::fingerprint('not-a-key'));
    }

    #[Test]
    public function signWithMalformedSecretKeyThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Tiger_Crypto_Signature::sign('m', base64_encode('short'));
    }
}
