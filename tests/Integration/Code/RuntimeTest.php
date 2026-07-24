<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Code;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Code_Runtime;
use Tiger_Model_Code;

/**
 * Tiger_Code_Runtime — the compile-to-one-validated-bundle gate (CODE.md §4/§5). This is the
 * load-bearing security rail of the whole Code Area: only a bundle that passes a whole-file
 * `php -l` is ever promoted (atomic rename), so a syntactically-broken snippet or a
 * redeclare-conflicting active set can NEVER go live — the last-good bundle keeps serving.
 * And server PHP is compiled ONLY from platform-scope rows (`org_id = ''`); a tenant-scoped
 * row must never enter the shared server bundle (the RCE boundary enforced in compile(),
 * not just the UI).
 *
 * These exercise compile() directly against a distinctive run_location ("testrce") so the test
 * bundles are isolated from any real `global` bundle and from each other; the written bundle
 * files are cleaned up in tearDown.
 */
#[CoversClass(Tiger_Code_Runtime::class)]
final class RuntimeTest extends IntegrationTestCase
{
    private const LOC = 'testrce';

    private string $cacheDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheDir = APPLICATION_ROOT . '/storage/cache/code';
    }

    protected function tearDown(): void
    {
        // Remove only the bundles/manifests THIS test wrote (scoped to our isolated location).
        foreach (glob($this->cacheDir . '/' . self::LOC . '.*.php') ?: [] as $f) { @unlink($f); }
        foreach (glob($this->cacheDir . '/inject.' . self::LOC . '.*.php') ?: [] as $f) { @unlink($f); }
        @unlink($this->cacheDir . '/DISABLED');
        parent::tearDown();
    }

    /** Insert an active PHP snippet row directly (bypassing the service's lint) for compile() to pick up. */
    private function insertPhp(string $name, string $code, string $orgId = '', int $priority = 100): string
    {
        return (new Tiger_Model_Code())->insert([
            'org_id'       => $orgId,
            'name'         => $name,
            'language'     => Tiger_Model_Code::LANG_PHP,
            'code'         => $code,
            'run_location' => self::LOC,
            'priority'     => $priority,
            'active'       => 1,
            'status'       => Tiger_Model_Code::STATUS_ACTIVE,
        ]);
    }

    #[Test]
    public function a_valid_active_set_compiles_and_is_promoted(): void
    {
        $this->insertPhp('ok', "if (!function_exists('tiger_rce_ok')) { function tiger_rce_ok() { return 42; } }");

        $file = Tiger_Code_Runtime::compile(self::LOC, 1);

        $this->assertFileExists($file, 'a valid bundle is written + promoted (atomic rename)');
        $this->assertStringContainsString('tiger_rce_ok', file_get_contents($file), 'the active snippet is in the bundle');
    }

    #[Test]
    public function a_syntactically_broken_snippet_is_never_promoted_and_last_good_keeps_serving(): void
    {
        // v1: a valid, promoted bundle (the "last good").
        $this->insertPhp('good', "if (!function_exists('tiger_rce_good')) { function tiger_rce_good() { return 1; } }");
        $v1 = Tiger_Code_Runtime::compile(self::LOC, 1);
        $this->assertFileExists($v1);

        // Now the active set contains a parse error. Compiling v2 must THROW and promote nothing.
        $this->insertPhp('broken', 'function tiger_rce_broken( {');   // deliberate syntax error

        try {
            Tiger_Code_Runtime::compile(self::LOC, 2);
            $this->fail('a broken bundle must not compile');
        } catch (RuntimeException $e) {
            $this->assertNotSame('', $e->getMessage(), 'the compiler surfaces the php -l error');
        }

        $this->assertFileDoesNotExist(
            $this->cacheDir . '/' . self::LOC . '.2.php',
            'the invalid v2 bundle was never promoted'
        );
        $this->assertFileExists($v1, 'the last-good v1 bundle still serves');
    }

    #[Test]
    public function a_redeclare_conflicting_active_set_is_rejected_by_the_whole_bundle_lint(): void
    {
        // Two UNGUARDED definitions of the same function — each lints fine alone, but the assembled
        // bundle is a "Cannot redeclare" fatal that only the whole-file php -l can see (CODE.md §4).
        $this->insertPhp('dup-a', 'function tiger_rce_dup() { return 1; }', '', 10);
        $this->insertPhp('dup-b', 'function tiger_rce_dup() { return 2; }', '', 20);

        $this->expectException(RuntimeException::class);
        Tiger_Code_Runtime::compile(self::LOC, 1);
    }

    #[Test]
    public function server_php_compiles_only_from_platform_scope_rows_never_a_tenant_row(): void
    {
        // A platform row (org_id='') and a tenant row (org_id='org-x'), both active, same location.
        $this->insertPhp('platform', "if (!function_exists('tiger_platform_fn')) { function tiger_platform_fn() {} }", '');
        $this->insertPhp('tenant',   "if (!function_exists('tiger_tenant_fn'))   { function tiger_tenant_fn() {} }",   'org-x');

        $bundle = file_get_contents(Tiger_Code_Runtime::compile(self::LOC, 1));

        $this->assertStringContainsString('tiger_platform_fn', $bundle, 'the platform-scope snippet compiles in');
        $this->assertStringNotContainsString(
            'tiger_tenant_fn',
            $bundle,
            'a tenant-scoped row NEVER enters the server bundle — the RCE boundary is in compile(), not the UI'
        );
    }

    #[Test]
    public function the_kill_switch_disabled_file_turns_execution_off(): void
    {
        // Default (no DISABLED file, no config node) is enabled.
        @unlink($this->cacheDir . '/DISABLED');
        $this->assertTrue(Tiger_Code_Runtime::enabled(), 'enabled by default');

        // The fastest recovery of all: a DISABLED file in the cache dir hard-stops execution.
        if (!is_dir($this->cacheDir)) { @mkdir($this->cacheDir, 0775, true); }
        file_put_contents($this->cacheDir . '/DISABLED', '1');
        $this->assertFalse(Tiger_Code_Runtime::enabled(), 'the DISABLED kill-switch file disables execution');
    }
}
