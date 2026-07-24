<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\Backup;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Backup_Archive;

/**
 * Tiger_Backup_Archive — the ext-zip / ext-phar shim that always produces a real .zip.
 *
 * Backups target cPanel/shared hosting where `ZipArchive` isn't guaranteed, so the shim falls back to
 * `PharData`; either way the output is a standard zip and callers touch neither class. These tests
 * drive the public contract against a throwaway temp dir with whichever backend the running PHP has:
 *   - `available()` reflects the presence of a backend;
 *   - `build()` writes a zip from BOTH entry kinds — a `file` (copied in) and inline `data` (bytes) —
 *     and OVERWRITES a pre-existing target;
 *   - `read()` pulls a single entry's bytes back (the manifest-read path) and returns false for an
 *     absent entry;
 *   - `extract()` unpacks the whole archive to a directory, byte-for-byte;
 *   - a full build → read → extract round trip preserves every entry's content and archive path.
 *
 * The suite skips wholesale if neither backend exists (it never will in practice — ext-phar is
 * effectively universal). Everything lands under a unique sys_get_temp_dir() sandbox, removed in
 * tearDown.
 */
#[CoversClass(Tiger_Backup_Archive::class)]
final class ArchiveTest extends UnitTestCase
{
    private string $sandbox;

    protected function setUp(): void
    {
        parent::setUp();
        if (!Tiger_Backup_Archive::available()) {
            $this->markTestSkipped('No zip backend (ext-zip or ext-phar) available.');
        }
        $this->sandbox = sys_get_temp_dir() . '/tiger-arch-' . bin2hex(random_bytes(6));
        mkdir($this->sandbox, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->sandbox);
        parent::tearDown();
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            @unlink($dir);
            return;
        }
        foreach (scandir($dir) as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $p = $dir . '/' . $e;
            is_dir($p) && !is_link($p) ? $this->rmrf($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    // ---- available() ---------------------------------------------------------------------------

    #[Test]
    public function available_is_true_when_a_backend_exists(): void
    {
        // setUp already skipped if neither exists, so here it must be true — and it agrees with the
        // raw class_exists() the shim uses internally.
        $this->assertTrue(Tiger_Backup_Archive::available());
        $this->assertSame(
            class_exists('ZipArchive') || class_exists('PharData'),
            Tiger_Backup_Archive::available()
        );
    }

    // ---- build() + read() ----------------------------------------------------------------------

    #[Test]
    public function build_writes_both_a_file_entry_and_an_inline_data_entry(): void
    {
        // A real on-disk source for the 'file' entry kind…
        $src = $this->sandbox . '/source.txt';
        file_put_contents($src, 'file entry contents');

        $zip = $this->sandbox . '/out.zip';
        Tiger_Backup_Archive::build($zip, [
            ['name' => 'from-file.txt', 'file' => $src],
            ['name' => 'manifest.json', 'data' => '{"ok":true}'],
        ]);

        $this->assertFileExists($zip);
        // read() pulls each entry back out by its archive name.
        $this->assertSame('file entry contents', Tiger_Backup_Archive::read($zip, 'from-file.txt'));
        $this->assertSame('{"ok":true}', Tiger_Backup_Archive::read($zip, 'manifest.json'));
    }

    #[Test]
    public function read_returns_false_for_an_absent_entry(): void
    {
        $zip = $this->sandbox . '/out.zip';
        Tiger_Backup_Archive::build($zip, [['name' => 'only.txt', 'data' => 'x']]);
        $this->assertFalse(Tiger_Backup_Archive::read($zip, 'does-not-exist.txt'));
    }

    #[Test]
    public function build_overwrites_a_pre_existing_archive(): void
    {
        $zip = $this->sandbox . '/out.zip';
        // A stale file sits at the target path first…
        file_put_contents($zip, 'not a zip — should be replaced');

        Tiger_Backup_Archive::build($zip, [['name' => 'fresh.txt', 'data' => 'fresh bytes']]);

        // …and the new archive fully replaced it (the stale entry name is gone; the new one reads).
        $this->assertSame('fresh bytes', Tiger_Backup_Archive::read($zip, 'fresh.txt'));
    }

    // ---- extract() -----------------------------------------------------------------------------

    #[Test]
    public function extract_unpacks_the_whole_archive_to_a_directory(): void
    {
        $zip = $this->sandbox . '/out.zip';
        Tiger_Backup_Archive::build($zip, [
            ['name' => 'a.txt', 'data' => 'alpha'],
            ['name' => 'nested/b.txt', 'data' => 'bravo'],
        ]);

        $dest = $this->sandbox . '/unpacked';
        Tiger_Backup_Archive::extract($zip, $dest);

        $this->assertFileExists($dest . '/a.txt');
        $this->assertSame('alpha', file_get_contents($dest . '/a.txt'));
        $this->assertFileExists($dest . '/nested/b.txt');
        $this->assertSame('bravo', file_get_contents($dest . '/nested/b.txt'));
    }

    #[Test]
    public function extract_creates_a_missing_destination_directory(): void
    {
        $zip = $this->sandbox . '/out.zip';
        Tiger_Backup_Archive::build($zip, [['name' => 'x.txt', 'data' => 'y']]);

        // A destination that does not yet exist is created by extract().
        $dest = $this->sandbox . '/brand/new/deep';
        $this->assertDirectoryDoesNotExist($dest);
        Tiger_Backup_Archive::extract($zip, $dest);
        $this->assertFileExists($dest . '/x.txt');
    }

    // ---- full round trip -----------------------------------------------------------------------

    #[Test]
    public function build_then_extract_preserves_every_entry_byte_for_byte(): void
    {
        $binary = random_bytes(1024);
        $src = $this->sandbox . '/db.sql';
        file_put_contents($src, "-- dump\nINSERT INTO t VALUES (1);\n");

        $zip = $this->sandbox . '/backup.zip';
        Tiger_Backup_Archive::build($zip, [
            ['name' => 'database/db.sql', 'file' => $src],
            ['name' => 'blob.bin', 'data' => $binary],
            ['name' => 'manifest.json', 'data' => '{"tables":1}'],
        ]);

        $dest = $this->sandbox . '/restore';
        Tiger_Backup_Archive::extract($zip, $dest);

        $this->assertSame(file_get_contents($src), file_get_contents($dest . '/database/db.sql'));
        $this->assertSame($binary, file_get_contents($dest . '/blob.bin'));
        $this->assertSame('{"tables":1}', file_get_contents($dest . '/manifest.json'));
    }
}
