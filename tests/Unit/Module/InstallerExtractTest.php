<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\Module;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Module_Installer;

/**
 * The installer's archive extractor (Tiger_Module_Installer::_extract) — the zip-slip / tar-slip
 * guard, reached through a tiny subclass exposer.
 *
 * This is the highest-value security test on the installer: an install downloads an UNTRUSTED archive
 * (a public GitHub tarball, an uploaded .zip, or a licensed vendor's release) and extracts it into a
 * temp dir before moving it into modules/. If a crafted entry name (`../…`, deep `../../../…`, or an
 * absolute `/…` path) could steer a write OUTSIDE that temp dir, an attacker could overwrite arbitrary
 * files on the host — the classic "Zip Slip" class of RCE. `_extract` itself carries no explicit path
 * sanitizer; the guarantee comes from the extractors it drives (ZipArchive / PharData), which flatten
 * traversal segments and strip leading slashes so every member lands INSIDE the target. These tests
 * pin that invariant so a future refactor (e.g. swapping to a hand-rolled loop, or a shell `unzip`/`tar`
 * fallback that doesn't sanitize) can't silently reintroduce the hole.
 *
 * The archives are built in-process with genuinely malicious member names: ZipArchive keeps a `../`
 * member name verbatim, and a hand-written ustar tar lets us smuggle a real `../` member past PharData's
 * write-side sanitization — so we exercise the EXTRACT side, which is where the defense must live.
 */
#[CoversClass(Tiger_Module_Installer::class)]
final class InstallerExtractTest extends UnitTestCase
{
    private string $sandbox = '';
    /** An absolute-path member target we probe for outside any sandbox; cleaned up regardless. */
    private string $absProbe = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->sandbox = sys_get_temp_dir() . '/tiger_extract_' . getmypid() . '_' . bin2hex(random_bytes(4));
        @mkdir($this->sandbox, 0775, true);
        $this->absProbe = sys_get_temp_dir() . '/tiger_slip_abs_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.txt';
        @unlink($this->absProbe);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->sandbox);
        @unlink($this->absProbe);
        parent::tearDown();
    }

    #[Test]
    public function zipEntriesCannotEscapeTheTargetDir(): void
    {
        // A ZIP is what an ADMIN UPLOAD arrives as; _extract routes on the "PK" magic to the zip branch.
        $target = $this->sandbox . '/zip_target';
        @mkdir($target, 0775, true);

        $zipPath = $this->sandbox . '/evil.zip';
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($zipPath, \ZipArchive::CREATE) === true, 'could not create the test zip');
        $zip->addFromString('../escaped_rel.txt', 'PWNED');                   // one level up
        $zip->addFromString('a/b/../../../escaped_deep.txt', 'PWNED');        // deep traversal
        $zip->addFromString($this->absProbe, 'PWNED');                        // absolute path
        $zip->addFromString('good.txt', 'legit');                            // a benign control member
        $zip->close();

        InstallerExtractProbe::extract($zipPath, $target);

        // The security invariant: nothing landed OUTSIDE the target — not the parent, not the
        // grandparent, not the absolute path the archive named.
        $this->assertFileDoesNotExist($this->sandbox . '/escaped_rel.txt', 'a ../ member escaped one level');
        $this->assertFileDoesNotExist($this->sandbox . '/escaped_deep.txt', 'a deep ../ member escaped');
        $this->assertFileDoesNotExist(dirname($this->sandbox) . '/escaped_rel.txt', 'a member escaped to the grandparent');
        $this->assertFileDoesNotExist($this->absProbe, 'an absolute-path member escaped to /tmp');

        // And the benign member was still extracted (the extractor worked, it just neutralized traversal).
        $this->assertFileExists($target . '/good.txt');
        $this->assertSame('legit', file_get_contents($target . '/good.txt'));

        // Every real file the extractor produced is confined within the target subtree.
        $this->assertAllFilesWithin($target);
    }

    #[Test]
    public function tarEntriesCannotEscapeTheTargetDir(): void
    {
        // A TAR.GZ is what a GITHUB RELEASE download arrives as; _extract drives PharData for it. We
        // hand-build the ustar bytes so the malicious names survive into the archive (PharData would
        // sanitize them if we added them via its API), forcing the EXTRACT side to be the thing tested.
        $target = $this->sandbox . '/tar_target';
        @mkdir($target, 0775, true);

        $tarGz = $this->sandbox . '/evil.tar.gz';
        $this->writeTarGz($tarGz, [
            '../escaped_rel.txt'          => 'PWNED',
            'a/b/../../../escaped_deep.txt' => 'PWNED',
            'good.txt'                    => 'legit',
        ]);

        InstallerExtractProbe::extract($tarGz, $target);

        $this->assertFileDoesNotExist($this->sandbox . '/escaped_rel.txt', 'a ../ tar member escaped one level');
        $this->assertFileDoesNotExist($this->sandbox . '/escaped_deep.txt', 'a deep ../ tar member escaped');
        $this->assertFileDoesNotExist(dirname($this->sandbox) . '/escaped_rel.txt', 'a tar member escaped to the grandparent');

        $this->assertFileExists($target . '/good.txt');
        $this->assertSame('legit', file_get_contents($target . '/good.txt'));
        $this->assertAllFilesWithin($target);
    }

    // ---- helpers ---------------------------------------------------------------

    /** Assert that every regular file under $root has a real path that stays inside $root (no escape). */
    private function assertAllFilesWithin(string $root): void
    {
        $realRoot = realpath($root);
        $this->assertNotFalse($realRoot);
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            $rp = realpath($file->getPathname());
            $this->assertNotFalse($rp);
            $this->assertStringStartsWith($realRoot . DIRECTORY_SEPARATOR, $rp, "extracted file escaped the target: {$rp}");
        }
    }

    /** Build a gzip'd ustar tar at $path from [name => bytes], keeping malicious names verbatim. */
    private function writeTarGz(string $path, array $entries): void
    {
        $tar = '';
        foreach ($entries as $name => $data) {
            $tar .= $this->ustarHeader((string) $name, strlen((string) $data));
            $tar .= $data . str_repeat("\0", (512 - strlen((string) $data) % 512) % 512);
        }
        $tar .= str_repeat("\0", 1024);   // two zero blocks terminate the archive
        file_put_contents($path, (string) gzencode($tar));
    }

    /** A single 512-byte ustar header with a correct checksum (name kept exactly as given). */
    private function ustarHeader(string $name, int $size): string
    {
        $h  = str_pad(substr($name, 0, 100), 100, "\0");
        $h .= str_pad('0000644', 7, '0', STR_PAD_LEFT) . "\0";      // mode
        $h .= str_pad('0000000', 7, '0', STR_PAD_LEFT) . "\0";      // uid
        $h .= str_pad('0000000', 7, '0', STR_PAD_LEFT) . "\0";      // gid
        $h .= str_pad(decoct($size), 11, '0', STR_PAD_LEFT) . "\0"; // size (octal)
        $h .= str_pad(decoct(time()), 11, '0', STR_PAD_LEFT) . "\0";// mtime (octal)
        $h .= str_repeat(' ', 8);                                   // checksum field (spaces while summing)
        $h .= '0';                                                  // typeflag: regular file
        $h .= str_repeat("\0", 100);                                // linkname
        $h .= "ustar\0" . '00';                                     // magic + version
        $h .= str_repeat("\0", 32) . str_repeat("\0", 32);          // uname + gname
        $h .= str_repeat("\0", 8) . str_repeat("\0", 8);            // devmajor + devminor
        $h .= str_repeat("\0", 155);                                // prefix
        $h .= str_repeat("\0", 12);                                 // pad to 512

        $sum = 0;
        for ($i = 0; $i < 512; $i++) { $sum += ord($h[$i]); }
        $chk = str_pad(decoct($sum), 6, '0', STR_PAD_LEFT) . "\0 ";
        return substr($h, 0, 148) . $chk . substr($h, 156);
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

/** Test seam: expose the installer's protected archive extractor. */
final class InstallerExtractProbe extends Tiger_Module_Installer
{
    public static function extract(string $archive, string $into): void
    {
        self::_extract($archive, $into);
    }
}
