<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\Media;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Media_Storage;
use Tiger_Media_Storage_Filesystem;
use Tiger_Media_Storage_S3;

/**
 * Tiger_Media_Storage — the disk FACTORY: a disk name → its (memoized) adapter, read from the
 * `media.disks.*` config in Zend_Registry.
 *
 * Under test: adapter resolution + the `filesystem`/`local` aliases; **memoization** (the same
 * disk yields the identical instance until reset()); the `default_disk` fallback (and its 'local'
 * default when unset); and the two hard failures — an **unknown adapter** and a **missing disk
 * config** both throw. Because the memo is process-static, we reset() around every test so no disk
 * instance survives from one case into the next.
 */
#[CoversClass(Tiger_Media_Storage::class)]
final class StorageTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tiger_Media_Storage::reset();   // the memo is static — start each test with an empty one
    }

    protected function tearDown(): void
    {
        Tiger_Media_Storage::reset();
        parent::tearDown();
    }

    /** Seed a `media` config node (default_disk + a map of disks) into the registry. */
    private function seedMedia(array $media): void
    {
        $this->setConfig(['media' => $media]);
    }

    /** A minimal filesystem disk spec (roots are never touched at construct time). */
    private function fsDisk(): array
    {
        return ['adapter' => 'filesystem', 'public_root' => '/tmp/pub', 'private_root' => '/tmp/priv'];
    }

    // ---- Resolution + aliases -----------------------------------------------------------

    #[Test]
    public function resolves_a_filesystem_disk_to_the_filesystem_adapter(): void
    {
        $this->seedMedia(['default_disk' => 'local', 'disks' => ['local' => $this->fsDisk()]]);
        $this->assertInstanceOf(Tiger_Media_Storage_Filesystem::class, Tiger_Media_Storage::disk('local'));
    }

    #[Test]
    public function the_local_adapter_alias_maps_to_filesystem_too(): void
    {
        // 'local' and 'filesystem' are aliases for the same backend.
        $this->seedMedia(['disks' => ['d' => ['adapter' => 'local', 'public_root' => '/tmp/p', 'private_root' => '/tmp/q']]]);
        $this->assertInstanceOf(Tiger_Media_Storage_Filesystem::class, Tiger_Media_Storage::disk('d'));
    }

    #[Test]
    public function resolves_an_s3_disk_to_the_s3_adapter(): void
    {
        // Construct-only: the S3 client (and the AWS SDK) are lazy, so no network/credentials
        // are touched here — this just proves the factory's adapter switch reaches S3.
        $this->seedMedia(['disks' => ['cloud' => ['adapter' => 's3', 'bucket' => 'my-bucket', 'region' => 'us-east-1']]]);
        $this->assertInstanceOf(Tiger_Media_Storage_S3::class, Tiger_Media_Storage::disk('cloud'));
    }

    // ---- Memoization --------------------------------------------------------------------

    #[Test]
    public function the_same_disk_name_returns_the_identical_memoized_instance(): void
    {
        $this->seedMedia(['disks' => ['local' => $this->fsDisk()]]);
        $a = Tiger_Media_Storage::disk('local');
        $b = Tiger_Media_Storage::disk('local');
        $this->assertSame($a, $b, 'disk() must memoize per name, not rebuild');
    }

    #[Test]
    public function reset_clears_the_memo_so_the_next_call_rebuilds(): void
    {
        $this->seedMedia(['disks' => ['local' => $this->fsDisk()]]);
        $a = Tiger_Media_Storage::disk('local');
        Tiger_Media_Storage::reset();
        $b = Tiger_Media_Storage::disk('local');
        $this->assertNotSame($a, $b, 'after reset() a fresh instance must be built');
    }

    // ---- default_disk fallback ----------------------------------------------------------

    #[Test]
    public function disk_null_resolves_through_the_configured_default_disk(): void
    {
        $this->seedMedia(['default_disk' => 'local', 'disks' => ['local' => $this->fsDisk()]]);
        // A null name means "the default disk" — and it memoizes to the SAME instance as the
        // explicit name it resolves to.
        $this->assertSame(Tiger_Media_Storage::disk('local'), Tiger_Media_Storage::disk(null));
    }

    #[Test]
    public function default_disk_reads_the_configured_value(): void
    {
        $this->seedMedia(['default_disk' => 'assets', 'disks' => ['assets' => $this->fsDisk()]]);
        $this->assertSame('assets', Tiger_Media_Storage::defaultDisk());
    }

    #[Test]
    public function default_disk_falls_back_to_local_when_unset(): void
    {
        // media config present but no default_disk key.
        $this->seedMedia(['disks' => ['whatever' => $this->fsDisk()]]);
        $this->assertSame('local', Tiger_Media_Storage::defaultDisk());
    }

    #[Test]
    public function default_disk_falls_back_to_local_with_no_media_config_at_all(): void
    {
        // No Zend_Config in the registry at all (base setUp unset it) — still a safe 'local'.
        $this->assertSame('local', Tiger_Media_Storage::defaultDisk());
    }

    // ---- The two hard failures ----------------------------------------------------------

    #[Test]
    public function an_unknown_adapter_name_throws(): void
    {
        $this->seedMedia(['disks' => ['weird' => ['adapter' => 'quantum-blob']]]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/unknown adapter/i');
        Tiger_Media_Storage::disk('weird');
    }

    #[Test]
    public function a_disk_with_no_config_throws(): void
    {
        $this->seedMedia(['disks' => ['local' => $this->fsDisk()]]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no config for disk/i');
        Tiger_Media_Storage::disk('does-not-exist');
    }
}
