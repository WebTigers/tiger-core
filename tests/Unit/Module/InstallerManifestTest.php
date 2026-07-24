<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\Module;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Module_Installer;

/**
 * The installer's manifest + placement gatekeepers — the pure, no-DB parts of Tiger_Module_Installer:
 *   - _validSlug()     the slug allow-list: a regex shape + the RESERVED platform-namespace block.
 *   - _readManifest()  read module.json, or normalize a theme.json into the same module shape.
 *   - migrationPaths() the single authority for which migration dirs an install/CLI run scans.
 *
 * These decide WHERE untrusted package bytes are allowed to land and WHAT the installer believes a
 * package is, so their edges are security edges: a slug that escapes its charset could traverse out of
 * modules/; a reserved slug could clobber the platform's own namespace; a manifest with no identity must
 * be refused, not guessed. Protected statics are reached through a tiny subclass exposer.
 */
#[CoversClass(Tiger_Module_Installer::class)]
final class InstallerManifestTest extends UnitTestCase
{
    private string $sandbox = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->sandbox = sys_get_temp_dir() . '/tiger_manifest_' . getmypid() . '_' . bin2hex(random_bytes(4));
        @mkdir($this->sandbox, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->sandbox);
        parent::tearDown();
    }

    // ---- _validSlug ------------------------------------------------------------

    #[Test]
    public function validSlugsAreAccepted(): void
    {
        // Must start with [a-z0-9], then up to 63 more of [a-z0-9_-]; input is lower-cased + trimmed first.
        $this->assertSame('a', InstallerManifestProbe::validSlug('a'));
        $this->assertSame('billing', InstallerManifestProbe::validSlug('billing'));
        $this->assertSame('theme-aurora', InstallerManifestProbe::validSlug('theme-aurora'));
        $this->assertSame('code_utils-2', InstallerManifestProbe::validSlug('code_utils-2'));
        $this->assertSame('42day', InstallerManifestProbe::validSlug('42day'));   // may start with a digit
        $this->assertSame('billing', InstallerManifestProbe::validSlug('  Billing  ')); // trimmed + lower-cased
        $this->assertSame('a' . str_repeat('b', 63), InstallerManifestProbe::validSlug('a' . str_repeat('b', 63))); // 64 = max
    }

    #[Test]
    public function reservedSlugsAreRejected(): void
    {
        // Every platform namespace is blocked so a package can never install "over the platform".
        foreach (Tiger_Module_Installer::RESERVED as $reserved) {
            try {
                InstallerManifestProbe::validSlug($reserved);
                $this->fail("reserved slug '{$reserved}' should have thrown");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('Reserved', $e->getMessage());
            }
        }
    }

    #[Test]
    public function malformedAndTraversalSlugsAreRejected(): void
    {
        // Anything outside the charset — including path-traversal shapes — is an "Invalid" hard error.
        $bad = [
            '',                 // empty
            '-lead',            // must not start with - or _
            '_lead',
            'has space',        // whitespace mid-slug
            'UP.PER',           // dot is not in the charset
            '../evil',          // traversal
            'foo/bar',          // slash
            'foo\\bar',         // backslash
            'a' . str_repeat('b', 64), // 65 chars — one past the {0,63} tail
            'tab' . "\t" . 'x',
        ];
        foreach ($bad as $slug) {
            try {
                InstallerManifestProbe::validSlug($slug);
                $this->fail('malformed slug should have thrown: ' . var_export($slug, true));
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('Invalid', $e->getMessage());
            }
        }
    }

    // ---- _readManifest ---------------------------------------------------------

    #[Test]
    public function readsAModuleJsonManifest(): void
    {
        $dir = $this->sandbox . '/mod';
        @mkdir($dir, 0775, true);
        file_put_contents($dir . '/module.json', json_encode([
            'slug' => 'billing', 'name' => 'Billing', 'version' => '1.2.3',
        ]));

        $m = InstallerManifestProbe::readManifest($dir);
        $this->assertIsArray($m);
        $this->assertSame('billing', $m['slug']);
        $this->assertSame('Billing', $m['name']);
        $this->assertSame('1.2.3', $m['version']);
    }

    #[Test]
    public function moduleJsonWithoutASlugIsRejected(): void
    {
        $dir = $this->sandbox . '/noslug';
        @mkdir($dir, 0775, true);
        file_put_contents($dir . '/module.json', json_encode(['name' => 'Anon']));
        $this->assertNull(InstallerManifestProbe::readManifest($dir), 'a manifest with no slug is not installable');
    }

    #[Test]
    public function invalidJsonManifestIsRejected(): void
    {
        $dir = $this->sandbox . '/broken';
        @mkdir($dir, 0775, true);
        file_put_contents($dir . '/module.json', '{ this is not json ');
        $this->assertNull(InstallerManifestProbe::readManifest($dir));
    }

    #[Test]
    public function themeJsonIsNormalizedToTheModuleShape(): void
    {
        // A theme installs through the SAME path as a module: theme.json → slug "theme-<key>", type=theme.
        $dir = $this->sandbox . '/theme';
        @mkdir($dir, 0775, true);
        file_put_contents($dir . '/theme.json', json_encode([
            'key' => 'aurora', 'name' => 'Aurora', 'version' => '0.4.0', 'license' => 'MIT',
            'requires' => ['php' => '>=8.1'],
        ]));

        $m = InstallerManifestProbe::readManifest($dir);
        $this->assertIsArray($m);
        $this->assertSame('theme-aurora', $m['slug'], 'the theme key becomes a theme-<key> slug');
        $this->assertSame('theme', $m['type']);
        $this->assertSame('Aurora', $m['name']);
        $this->assertSame('0.4.0', $m['version']);
        $this->assertSame('MIT', $m['license']);
        $this->assertSame(['php' => '>=8.1'], $m['requires']);
    }

    #[Test]
    public function themeJsonWithoutAKeyIsRejected(): void
    {
        $dir = $this->sandbox . '/keyless';
        @mkdir($dir, 0775, true);
        file_put_contents($dir . '/theme.json', json_encode(['name' => 'Keyless']));
        $this->assertNull(InstallerManifestProbe::readManifest($dir), 'a theme with no key has no slug to install under');
    }

    #[Test]
    public function noManifestAtAllReturnsNull(): void
    {
        $dir = $this->sandbox . '/empty';
        @mkdir($dir, 0775, true);
        $this->assertNull(InstallerManifestProbe::readManifest($dir));
    }

    #[Test]
    public function moduleJsonWinsOverThemeJsonWhenBothArePresent(): void
    {
        // The reader checks module.json first — a repo shipping both is treated as a code module.
        $dir = $this->sandbox . '/both';
        @mkdir($dir, 0775, true);
        file_put_contents($dir . '/module.json', json_encode(['slug' => 'dual', 'name' => 'Dual']));
        file_put_contents($dir . '/theme.json', json_encode(['key' => 'dual', 'name' => 'DualTheme']));

        $m = InstallerManifestProbe::readManifest($dir);
        $this->assertSame('dual', $m['slug']);
        $this->assertArrayNotHasKey('type', $m, 'module.json path does not inject the theme type');
    }

    // ---- migrationPaths --------------------------------------------------------

    #[Test]
    public function migrationPathsIncludeCoreAppAndBothModuleRoots(): void
    {
        // bootstrap.php defines TIGER_CORE_PATH (repo root) and APPLICATION_PATH (a fixture app dir).
        $this->assertTrue(defined('TIGER_CORE_PATH') && defined('APPLICATION_PATH'), 'path constants must be defined by the bootstrap');

        $paths = Tiger_Module_Installer::migrationPaths();
        $this->assertIsArray($paths);

        // Core + app migration roots are always present, core first (precedence order).
        $this->assertContains(TIGER_CORE_PATH . '/migrations', $paths);
        $this->assertContains(APPLICATION_PATH . '/migrations', $paths);
        $this->assertLessThan(
            array_search(APPLICATION_PATH . '/migrations', $paths, true),
            array_search(TIGER_CORE_PATH . '/migrations', $paths, true),
            'core migrations must precede app migrations'
        );

        // BOTH module roots are scanned: at least one first-party core module ships a migrations/ dir,
        // and every returned per-module path sits under one of the two module roots (never elsewhere).
        $coreModuleRoot = TIGER_CORE_PATH . '/modules';
        $appModuleRoot  = APPLICATION_PATH . '/modules';
        $moduleMigrationDirs = array_values(array_filter($paths, static function ($p) use ($coreModuleRoot, $appModuleRoot) {
            return strpos($p, $coreModuleRoot . '/') === 0 || strpos($p, $appModuleRoot . '/') === 0;
        }));
        $this->assertNotEmpty(
            array_filter($moduleMigrationDirs, static fn ($p) => strpos($p, $coreModuleRoot . '/') === 0),
            'the first-party core modules root is scanned for migrations'
        );
        foreach ($moduleMigrationDirs as $p) {
            $this->assertStringEndsWith('/migrations', $p, 'a per-module entry is always a migrations/ dir');
        }
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

/** Test seam: expose the installer's protected manifest/slug helpers. */
final class InstallerManifestProbe extends Tiger_Module_Installer
{
    public static function validSlug(string $slug): string
    {
        return self::_validSlug($slug);
    }

    public static function readManifest(string $dir): ?array
    {
        return self::_readManifest($dir);
    }
}
