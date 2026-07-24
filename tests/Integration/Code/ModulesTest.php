<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Code;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Code_Modules;
use Tiger_Model_Code;

/**
 * Tiger_Code_Modules — file-based snippets shipped by installed `code` modules.
 *
 * A module snippet stays a FILE (`<module>/snippets/<id>.php`, a `tiger:snippet` header + a
 * define-only body); nothing is ever copied into the DB, and "active" is a small config set
 * (`tiger.code.modules`). These tests plant a throwaway fixture module under the harness app path
 * (APPLICATION_PATH/modules, removed in tearDown) and exercise the discovery + active-set surface:
 *   - `all()`/`get()` discover the file and parse its `tiger:snippet` hint (label/category/scope/
 *     description — the description spanning a second comment line, proving the multi-line parse);
 *   - `body()` normalizes the PHP (opening tag stripped) for the compiler; `source()` returns raw bytes;
 *   - `activeKeys()`/`setActive()`/`isActive()` flip a key in the config set (the live-override tier);
 *   - `activeForLoad()` returns only ACTIVE snippets whose scope matches the run location (an `admin`
 *     snippet is excluded from the `global` load).
 *
 * The config rows `setActive()` writes ride the per-test transaction (rolled back); the fixture files
 * are deleted in tearDown.
 */
#[CoversClass(Tiger_Code_Modules::class)]
final class ModulesTest extends IntegrationTestCase
{
    private const SLUG = 'w5codemod';

    private string $moduleDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->moduleDir = APPLICATION_PATH . '/modules/' . self::SLUG;
        $snip = $this->moduleDir . '/snippets';
        @mkdir($snip, 0777, true);

        // A global-scope snippet whose description spans a SECOND comment line (multi-line hint parse).
        file_put_contents($snip . '/hello.php', <<<'PHP'
<?php
// tiger:snippet label="Hello" category="Strings" scope="global"
//   description="w5_hello(): a tiny greeter helper."

if (!function_exists('w5_hello')) {
    function w5_hello() { return 'hi'; }
}
PHP);

        // An admin-scope snippet — must be excluded from a `global` activeForLoad().
        file_put_contents($snip . '/admincron.php', <<<'PHP'
<?php
// tiger:snippet label="Admin Cron" category="Ops" scope="admin"

if (!function_exists('w5_admin_cron')) {
    function w5_admin_cron() { return true; }
}
PHP);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->moduleDir);
        parent::tearDown();
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) { @unlink($dir); return; }
        foreach (scandir($dir) as $e) {
            if ($e === '.' || $e === '..') { continue; }
            $p = $dir . '/' . $e;
            is_dir($p) && !is_link($p) ? $this->rmrf($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    private function key(string $file): string
    {
        return self::SLUG . '/' . $file;
    }

    // ---- discovery + hint parsing --------------------------------------------------------------

    #[Test]
    public function all_discovers_the_fixture_snippets_and_parses_their_hints(): void
    {
        $all = Tiger_Code_Modules::all();

        $helloKey = $this->key('hello');
        $this->assertArrayHasKey($helloKey, $all, 'the file was discovered under the module snippets dir');

        $hello = $all[$helloKey];
        $this->assertSame($helloKey, $hello['key']);
        $this->assertSame(self::SLUG, $hello['module']);
        $this->assertSame('Hello', $hello['label']);
        $this->assertSame('Strings', $hello['category']);
        $this->assertSame('global', $hello['scope']);
        // The description sat on a SECOND comment line — proving the multi-line header parse.
        $this->assertSame('w5_hello(): a tiny greeter helper.', $hello['description']);
    }

    #[Test]
    public function get_returns_one_snippet_and_null_for_an_unknown_key(): void
    {
        $this->assertNotNull(Tiger_Code_Modules::get($this->key('admincron')));
        $this->assertNull(Tiger_Code_Modules::get(self::SLUG . '/does-not-exist'));
    }

    // ---- body() + source() ---------------------------------------------------------------------

    #[Test]
    public function body_normalizes_the_php_and_source_returns_raw_bytes(): void
    {
        $body = Tiger_Code_Modules::body($this->key('hello'));
        // normalize() strips the opening tag but keeps the definition.
        $this->assertStringNotContainsString('<?php', $body);
        $this->assertStringContainsString('function w5_hello', $body);

        $source = Tiger_Code_Modules::source($this->key('hello'));
        $this->assertStringContainsString('<?php', $source, 'source() is the raw, un-normalized file');
        $this->assertStringContainsString('tiger:snippet', $source);

        // A vanished key yields '' from both.
        $this->assertSame('', Tiger_Code_Modules::body(self::SLUG . '/missing'));
        $this->assertSame('', Tiger_Code_Modules::source(self::SLUG . '/missing'));
    }

    // ---- the active config set -----------------------------------------------------------------

    #[Test]
    public function set_active_toggles_the_config_set_and_is_active_reflects_it(): void
    {
        $key = $this->key('hello');

        $this->assertFalse(Tiger_Code_Modules::isActive($key), 'nothing is active by default');
        $this->assertNotContains($key, Tiger_Code_Modules::activeKeys());

        Tiger_Code_Modules::setActive($key, true);
        $this->assertTrue(Tiger_Code_Modules::isActive($key));
        $this->assertContains($key, Tiger_Code_Modules::activeKeys());

        Tiger_Code_Modules::setActive($key, false);
        $this->assertFalse(Tiger_Code_Modules::isActive($key));
        $this->assertNotContains($key, Tiger_Code_Modules::activeKeys());
    }

    // ---- activeForLoad(): active + scope-matched only ------------------------------------------

    #[Test]
    public function active_for_load_returns_only_active_scope_matched_snippets(): void
    {
        $hello = $this->key('hello');       // scope=global
        $admin = $this->key('admincron');   // scope=admin

        Tiger_Code_Modules::setActive($hello, true);
        Tiger_Code_Modules::setActive($admin, true);

        $global = Tiger_Code_Modules::activeForLoad(Tiger_Model_Code::LOC_GLOBAL);
        $this->assertArrayHasKey($hello, $global, 'the active global-scope snippet loads at global');
        $this->assertArrayNotHasKey($admin, $global, 'an admin-scope snippet never loads at global');

        // At the admin location, the admin one loads and the global one does not.
        $adminLoad = Tiger_Code_Modules::activeForLoad(Tiger_Model_Code::LOC_ADMIN);
        $this->assertArrayHasKey($admin, $adminLoad);
        $this->assertArrayNotHasKey($hello, $adminLoad);
    }

    #[Test]
    public function active_for_load_excludes_a_discovered_but_inactive_snippet(): void
    {
        // Discovered (all() sees it) but never activated → not in the load set.
        $this->assertArrayHasKey($this->key('hello'), Tiger_Code_Modules::all());
        $this->assertSame([], Tiger_Code_Modules::activeForLoad(Tiger_Model_Code::LOC_GLOBAL));
    }
}
