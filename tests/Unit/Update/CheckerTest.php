<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\Update;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Update_Checker;
use Tiger_Version;

/**
 * Tiger_Update_Checker — the "what has an update?" diff behind the WordPress-simple Updates screen.
 *
 * The remote checks (Packagist for core, GitHub for modules, a repo's CHANGELOG for notes) are file-cached,
 * so this suite drives the decision logic with NO network by PRE-SEEDING that cache: a primed `core.json`
 * makes core() decide update-or-not offline, and a primed notes cache makes notes() return without a fetch.
 * The pure comparators/parsers — _descriptor()'s update flag, _stripV(), _sliceChangelog(), and the
 * _cached() read-through — are pinned directly through a subclass exposer. The genuine HTTP bodies
 * (_latestCore, the per-module latestRef loop) are integration/live territory and left uncovered here.
 */
#[CoversClass(Tiger_Update_Checker::class)]
final class CheckerTest extends UnitTestCase
{
    /** @var string[] cache files this test wrote (removed in tearDown). */
    private array $wrote = [];

    protected function tearDown(): void
    {
        foreach ($this->wrote as $f) { @unlink($f); }
        parent::tearDown();
    }

    private function cacheDir(): string
    {
        return UpdateCheckerProbe::cacheDir();
    }

    /** Prime a remote-check cache entry so the checker resolves it offline. */
    private function primeCache(string $key, $value): void
    {
        $dir = $this->cacheDir();
        @mkdir($dir, 0775, true);
        $file = $dir . '/' . preg_replace('/[^a-z0-9._-]/i', '_', $key) . '.json';
        file_put_contents($file, json_encode(['v' => $value]));
        $this->wrote[] = $file;
    }

    // ---- _stripV ---------------------------------------------------------------

    #[Test]
    public function stripVDropsALeadingVAndTrims(): void
    {
        $this->assertSame('1.2.3', UpdateCheckerProbe::stripV('v1.2.3'));
        $this->assertSame('2', UpdateCheckerProbe::stripV('  V2  '));
        $this->assertSame('0.41.0-beta', UpdateCheckerProbe::stripV('0.41.0-beta'));
    }

    // ---- _descriptor : the update flag -----------------------------------------

    #[Test]
    public function descriptorFlagsAnUpdateOnlyWhenLatestExceedsInstalled(): void
    {
        $newer = UpdateCheckerProbe::descriptor('module', 'Widget', 'widget', '1.0.0', '1.4.0', 'installer', 'o/r', 'v1.4.0');
        $this->assertTrue($newer['update']);
        $this->assertSame('1.4.0', $newer['latest']);
        $this->assertSame('1.0.0', $newer['installed']);

        $same = UpdateCheckerProbe::descriptor('module', 'Widget', 'widget', '1.4.0', '1.4.0', 'installer', 'o/r', null);
        $this->assertFalse($same['update']);

        // A null "latest" (couldn't resolve) is not an update, and latest falls back to the installed version.
        $unknown = UpdateCheckerProbe::descriptor('core', 'TigerCore', 'tiger-core', '2.0.0', null, 'manual', 'x', null);
        $this->assertFalse($unknown['update']);
        $this->assertSame('2.0.0', $unknown['latest']);
    }

    // ---- _sliceChangelog -------------------------------------------------------

    #[Test]
    public function sliceChangelogExtractsTheMatchingVersionSection(): void
    {
        $md = "# Changelog\n\n## [1.4.0] — 2026-01-01\n- Added a thing\n- Fixed a bug\n\n## [1.3.0] — 2025-12-01\n- Older\n";
        $section = UpdateCheckerProbe::sliceChangelog($md, '1.4.0');
        $this->assertSame("- Added a thing\n- Fixed a bug", $section, 'the heading is dropped; content stops at the next ##');

        // A version with no section → null.
        $this->assertNull(UpdateCheckerProbe::sliceChangelog($md, '9.9.9'));

        // A present-but-empty section → null (nothing to show).
        $empty = "## [2.0.0]\n\n## [1.0.0]\n- x\n";
        $this->assertNull(UpdateCheckerProbe::sliceChangelog($empty, '2.0.0'));
    }

    // ---- _cached : read-through ------------------------------------------------

    #[Test]
    public function cachedWritesOnMissThenReadsWithoutReRunning(): void
    {
        $key = 'w4-cached-' . bin2hex(random_bytes(3));
        $this->wrote[] = $this->cacheDir() . '/' . $key . '.json';

        $calls = 0;
        $fn = function () use (&$calls) { $calls++; return 'resolved-value'; };

        $this->assertSame('resolved-value', UpdateCheckerProbe::cached($key, false, $fn));
        $this->assertSame('resolved-value', UpdateCheckerProbe::cached($key, false, $fn));
        $this->assertSame(1, $calls, 'the second read is served from the file cache, not re-run');
    }

    // ---- core() / all() / available() with a primed cache ----------------------

    #[Test]
    public function coreFlagsAnUpdateWhenPackagistIsNewer(): void
    {
        $this->primeCache('core', '99.0.0');   // pretend Packagist has a much newer release

        $core = Tiger_Update_Checker::core();
        $this->assertIsArray($core);
        $this->assertSame('core', $core['type']);
        $this->assertSame('tiger-core', $core['slug']);
        $this->assertSame('TigerCore', $core['name']);
        $this->assertSame(UpdateCheckerProbe::stripV(Tiger_Version::VERSION), $core['installed']);
        $this->assertTrue($core['update']);
        $this->assertContains($core['method'], ['composer', 'manual']);
    }

    #[Test]
    public function coreShowsNoUpdateWhenTheLatestMatchesInstalled(): void
    {
        $this->primeCache('core', Tiger_Version::VERSION);
        $core = Tiger_Update_Checker::core();
        $this->assertFalse($core['update'], 'installed == latest → nothing to do');
    }

    #[Test]
    public function allIncludesCoreAndAvailableFiltersToPendingOnly(): void
    {
        // In the unit harness there is no module inventory to diff (bySlugMap fails soft to []), so all()
        // is just the core descriptor — enough to prove the aggregate + the available() filter.
        $this->primeCache('core', '99.0.0');

        $all = Tiger_Update_Checker::all();
        $this->assertNotEmpty($all);
        $core = array_values(array_filter($all, static fn ($u) => $u['type'] === 'core'));
        $this->assertCount(1, $core);

        $available = Tiger_Update_Checker::available();
        $this->assertNotEmpty($available, 'the newer core should surface as available');
        foreach ($available as $u) {
            $this->assertTrue($u['update'], 'available() returns only items with a pending update');
        }
    }

    #[Test]
    public function availableIsEmptyWhenNothingIsStale(): void
    {
        $this->primeCache('core', Tiger_Version::VERSION);
        $this->assertSame([], Tiger_Update_Checker::available());
    }

    // ---- notes() ---------------------------------------------------------------

    #[Test]
    public function notesReturnsNullWithoutARepositoryOrVersion(): void
    {
        $this->assertNull(Tiger_Update_Checker::notes(['slug' => 'x', 'latest' => '1.0.0']), 'no repository → null');
        $this->assertNull(Tiger_Update_Checker::notes(['slug' => 'x', 'repository' => 'o/r', 'latest' => '']), 'no version → null');
    }

    #[Test]
    public function notesServesAPrimedChangelogSectionOffline(): void
    {
        $u = ['slug' => 'widget', 'repository' => 'acme/widget', 'latest' => 'v1.4.0', 'ref' => 'v1.4.0'];
        // notes() cache key: 'notes-<slug>-<strippedVersion>'.
        $this->primeCache('notes-widget-1.4.0', "- Added a thing\n- Fixed a bug");

        $this->assertSame("- Added a thing\n- Fixed a bug", Tiger_Update_Checker::notes($u));
    }
}

/** Test seam: expose Tiger_Update_Checker's protected comparators/parsers + the cache dir. */
final class UpdateCheckerProbe extends Tiger_Update_Checker
{
    public static function stripV($v): string { return self::_stripV($v); }
    public static function cacheDir(): string { return self::_cacheDir(); }
    public static function sliceChangelog($md, $version) { return self::_sliceChangelog($md, $version); }
    public static function cached($key, $refresh, callable $fn) { return self::_cached($key, $refresh, $fn); }

    public static function descriptor($type, $name, $slug, $installed, $latest, $method, $repository, $ref): array
    {
        return self::_descriptor($type, $name, $slug, $installed, $latest, $method, $repository, $ref);
    }
}
