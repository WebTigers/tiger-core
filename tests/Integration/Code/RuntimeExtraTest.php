<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Code;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Code_Runtime;
use Tiger_Log;
use Tiger_Model_Code;
use Zend_Config;
use Zend_Registry;

/**
 * Tiger_Code_Runtime — the loader/compiler paths beyond RuntimeTest's promote-gate + RCE-boundary.
 *
 * RuntimeTest pinned compile()'s validation gate and scope boundary. This drives the rest of the
 * per-request machinery. Each test uses its OWN letters-only run location (`codew…`) for two reasons:
 * the bundle filename is `preg_replace('/[^a-z]/', '', location)` (digits would be stripped from the
 * path but not the DB row), and `boot()` memoizes per location in a process-static — so a fresh
 * location per test avoids a short-circuit. Every bundle/manifest/asset file is cleaned in tearDown.
 *
 * Covered:
 *   - `boot()` — the happy load (compile-if-missing → include → the snippet's function is defined);
 *     the version-0 short-circuit; and SELF-HEAL — a snippet that throws a (catchable) Error while
 *     loading is auto-deactivated (`status=error`, `active=0`) so the next request recovers;
 *   - `rebuild()` — bumps the `tiger.code.version` token monotonically + writes the bundle;
 *   - `enabled()`/`version()` — the kill-switch (DISABLED file / config) and the version token;
 *   - the CLIENT tier — `compileClient()` writes versioned public css/js assets + a private inject
 *     manifest, and `injectManifest()` compiles-if-missing.
 *
 * `boot()`/`version()`/`enabled()` read the version+kill-switch from the already-loaded `Zend_Config`
 * (registry), so these tests seed that node and restore the prior config in tearDown.
 */
#[CoversClass(Tiger_Code_Runtime::class)]
final class RuntimeExtraTest extends IntegrationTestCase
{
    private string $cacheDir;
    private string $publicDir;
    private ?Zend_Config $priorConfig = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheDir  = APPLICATION_ROOT . '/storage/cache/code';
        $this->publicDir = APPLICATION_ROOT . '/public/_code';
        $this->priorConfig = Zend_Registry::isRegistered('Zend_Config') ? Zend_Registry::get('Zend_Config') : null;
    }

    protected function tearDown(): void
    {
        // All this suite's locations share the 'codew' prefix (letters-only), so glob them away.
        foreach (glob($this->cacheDir . '/codew*.php') ?: [] as $f) { @unlink($f); }
        foreach (glob($this->cacheDir . '/inject.codew*.php') ?: [] as $f) { @unlink($f); }
        foreach (glob($this->publicDir . '/codew*') ?: [] as $f) { @unlink($f); }
        @unlink($this->cacheDir . '/DISABLED');
        Tiger_Log::reset();

        if ($this->priorConfig !== null) {
            Zend_Registry::set('Zend_Config', $this->priorConfig);
        } elseif (Zend_Registry::isRegistered('Zend_Config')) {
            Zend_Registry::set('Zend_Config', new Zend_Config([]));
        }
        parent::tearDown();
    }

    /**
     * Seed the tiger.code config node boot()/version()/enabled() read from the registry. A null log
     * sink rides along so the self-heal's WARN doesn't trip the strict output check.
     */
    private function seedCodeConfig(int $version, int $enabled = 1): void
    {
        Zend_Registry::set('Zend_Config', new Zend_Config([
            'tiger' => [
                'code' => ['version' => $version, 'enabled' => $enabled],
                'log'  => ['writer' => 'null'],
            ],
        ]));
        Tiger_Log::reset();   // drop any cached logger so it re-reads the null writer
    }

    private function insertPhp(string $location, string $name, string $code, string $orgId = ''): string
    {
        return (new Tiger_Model_Code())->insert([
            'org_id'       => $orgId,
            'name'         => $name,
            'language'     => Tiger_Model_Code::LANG_PHP,
            'code'         => $code,
            'run_location' => $location,
            'priority'     => 100,
            'active'       => 1,
            'status'       => Tiger_Model_Code::STATUS_ACTIVE,
        ]);
    }

    private function insertClient(string $location, string $name, string $language, string $code): string
    {
        return (new Tiger_Model_Code())->insert([
            'org_id'       => '',
            'name'         => $name,
            'language'     => $language,
            'code'         => $code,
            'run_location' => $location,
            'auto_insert'  => Tiger_Model_Code::AUTO_HEAD,
            'priority'     => 100,
            'active'       => 1,
            'status'       => Tiger_Model_Code::STATUS_ACTIVE,
        ]);
    }

    // ---- boot(): the happy per-request load ----------------------------------------------------

    #[Test]
    public function boot_compiles_and_includes_the_active_bundle(): void
    {
        $loc = 'codewboot';
        $this->insertPhp($loc, 'greeter', "if (!function_exists('w5_boot_fn')) { function w5_boot_fn() { return 7; } }");
        $this->seedCodeConfig(1);   // version 1 → boot compiles v1 if missing, then includes it

        Tiger_Code_Runtime::boot($loc);

        $this->assertTrue(function_exists('w5_boot_fn'), 'the active snippet was compiled + loaded');
        $this->assertSame(7, w5_boot_fn());
    }

    #[Test]
    public function boot_is_a_no_op_when_the_version_token_is_zero(): void
    {
        $loc = 'codewzero';
        $this->insertPhp($loc, 'never', "if (!function_exists('w5_never_fn')) { function w5_never_fn() {} }");
        $this->seedCodeConfig(0);   // version 0 = nothing ever activated → boot returns early

        Tiger_Code_Runtime::boot($loc);
        $this->assertFalse(function_exists('w5_never_fn'), 'version 0 short-circuits boot (no compile)');
    }

    // ---- boot(): self-heal on a catchable load error -------------------------------------------

    #[Test]
    public function boot_auto_deactivates_a_snippet_that_throws_while_loading(): void
    {
        // Calling an undefined function at load throws a CATCHABLE Error in PHP 8 → boot() catches it,
        // marks the running snippet errored, and rebuilds so the next request is clean.
        $loc = 'codewheal';
        $id = $this->insertPhp($loc, 'self-destruct', 'w5_undefined_function_zzz();');
        $this->seedCodeConfig(1);

        Tiger_Code_Runtime::boot($loc);

        $row = (new Tiger_Model_Code())->findById($id);
        $this->assertNotNull($row);
        $this->assertSame(Tiger_Model_Code::STATUS_ERROR, $row['status'], 'the failing snippet was flagged errored');
        $this->assertSame(0, (int) $row['active'], 'and deactivated so it never runs again');
        $this->assertNotEmpty($row['last_error'], 'the killing error was recorded');
    }

    // ---- rebuild(): the version token ----------------------------------------------------------

    #[Test]
    public function rebuild_bumps_the_version_token_monotonically(): void
    {
        $loc = 'codewrebuild';
        $this->insertPhp($loc, 'r', "if (!function_exists('w5_rebuild_fn')) { function w5_rebuild_fn() {} }");

        $v1 = Tiger_Code_Runtime::rebuild($loc);
        $v2 = Tiger_Code_Runtime::rebuild($loc);

        $this->assertGreaterThan(0, $v1);
        $this->assertSame($v1 + 1, $v2, 'each rebuild increments the version token');
        // The compiled bundle for the newest version is on disk (filename is letters-only).
        $this->assertFileExists($this->cacheDir . '/' . $loc . '.' . $v2 . '.php');
    }

    // ---- enabled() / version(): the kill-switch + token ----------------------------------------

    #[Test]
    public function enabled_defaults_true_and_the_config_flag_and_disabled_file_both_turn_it_off(): void
    {
        @unlink($this->cacheDir . '/DISABLED');

        // No config node at all → default enabled.
        Zend_Registry::set('Zend_Config', new Zend_Config([]));
        $this->assertTrue(Tiger_Code_Runtime::enabled());

        // config enabled=0 → disabled.
        $this->seedCodeConfig(3, 0);
        $this->assertFalse(Tiger_Code_Runtime::enabled());

        // Even with enabled=1, the DISABLED file wins (fastest recovery).
        $this->seedCodeConfig(3, 1);
        $this->assertTrue(Tiger_Code_Runtime::enabled());
        if (!is_dir($this->cacheDir)) { @mkdir($this->cacheDir, 0775, true); }
        file_put_contents($this->cacheDir . '/DISABLED', '1');
        $this->assertFalse(Tiger_Code_Runtime::enabled(), 'the DISABLED file hard-stops execution');
    }

    #[Test]
    public function version_reads_the_config_token(): void
    {
        Zend_Registry::set('Zend_Config', new Zend_Config([]));
        $this->assertSame(0, Tiger_Code_Runtime::version(), 'no config node → 0');

        $this->seedCodeConfig(42);
        $this->assertSame(42, Tiger_Code_Runtime::version());
    }

    // ---- the client tier: compileClient() + injectManifest() -----------------------------------

    #[Test]
    public function compile_client_writes_public_assets_and_the_inject_manifest(): void
    {
        $loc = 'codewclient';
        $this->insertClient($loc, 'brand-css', Tiger_Model_Code::LANG_CSS, 'body { color: rebeccapurple; }');
        $this->insertClient($loc, 'tracker-js', Tiger_Model_Code::LANG_JS, 'window.__w5 = 1;');

        $version = 5;
        Tiger_Code_Runtime::compileClient($loc, $version);

        // Versioned public assets exist for both tiers.
        $this->assertFileExists($this->publicDir . '/' . $loc . '.' . $version . '.css');
        $this->assertFileExists($this->publicDir . '/' . $loc . '.' . $version . '.head.js');

        // injectManifest() returns the head items pointing at those assets.
        $manifest = Tiger_Code_Runtime::injectManifest($version, $loc);
        $this->assertArrayHasKey('head', $manifest);
        $types = array_column($manifest['head'], 'type');
        $this->assertContains('css_asset', $types);
        $this->assertContains('js_asset', $types);
    }

    #[Test]
    public function inject_manifest_compiles_if_missing(): void
    {
        $loc = 'codewinject';
        $this->insertClient($loc, 'inline-html', Tiger_Model_Code::LANG_HTML, '<meta name="w5" content="1">');

        // No manifest on disk yet for this version → injectManifest() compiles it on demand.
        $version = 9;
        $mf = $this->cacheDir . '/inject.' . $loc . '.' . $version . '.php';
        @unlink($mf);

        $manifest = Tiger_Code_Runtime::injectManifest($version, $loc);

        $this->assertFileExists($mf, 'the manifest was compiled on demand');
        $inlineTypes = array_column($manifest['head'], 'type');
        $this->assertContains('html', $inlineTypes, 'the inline html item is in the head manifest');
    }
}
