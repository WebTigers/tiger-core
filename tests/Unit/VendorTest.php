<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Vendor;
use Tiger_Vendor_Environment;

/**
 * Tiger_Vendor — the third-party-library provisioner. Only its NO-NETWORK, security-relevant halves
 * are unit-tested here (the Composer/registry-download tiers need a real network + a resolvable
 * bundle and are integration territory):
 *
 *   - satisfies()   the Composer-ish semver matcher used to pick a bundle and to enforce the
 *                   one-shared-version rule. A wrong verdict either installs an incompatible library
 *                   or needlessly reports a conflict, so its constraint forms are pinned exactly.
 *   - the SHA-256 integrity gate inside installTarball() — a downloaded archive whose hash doesn't
 *                   match the expected digest is REFUSED before it is unpacked into the shared store.
 *                   We exercise it with a local file:// URL (no network) and a real .tar.gz we build in
 *                   a temp sandbox, so a good hash installs and a bad hash is rejected as tampering.
 */
#[CoversClass(Tiger_Vendor::class)]
final class VendorTest extends UnitTestCase
{
    private string $tmp = '';
    /** Whether the shared store dir pre-existed — so tearDown only removes what this test created. */
    private bool $storePreExisted = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/tiger_vendor_' . getmypid() . '_' . bin2hex(random_bytes(4));
        @mkdir($this->tmp, 0775, true);
        $this->storePreExisted = is_dir(Tiger_Vendor_Environment::storeDir());
    }

    protected function tearDown(): void
    {
        // Never network-touch: only clean the local temp sandbox and any store dir WE created.
        $store = Tiger_Vendor_Environment::storeDir();
        if (!$this->storePreExisted && is_dir($store)) {
            $this->rrmdir($store);
        } else {
            // store pre-existed — remove just our test package subdir, leave everything else alone.
            $this->rrmdir($store . '/test-lib');
        }
        $this->rrmdir($this->tmp);
        parent::tearDown();
    }

    // ---- satisfies() : the semver matcher --------------------------------------

    #[Test]
    public function caretConstraintPinsTheMajor(): void
    {
        $this->assertTrue(Tiger_Vendor::satisfies('1.4.0', '^1.0'));
        $this->assertTrue(Tiger_Vendor::satisfies('1.0.0', '^1.0'));
        $this->assertFalse(Tiger_Vendor::satisfies('2.0.0', '^1.0'), '^1 excludes the next major');
        $this->assertFalse(Tiger_Vendor::satisfies('0.9.9', '^1.0'), 'below the floor');
    }

    #[Test]
    public function caretBelowOnePinsTheMinor(): void
    {
        // ^0.3 ⇒ >=0.3.0 <0.4.0 (a 0.x caret pins the first non-zero segment).
        $this->assertTrue(Tiger_Vendor::satisfies('0.3.5', '^0.3'));
        $this->assertFalse(Tiger_Vendor::satisfies('0.4.0', '^0.3'));
    }

    #[Test]
    public function tildeConstraintPinsToTheLastGivenSegment(): void
    {
        // ~3.2 ⇒ >=3.2.0 <4.0.0 ; ~3.2.1 ⇒ >=3.2.1 <3.3.0.
        $this->assertTrue(Tiger_Vendor::satisfies('3.9.9', '~3.2'));
        $this->assertFalse(Tiger_Vendor::satisfies('4.0.0', '~3.2'));
        $this->assertTrue(Tiger_Vendor::satisfies('3.2.5', '~3.2.1'));
        $this->assertFalse(Tiger_Vendor::satisfies('3.3.0', '~3.2.1'));
    }

    #[Test]
    public function comparatorAndExactAndWildcardForms(): void
    {
        $this->assertTrue(Tiger_Vendor::satisfies('0.6.0', '>=0.5.0'));
        $this->assertFalse(Tiger_Vendor::satisfies('0.4.0', '>=0.5.0'));
        $this->assertTrue(Tiger_Vendor::satisfies('1.2.3', '=1.2.3'));
        $this->assertTrue(Tiger_Vendor::satisfies('1.2.3', '==1.2.3'));   // == is normalized to =
        $this->assertFalse(Tiger_Vendor::satisfies('1.2.4', '=1.2.3'));
        $this->assertTrue(Tiger_Vendor::satisfies('3.5.1', '3.*'));
        $this->assertFalse(Tiger_Vendor::satisfies('4.0.0', '3.*'));
        $this->assertTrue(Tiger_Vendor::satisfies('1.2.3', '*'), 'the wildcard matches anything');
    }

    #[Test]
    public function andGroupsAndOrGroups(): void
    {
        // Space/comma = AND (all must hold); || = OR (any group holds).
        $this->assertTrue(Tiger_Vendor::satisfies('3.5.0', '>=3.1 <4.0'));
        $this->assertFalse(Tiger_Vendor::satisfies('4.1.0', '>=3.1 <4.0'));
        $this->assertTrue(Tiger_Vendor::satisfies('3.5.0', '>=3.1, <4.0'));
        $this->assertTrue(Tiger_Vendor::satisfies('2.3.0', '^1 || ^2'));
        $this->assertFalse(Tiger_Vendor::satisfies('3.0.0', '^1 || ^2'));
    }

    #[Test]
    public function versionPrefixIsToleratedAndEmptyConstraintMatchesNothing(): void
    {
        $this->assertTrue(Tiger_Vendor::satisfies('v3.0.0', '3.0.0'), 'a leading v is stripped from the version');
        $this->assertTrue(Tiger_Vendor::satisfies('3.0.0', 'v3.0.0'), 'a leading v is stripped from the constraint');
        // An EMPTY constraint satisfies nothing here — callers special-case "no constraint" BEFORE
        // reaching satisfies(), so the matcher treats "" as "no group matched" (false).
        $this->assertFalse(Tiger_Vendor::satisfies('1.0.0', ''));
    }

    // ---- installTarball() : the SHA-256 integrity gate -------------------------

    #[Test]
    public function aCorrectSha256Installs(): void
    {
        $tarGz = $this->makeTarGz();
        $sha   = hash_file('sha256', $tarGz);

        $r = Tiger_Vendor::installTarball('file://' . $tarGz, 'test/lib', $sha);
        $this->assertTrue($r['ok'], 'a matching hash must install: ' . ($r['message'] ?? ''));
        $this->assertDirectoryExists(Tiger_Vendor_Environment::storeDir() . '/test-lib');
    }

    #[Test]
    public function aWrongSha256IsRefusedAsTampering(): void
    {
        $tarGz = $this->makeTarGz();

        $r = Tiger_Vendor::installTarball('file://' . $tarGz, 'test/lib', str_repeat('0', 64));
        $this->assertFalse($r['ok'], 'a hash mismatch must NOT install');
        $this->assertStringContainsString('Checksum mismatch', $r['message']);
        // Nothing was unpacked into the store — the gate fires before the extract/place step.
        $this->assertDirectoryDoesNotExist(Tiger_Vendor_Environment::storeDir() . '/test-lib');
    }

    #[Test]
    public function noSha256GivenSkipsTheGateButStillInstalls(): void
    {
        // sha256 is optional; when omitted the download is trusted (e.g. a source tarball) and installs.
        $tarGz = $this->makeTarGz();
        $r = Tiger_Vendor::installTarball('file://' . $tarGz, 'test/lib', null);
        $this->assertTrue($r['ok'], $r['message'] ?? '');
    }

    // ---- helpers ---------------------------------------------------------------

    /** Build a small, valid .tar.gz in the temp sandbox and return its path. */
    private function makeTarGz(): string
    {
        $base = $this->tmp . '/pkg_' . bin2hex(random_bytes(3)) . '.tar';
        $phar = new \PharData($base);
        $phar->addFromString('lib/Thing.php', "<?php\nclass Thing {}\n");
        $phar->compress(\Phar::GZ);
        unset($phar);
        $gz = $base . '.gz';
        $this->assertFileExists($gz);
        return $gz;
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) { return; }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') { continue; }
            $p = $dir . '/' . $item;
            (is_dir($p) && !is_link($p)) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
