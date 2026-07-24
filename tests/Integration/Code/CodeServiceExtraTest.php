<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Code;

use Code_Service_Code;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Code_Modules;
use Tiger_Model_Code;
use Zend_Registry;

/**
 * Code_Service_Code — the surface Wave 3 left uncovered: the datatable's MODULE-snippet merge, the local
 * activate/deactivate happy path, the module-snippet toggle (_toggleModule), the moduleSource "view
 * source" read, and the restore guard. Together these characterize the two-origin Code Area (local `code`
 * rows + file-based module snippets) and the compile-and-rebuild rails.
 *
 * A throwaway fixture `code` module is planted under the harness app path (APPLICATION_PATH/modules,
 * removed in tearDown), so module discovery has a real snippet file. The service is superadmin-gated
 * (authoring server PHP is the top privilege), so every test runs as superadmin. `tiger.auth.stateless`
 * is flipped for the CSRF-free service dispatch, and any bundle a rebuild wrote is scrubbed in tearDown.
 */
#[CoversClass(Code_Service_Code::class)]
final class CodeServiceExtraTest extends IntegrationTestCase
{
    private const SLUG = 'w6codemod';

    private string $moduleDir;

    protected function setUp(): void
    {
        parent::setUp();
        Zend_Registry::set('tiger.auth.stateless', true);

        $this->moduleDir = APPLICATION_PATH . '/modules/' . self::SLUG;
        $snip = $this->moduleDir . '/snippets';
        @mkdir($snip, 0777, true);
        file_put_contents($snip . '/greet.php', <<<'PHP'
<?php
// tiger:snippet label="Greeter" category="Strings" scope="global"
//   description="w6_greet(): a tiny greeter."

if (!function_exists('w6_greet')) {
    function w6_greet() { return 'hi'; }
}
PHP);
    }

    protected function tearDown(): void
    {
        Zend_Registry::set('tiger.auth.stateless', false);
        $this->rmrf($this->moduleDir);
        $dir = APPLICATION_ROOT . '/storage/cache/code';
        foreach (glob($dir . '/global.*.php') ?: [] as $f) { @unlink($f); }
        foreach (glob($dir . '/inject.global.*.php') ?: [] as $f) { @unlink($f); }
        foreach (glob(APPLICATION_ROOT . '/public/_code/global.*') ?: [] as $f) { @unlink($f); }
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

    private function call(string $action, array $params = []): object
    {
        return (new Code_Service_Code(['action' => $action] + $params))->getResponse();
    }

    private function key(string $file): string
    {
        return self::SLUG . '/' . $file;
    }

    // ----- datatable: the two-origin merge ------------------------------------------------------

    #[Test]
    public function datatable_merges_local_rows_and_module_snippets(): void
    {
        $this->loginAs('superadmin');
        (new Tiger_Model_Code())->insert([
            'org_id' => '', 'name' => 'Local Helper', 'language' => Tiger_Model_Code::LANG_PHP,
            'code' => "if(!function_exists('w6_local')){function w6_local(){}}",
            'run_location' => Tiger_Model_Code::LOC_GLOBAL, 'active' => 0, 'status' => Tiger_Model_Code::STATUS_DRAFT,
        ]);

        $data = $this->call('datatable', ['draw' => 3, 'start' => 0, 'length' => 100])->data;
        $this->assertSame(3, $data['draw']);

        $bySource = ['local' => 0, 'module' => 0];
        $moduleRow = null;
        foreach ($data['data'] as $r) {
            $bySource[$r['source']] = ($bySource[$r['source']] ?? 0) + 1;
            if ($r['source'] === 'module' && $r['code_id'] === 'module:' . $this->key('greet')) { $moduleRow = $r; }
        }
        $this->assertGreaterThanOrEqual(1, $bySource['local'], 'the local `code` row is listed');
        $this->assertNotNull($moduleRow, 'the discovered module snippet is merged in');
        $this->assertSame('Greeter', $moduleRow['name']);
        $this->assertSame(self::SLUG, $moduleRow['module']);
        $this->assertTrue($moduleRow['can_view'], 'module rows are read-only view-source');
        $this->assertFalse($moduleRow['can_edit']);
        $this->assertFalse($moduleRow['active'], 'not active until toggled');
    }

    #[Test]
    public function datatable_language_filter_for_css_excludes_php_module_snippets(): void
    {
        $this->loginAs('superadmin');
        // Module snippets are all PHP, so a non-php language filter must drop them.
        $data = $this->call('datatable', ['draw' => 1, 'start' => 0, 'length' => 100, 'language' => Tiger_Model_Code::LANG_CSS])->data;
        $sources = array_column($data['data'], 'source');
        $this->assertNotContains('module', $sources, 'no PHP module snippet appears under a css filter');
    }

    #[Test]
    public function datatable_search_filters_module_snippets_by_their_hint_text(): void
    {
        $this->loginAs('superadmin');
        // A search that matches a LOCAL row but NOT the module snippet's hint → the module loop's
        // no-match `continue` branch runs (the module snippet is excluded).
        (new Tiger_Model_Code())->insert([
            'org_id' => '', 'name' => 'Zebra Local', 'language' => Tiger_Model_Code::LANG_PHP,
            'code' => "if(!function_exists('w6_zebra')){function w6_zebra(){}}",
            'run_location' => Tiger_Model_Code::LOC_GLOBAL, 'active' => 0, 'status' => Tiger_Model_Code::STATUS_DRAFT,
        ]);

        $data = $this->call('datatable', ['draw' => 1, 'start' => 0, 'length' => 100, 'search' => 'Zebra'])->data;
        $sources = array_column($data['data'], 'source');
        $this->assertNotContains('module', $sources, 'the module snippet is filtered out by a non-matching search');

        // A search that DOES match the module snippet's label surfaces it.
        $hit = $this->call('datatable', ['draw' => 1, 'start' => 0, 'length' => 100, 'search' => 'Greeter'])->data;
        $names = array_column($hit['data'], 'name');
        $this->assertContains('Greeter', $names, 'a matching search surfaces the module snippet');
    }

    // ----- moduleSource: read live from the file ------------------------------------------------

    #[Test]
    public function module_source_returns_the_live_file_body(): void
    {
        $this->loginAs('superadmin');
        $res = $this->call('moduleSource', ['code_id' => 'module:' . $this->key('greet')]);

        $this->assertSame(1, (int) $res->result);
        $this->assertSame($this->key('greet'), $res->data['key']);
        $this->assertSame('Greeter', $res->data['name']);
        $this->assertSame(self::SLUG, $res->data['module']);
        $this->assertFalse($res->data['active']);
        $this->assertStringContainsString('function w6_greet', $res->data['source'], 'the raw file source is read live');
    }

    // ----- local activate/deactivate happy path (setActive is a plain update — no nested txn) ----

    #[Test]
    public function activate_then_deactivate_a_local_snippet_flips_active_and_rebuilds(): void
    {
        $this->loginAs('superadmin');
        $id = (new Tiger_Model_Code())->insert([
            'org_id' => '', 'name' => 'Toggle Me', 'language' => Tiger_Model_Code::LANG_PHP,
            'code' => "if(!function_exists('w6_toggle')){function w6_toggle(){return 1;}}",
            'run_location' => Tiger_Model_Code::LOC_GLOBAL, 'active' => 0, 'status' => Tiger_Model_Code::STATUS_DRAFT,
        ]);

        $on = $this->call('activate', ['code_id' => $id]);
        $this->assertSame(1, (int) $on->result, 'activate succeeds and the bundle rebuilds');
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT active FROM code WHERE code_id = ?', [$id]));

        $off = $this->call('deactivate', ['code_id' => $id]);
        $this->assertSame(1, (int) $off->result);
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT active FROM code WHERE code_id = ?', [$id]));
    }

    #[Test]
    public function activating_a_local_snippet_with_a_parse_error_is_refused(): void
    {
        $this->loginAs('superadmin');
        // A row can only reach the DB active-lint via activate; seed broken PHP directly, then try to turn it on.
        $id = (new Tiger_Model_Code())->insert([
            'org_id' => '', 'name' => 'Broken', 'language' => Tiger_Model_Code::LANG_PHP,
            'code' => 'function broken( {', 'run_location' => Tiger_Model_Code::LOC_GLOBAL,
            'active' => 0, 'status' => Tiger_Model_Code::STATUS_DRAFT,
        ]);

        $res = $this->call('activate', ['code_id' => $id]);
        $this->assertSame(0, (int) $res->result, 'a parse error cannot be activated');
        $this->assertStringContainsString('Cannot activate', json_encode($res->messages));
    }

    // ----- module-snippet toggle (_toggleModule) ------------------------------------------------

    #[Test]
    public function toggling_a_module_snippet_flips_the_config_active_set(): void
    {
        $this->loginAs('superadmin');
        $key = $this->key('greet');
        $this->assertFalse(Tiger_Code_Modules::isActive($key), 'inactive to start');

        $on = $this->call('activate', ['code_id' => 'module:' . $key]);
        $this->assertSame(1, (int) $on->result, 'the module snippet activates (config flag, not a code row)');
        $this->assertTrue(Tiger_Code_Modules::isActive($key), 'the config active-set now carries the key');

        $off = $this->call('deactivate', ['code_id' => 'module:' . $key]);
        $this->assertSame(1, (int) $off->result);
        $this->assertFalse(Tiger_Code_Modules::isActive($key), 'the key is removed from the active-set');
    }

    #[Test]
    public function toggling_an_unknown_module_snippet_is_a_clean_error(): void
    {
        $this->loginAs('superadmin');
        $res = $this->call('activate', ['code_id' => 'module:' . self::SLUG . '/does-not-exist']);
        $this->assertSame(0, (int) $res->result, 'a vanished module snippet fails cleanly, no crash');
        $this->assertStringNotContainsString('not_allowed', json_encode($res->messages), 'it failed on the lookup, not the ACL');
    }

    // ----- save happy path (the SavepointAdapter nests the model's own write+version txn) --------

    #[Test]
    public function save_stores_valid_php_lints_it_and_rebuilds_the_bundle(): void
    {
        $this->loginAs('superadmin');
        $res = $this->call('save', [
            'name'     => 'Slugify',
            'language' => Tiger_Model_Code::LANG_PHP,
            'code'     => "if (!function_exists('w6_slug')) { function w6_slug(\$s) { return strtolower(\$s); } }",
            'active'   => '1',
            'priority' => '50',
        ]);
        $this->assertSame(1, (int) $res->result, 'valid PHP saves + rebuilds live');
        $id = $res->data['code_id'];

        $row = (new Tiger_Model_Code())->findById($id);
        $this->assertSame('Slugify', $row->name);
        $this->assertSame(Tiger_Model_Code::LANG_PHP, $row->language);
        $this->assertSame(1, (int) $row->active, 'stored active');
        $this->assertSame(50, (int) $row->priority);
        $this->assertNull($row->auto_insert, 'php has no injection location');
    }

    #[Test]
    public function save_a_client_css_snippet_sets_the_head_auto_insert_and_skips_the_php_lint(): void
    {
        $this->loginAs('superadmin');
        $res = $this->call('save', [
            'name'     => 'Brand CSS',
            'language' => Tiger_Model_Code::LANG_CSS,
            'code'     => '.brand{color:red}',
            'active'   => '1',
            'priority' => '10',
        ]);
        $this->assertSame(1, (int) $res->result, 'a css snippet needs no parse-check');
        $row = (new Tiger_Model_Code())->findById($res->data['code_id']);
        $this->assertSame(Tiger_Model_Code::LANG_CSS, $row->language);
        $this->assertSame(Tiger_Model_Code::AUTO_HEAD, $row->auto_insert, 'css always injects into the head');
    }

    // ----- restore happy path -------------------------------------------------------------------

    #[Test]
    public function restore_reverts_a_snippet_to_a_prior_version(): void
    {
        $this->loginAs('superadmin');
        // v1 then v2 through the service (each save snapshots a version).
        $create = $this->call('save', [
            'name' => 'Versioned', 'language' => Tiger_Model_Code::LANG_PHP,
            'code' => "if(!function_exists('w6_v1')){function w6_v1(){return 1;}}",
            'active' => '0', 'priority' => '100',
        ]);
        $id = $create->data['code_id'];
        $this->call('save', [
            'code_id' => $id, 'name' => 'Versioned v2', 'language' => Tiger_Model_Code::LANG_PHP,
            'code' => "if(!function_exists('w6_v1')){function w6_v1(){return 2;}}",
            'active' => '0', 'priority' => '100',
        ]);

        $res = $this->call('restore', ['code_id' => $id, 'version' => 1]);
        $this->assertSame(1, (int) $res->result, 'restore to v1 succeeds');

        $row = (new Tiger_Model_Code())->findById($id);
        $this->assertSame('Versioned', $row->name, 'the snippet reverted to its v1 name');
        $this->assertStringContainsString('return 1', (string) $row->code);
    }

    // ----- restore guard ------------------------------------------------------------------------

    #[Test]
    public function restore_with_a_bad_version_is_a_clean_error(): void
    {
        $this->loginAs('superadmin');
        $res = $this->call('restore', ['code_id' => 'whatever', 'version' => 0]);
        $this->assertSame(0, (int) $res->result, 'version < 1 is refused up front');
    }

    #[Test]
    public function restore_with_a_missing_id_is_a_clean_error(): void
    {
        $this->loginAs('superadmin');
        $res = $this->call('restore', ['code_id' => '', 'version' => 2]);
        $this->assertSame(0, (int) $res->result);
    }
}
