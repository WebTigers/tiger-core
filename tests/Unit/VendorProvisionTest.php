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
 * Tiger_Vendor — the tier-picking provisioner, driven WITHOUT a network. VendorTest already pins the
 * semver matcher + the SHA-256 gate; this covers the rest of the reachable surface:
 *
 *   - ensure()'s tier selection: the no-name guard, a Tier-3 source tarball (generate_autoload), a Tier-2
 *     declared bundle, the registry-index tier (_resolveFromIndex / _registryIndex / _registryUrl), the
 *     already-present dedup, and the ONE-VERSION conflict report (the security-relevant "we keep one shared
 *     copy" rule — a genuine disagreement is reported, never silently double-installed).
 *   - isInstalled() / installedVersion() (reads a bundle's bundle.json).
 *   - installAsset() into a module's own assets dir (front-end deps), incl. the idempotent + error branches.
 *   - registerAutoloaders() wiring a stored lib's autoload.php.
 *
 * Downloads use `file://` URLs (cURL serves them locally — see VendorTest), so nothing leaves the box.
 * Every write lands in the real shared store (APPLICATION_ROOT/vendor-libs); tearDown removes ONLY the
 * test packages this suite created, never a pre-existing store.
 */
#[CoversClass(Tiger_Vendor::class)]
final class VendorProvisionTest extends UnitTestCase
{
    private string $tmp = '';
    private bool $storePreExisted = false;
    /** Store slugs this test created (removed in tearDown). */
    private array $slugs = ['test-lib', 'test-bundle', 'test-idx', 'test-auto'];

    /**
     * A class-scoped, persistent registry index + its referenced bundle. Tiger_Vendor::_registryIndex()
     * caches the fetched index in a function-static for the whole PROCESS, so the FIRST test to touch it
     * fixes the index for the run — it must therefore live in a stable dir that outlives any one test's
     * tmp (whose file:// url would otherwise dangle after that test's tearDown). Every test points the
     * resolver here (via the env override in setUp), so no test ever reaches the real network default.
     */
    private static string $regDir = '';

    public static function setUpBeforeClass(): void
    {
        self::$regDir = sys_get_temp_dir() . '/tiger_vprov_reg_' . getmypid() . '_' . bin2hex(random_bytes(4));
        @mkdir(self::$regDir, 0775, true);
        // A persistent bundle for test/idx (version 3.2.0), and an index that lists two versions of it.
        $tar  = self::$regDir . '/idx.tar';
        $phar = new \PharData($tar);
        $phar->addFromString('test-idx/src/Widget.php', "<?php\nnamespace TestVendorLib;\nclass IdxWidget {}\n");
        $phar->addFromString('test-idx/bundle.json', json_encode(['version' => '3.2.0']));
        $phar->addFromString('test-idx/autoload.php', "<?php\n");
        $phar->compress(\Phar::GZ);
        unset($phar);
        file_put_contents(self::$regDir . '/bundles.json', json_encode(['bundles' => [
            'test/idx' => [
                ['version' => '3.1.0', 'url' => 'file://' . $tar . '.gz'],
                ['version' => '3.2.0', 'url' => 'file://' . $tar . '.gz'],   // newest satisfying pick
            ],
        ]]));
    }

    public static function tearDownAfterClass(): void
    {
        self::rrmdirStatic(self::$regDir);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/tiger_vprov_' . getmypid() . '_' . bin2hex(random_bytes(4));
        @mkdir($this->tmp, 0775, true);
        $this->storePreExisted = is_dir(Tiger_Vendor_Environment::storeDir());
        // Aim the registry resolver at the persistent test index — no real network, stable across the run.
        putenv('TIGER_VENDOR_REGISTRY=file://' . self::$regDir . '/bundles.json');
    }

    protected function tearDown(): void
    {
        putenv('TIGER_VENDOR_REGISTRY');   // clear any registry override we set
        $store = Tiger_Vendor_Environment::storeDir();
        if (!$this->storePreExisted && is_dir($store)) {
            $this->rrmdir($store);
        } else {
            foreach ($this->slugs as $slug) { $this->rrmdir($store . '/' . $slug); }
        }
        $this->rrmdir($this->tmp);
        parent::tearDown();
    }

    // ---- ensure(): guards + tiers ----------------------------------------------

    #[Test]
    public function ensureWithoutANameFails(): void
    {
        $r = Tiger_Vendor::ensure([]);
        $this->assertFalse($r['ok']);
        $this->assertSame('none', $r['tier']);
    }

    #[Test]
    public function ensureInstallsFromASourceTarballAndGeneratesAnAutoloader(): void
    {
        // Tier 3: a raw source tarball (no constraint, no bundle) → install + a generated autoload.php.
        $tar = $this->makeLibTarGz('test-auto', withComposerJson: true);

        $r = Tiger_Vendor::ensure(['name' => 'test/auto', 'tarball' => 'file://' . $tar]);
        $this->assertTrue($r['ok'], $r['message'] ?? '');
        $this->assertSame('tarball', $r['tier']);

        $dir = Tiger_Vendor_Environment::storeDir() . '/test-auto';
        $this->assertDirectoryExists($dir);
        $this->assertFileExists($dir . '/autoload.php', 'a raw lib gets a generated autoloader');
        $this->assertTrue(Tiger_Vendor::isInstalled('test/auto'));
    }

    #[Test]
    public function ensureInstallsFromADeclaredBundleThenReusesItAndReportsAConflict(): void
    {
        // Tier 2: a declared bundle carrying bundle.json (version 1.0.0).
        $tar = $this->makeLibTarGz('test-bundle', bundleVersion: '1.0.0');

        $r = Tiger_Vendor::ensure(['name' => 'test/bundle', 'bundle' => 'file://' . $tar, 'constraint' => '^1.0']);
        $this->assertTrue($r['ok'], $r['message'] ?? '');
        $this->assertSame('bundle', $r['tier']);
        $this->assertSame('1.0.0', Tiger_Vendor::installedVersion('test/bundle'));

        // Already present + the constraint still holds → reused (dedup), not re-downloaded.
        $reuse = Tiger_Vendor::ensure(['name' => 'test/bundle', 'constraint' => '^1.0']);
        $this->assertTrue($reuse['ok']);
        $this->assertSame('present', $reuse['tier']);

        // A constraint the installed copy does NOT satisfy → a reported conflict (one-version rule), NOT ok.
        $conflict = Tiger_Vendor::ensure(['name' => 'test/bundle', 'constraint' => '^2.0']);
        $this->assertFalse($conflict['ok']);
        $this->assertSame('conflict', $conflict['tier']);
        $this->assertStringContainsString('conflict', strtolower($conflict['message']));
    }

    #[Test]
    public function ensureResolvesABundleFromTheRegistryIndex(): void
    {
        // The persistent test index (setUpBeforeClass) lists test/idx @ 3.1.0 + 3.2.0 → resolve the newest
        // satisfying version and install it. Covers _resolveFromIndex / _registryIndex / _registryUrl (env).
        $r = Tiger_Vendor::ensure(['name' => 'test/idx', 'constraint' => '^3.0']);
        $this->assertTrue($r['ok'], $r['message'] ?? '');
        $this->assertSame('bundle', $r['tier']);
        $this->assertStringContainsString('3.2.0', $r['message'], 'it picks the newest satisfying version');
        $this->assertSame('3.2.0', Tiger_Vendor::installedVersion('test/idx'));
    }

    #[Test]
    public function ensureFailsClosedWhenNoTierCanProvide(): void
    {
        // No composer, no bundle/tarball, and the registry has nothing for this name → fail closed.
        $r = Tiger_Vendor::ensure(['name' => 'nobody/here', 'constraint' => '^1.0']);
        $this->assertFalse($r['ok']);
        $this->assertSame('none', $r['tier']);
    }

    // ---- installAsset() : front-end deps into a module dir ---------------------

    #[Test]
    public function installAssetCopiesIncludedFilesAndIsIdempotent(): void
    {
        $tar = $this->makeAssetTarGz();   // contains dist/widget.js + dist/widget.css
        $moduleDir = $this->tmp . '/mymodule';
        @mkdir($moduleDir, 0775, true);

        // Concrete include paths (not globs): the idempotency check keys on each include's BASENAME.
        $asset = ['name' => 'widget', 'tarball' => 'file://' . $tar, 'target' => 'assets/vendor', 'include' => ['dist/widget.js', 'dist/widget.css']];
        $r = Tiger_Vendor::installAsset($asset, $moduleDir);
        $this->assertTrue($r['ok'], $r['message'] ?? '');
        $this->assertFileExists($moduleDir . '/assets/vendor/widget.js');
        $this->assertFileExists($moduleDir . '/assets/vendor/widget.css');

        // Idempotent: a second call sees the basenames already present and no-ops "Already present".
        $again = Tiger_Vendor::installAsset($asset, $moduleDir);
        $this->assertTrue($again['ok']);
        $this->assertStringContainsString('Already present', $again['message']);
    }

    #[Test]
    public function installAssetRejectsAnUnderspecifiedAsset(): void
    {
        $r = Tiger_Vendor::installAsset(['name' => 'x'], $this->tmp);   // no url/target/include
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('include', strtolower($r['message']));
    }

    #[Test]
    public function installAssetReportsWhenNothingMatchesInclude(): void
    {
        $tar = $this->makeAssetTarGz();
        $moduleDir = $this->tmp . '/mod2';
        @mkdir($moduleDir, 0775, true);
        $r = Tiger_Vendor::installAsset(
            ['name' => 'widget', 'tarball' => 'file://' . $tar, 'target' => 'assets/x', 'include' => ['dist/nope.*']],
            $moduleDir
        );
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('No files matched', $r['message']);
    }

    // ---- registerAutoloaders() -------------------------------------------------

    #[Test]
    public function registerAutoloadersRequiresEveryStoredLibsAutoloader(): void
    {
        // Install a lib whose generated autoload.php maps a PSR-4 prefix; then registerAutoloaders() wires it
        // and the class resolves.
        $tar = $this->makeLibTarGz('test-lib', withComposerJson: true, psr4: ['TestVendorLib\\' => 'src/']);
        Tiger_Vendor::ensure(['name' => 'test/lib', 'tarball' => 'file://' . $tar]);

        Tiger_Vendor::registerAutoloaders();
        $this->assertTrue(class_exists('TestVendorLib\\Widget'), 'the stored lib autoloader should resolve its class');
    }

    // ---- helpers ---------------------------------------------------------------

    /**
     * Build a .tar.gz of a library, wrapped in a single top dir (as GitHub/source tarballs are), and
     * return its path. Optionally ship a composer.json (for autoload generation) and/or a bundle.json.
     */
    private function makeLibTarGz(string $top, bool $withComposerJson = false, ?string $bundleVersion = null, array $psr4 = ['TestVendorLib\\' => 'src/']): string
    {
        $base = $this->tmp . '/' . $top . '_' . bin2hex(random_bytes(3)) . '.tar';
        $phar = new \PharData($base);
        $phar->addFromString($top . '/src/Widget.php', "<?php\nnamespace TestVendorLib;\nclass Widget {}\n");
        if ($withComposerJson) {
            $phar->addFromString($top . '/composer.json', json_encode(['autoload' => ['psr-4' => $psr4]]));
        }
        if ($bundleVersion !== null) {
            $phar->addFromString($top . '/bundle.json', json_encode(['version' => $bundleVersion]));
            $phar->addFromString($top . '/autoload.php', "<?php\n// shipped bundle autoloader\n");
        }
        $phar->compress(\Phar::GZ);
        unset($phar);
        return $base . '.gz';
    }

    /** A .tar.gz of a front-end asset package (dist/widget.js + dist/widget.css), single top dir. */
    private function makeAssetTarGz(): string
    {
        $base = $this->tmp . '/asset_' . bin2hex(random_bytes(3)) . '.tar';
        $phar = new \PharData($base);
        $phar->addFromString('pkg/dist/widget.js', "console.log('w');\n");
        $phar->addFromString('pkg/dist/widget.css', ".w{}\n");
        $phar->compress(\Phar::GZ);
        unset($phar);
        return $base . '.gz';
    }

    private function rrmdir(string $dir): void
    {
        self::rrmdirStatic($dir);
    }

    private static function rrmdirStatic(string $dir): void
    {
        if (!is_dir($dir)) { return; }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') { continue; }
            $p = $dir . '/' . $item;
            (is_dir($p) && !is_link($p)) ? self::rrmdirStatic($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
