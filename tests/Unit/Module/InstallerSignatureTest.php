<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\Module;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Crypto_Signature;
use Tiger_Module_Installer;

/**
 * The installer's artifact-signature gate (Tiger_Module_Installer::_verifySignature), reached via a tiny
 * subclass exposer. A valid signature passes; a tampered artifact, a wrong key, incomplete material, a
 * bad hash, or an unsupported algorithm each fail-close (throw) — so a bad package is refused before a
 * single file is extracted.
 */
#[CoversClass(Tiger_Module_Installer::class)]
final class InstallerSignatureTest extends UnitTestCase
{
    private string $path = '';
    private array $keys = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->keys = Tiger_Crypto_Signature::generateKeypair();
        $this->path = (string) tempnam(sys_get_temp_dir(), 'artifact');
        file_put_contents($this->path, "PK\x03\x04fake-zip-bytes");
    }

    protected function tearDown(): void
    {
        if ($this->path !== '' && is_file($this->path)) { @unlink($this->path); }
        parent::tearDown();
    }

    private function material(array $over = []): array
    {
        $sig = Tiger_Crypto_Signature::signFile($this->path, $this->keys['secret_key']);
        return array_merge([
            'algo'       => 'ed25519',
            'public_key' => $this->keys['public_key'],
            'signature'  => $sig,
            'sha256'     => Tiger_Crypto_Signature::sha256File($this->path),
        ], $over);
    }

    #[Test]
    public function validSignaturePasses(): void
    {
        $this->expectNotToPerformAssertions();
        InstallerProbe::verify($this->path, $this->material());
    }

    #[Test]
    public function tamperedArtifactThrows(): void
    {
        $mat = $this->material();
        file_put_contents($this->path, "PK\x03\x04TAMPERED");   // sign then swap the bytes
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/verification FAILED/i');
        InstallerProbe::verify($this->path, $mat);
    }

    #[Test]
    public function wrongKeyThrows(): void
    {
        $other = Tiger_Crypto_Signature::generateKeypair();
        $this->expectException(RuntimeException::class);
        InstallerProbe::verify($this->path, $this->material(['public_key' => $other['public_key']]));
    }

    #[Test]
    public function incompleteMaterialThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/incomplete/i');
        InstallerProbe::verify($this->path, ['algo' => 'ed25519', 'public_key' => $this->keys['public_key']]); // no signature
    }

    #[Test]
    public function unsupportedAlgoThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/algorithm/i');
        InstallerProbe::verify($this->path, $this->material(['algo' => 'rsa']));
    }

    #[Test]
    public function wrongSha256Throws(): void
    {
        $this->expectException(RuntimeException::class);
        InstallerProbe::verify($this->path, $this->material(['sha256' => str_repeat('0', 64)]));
    }
}

/** Test seam: expose the installer's protected artifact-signature gate. */
final class InstallerProbe extends Tiger_Module_Installer
{
    public static function verify(string $path, array $sig): void
    {
        self::_verifySignature($path, $sig);
    }
}
