<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\System;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use System_Service_Modules;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_Module;

// System_Service_Modules resolves via the harness module autoloader (tests/bootstrap.php).

/**
 * System_Service_Modules — the DEEPER branches Wave 4's ModulesServiceTest left uncovered: the actual
 * activate/deactivate toggle for a real on-disk module (the `module.active` flag write + the
 * dependency/asset bookkeeping), the registry `search()` read, and the `upload()` error-code guards.
 * Everything here runs WITHOUT the network: `blog` is a bundled module that ships neither a `migrations/`
 * nor an `assets/` dir, so its toggle is a pure DB write (rolled back per-test), and the registry index
 * is seeded into its file cache so `search()` never reaches GitHub.
 *
 * The install-from-URL / install-from-upload success paths and the theme-toggle path are NOT reachable
 * here (they need a live repo, a real multipart upload, or a `type:theme` module on disk) — see
 * WAVE6-FINDINGS-system.md.
 */
#[CoversClass(System_Service_Modules::class)]
final class ModulesServiceExtraTest extends IntegrationTestCase
{
    /** A bundled module with no migrations/ and no assets/ → a side-effect-free toggle target. */
    private const TARGET = 'blog';

    /** Registry index cache path + its original contents (null = didn't exist) for restore. */
    private ?string $registryFile = null;
    private ?string $registryOrig = null;

    protected function tearDown(): void
    {
        if ($this->registryFile !== null) {
            if ($this->registryOrig !== null) { @file_put_contents($this->registryFile, $this->registryOrig); }
            else { @unlink($this->registryFile); }
        }
        parent::tearDown();
    }

    private function dispatch(array $msg): object
    {
        return (new System_Service_Modules($msg))->getResponse();
    }

    private function messages(object $res): string
    {
        return json_encode($res->messages ?? []);
    }

    // ---- activate / deactivate a real module -----------------------------------------------------

    #[Test]
    public function a_superadmin_can_activate_a_real_module_flipping_its_flag(): void
    {
        $this->loginAs('superadmin');
        $res = $this->dispatch(['action' => 'activate', 'slug' => self::TARGET]);

        $this->assertSame(1, (int) $res->result, $this->messages($res));
        $this->assertStringContainsString('activated', $this->messages($res));
        $this->assertSame('/system/modules', $res->redirect, 'the manager redirect is returned');
        $this->assertSame(self::TARGET, $res->data['slug']);
        $this->assertTrue((bool) $res->data['active']);
        // The activation branch reports missing required modules (empty for a dependency-free module).
        $this->assertArrayHasKey('requires_missing', $res->data);

        $row = (new Tiger_Model_Module())->bySlug(self::TARGET);
        $this->assertNotNull($row, 'the module row was written');
        $this->assertSame(1, (int) $row->active, 'the flag is active in the DB (rolled back after the test)');
    }

    #[Test]
    public function a_superadmin_can_deactivate_a_real_module_and_is_told_its_dependents(): void
    {
        $this->loginAs('superadmin');
        $res = $this->dispatch(['action' => 'deactivate', 'slug' => self::TARGET]);

        $this->assertSame(1, (int) $res->result, $this->messages($res));
        $this->assertStringContainsString('deactivated', $this->messages($res));
        $this->assertFalse((bool) $res->data['active']);
        // The deactivate branch surfaces which OTHER modules depend on this one (the confirm prompt).
        $this->assertArrayHasKey('dependents', $res->data);
        $this->assertIsArray($res->data['dependents']);

        $row = (new Tiger_Model_Module())->bySlug(self::TARGET);
        $this->assertSame(0, (int) $row->active, 'the flag is deactivated in the DB');
    }

    // ---- registry search (seeded cache, no network) ----------------------------------------------

    #[Test]
    public function search_returns_the_registry_listing_from_the_seeded_cache(): void
    {
        $this->seedRegistryIndex([
            'modules' => [
                ['slug' => 'demo-widget', 'name' => 'Demo Widget', 'description' => 'A demo listing',
                 'repository' => 'https://github.com/WebTigers/DemoWidget', 'type' => 'plugin'],
                ['slug' => 'other', 'name' => 'Other Thing', 'description' => 'unrelated',
                 'repository' => 'https://github.com/WebTigers/Other', 'type' => 'plugin'],
            ],
        ]);

        $this->loginAs('superadmin');
        $res = $this->dispatch(['action' => 'search', 'q' => 'demo', 'sort' => 'title']);

        $this->assertSame(1, (int) $res->result, $this->messages($res));
        $this->assertTrue((bool) $res->data['available'], 'the seeded index reads as available');
        $this->assertSame('title', $res->data['sort']);
        $this->assertCount(1, $res->data['results'], 'the query matched exactly the demo listing');
        $this->assertSame('demo-widget', $res->data['results'][0]['slug']);
    }

    #[Test]
    public function a_plain_admin_is_denied_the_registry_search(): void
    {
        $this->loginAs('admin');
        $res = $this->dispatch(['action' => 'search', 'q' => 'anything']);

        $this->assertSame(0, (int) $res->result, 'searching the registry is superadmin+');
        $this->assertStringContainsString('not_allowed', $this->messages($res));
    }

    // ---- upload() error-code guards (no real multipart upload) -----------------------------------

    #[Test]
    public function upload_reports_a_file_that_exceeds_the_server_size_limit(): void
    {
        $this->loginAs('superadmin');
        $_FILES['archive'] = ['name' => 'big.zip', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0];
        try {
            $res = $this->dispatch(['action' => 'upload']);
        } finally {
            unset($_FILES['archive']);
        }

        $this->assertSame(0, (int) $res->result);
        $this->assertStringContainsString('larger than the server allows', $this->messages($res));
    }

    #[Test]
    public function upload_reports_a_generic_transport_error(): void
    {
        $this->loginAs('superadmin');
        $_FILES['archive'] = ['name' => 'x.zip', 'tmp_name' => '', 'error' => UPLOAD_ERR_PARTIAL, 'size' => 0];
        try {
            $res = $this->dispatch(['action' => 'upload']);
        } finally {
            unset($_FILES['archive']);
        }

        $this->assertSame(0, (int) $res->result);
        $this->assertStringContainsString('Upload failed', $this->messages($res));
    }

    #[Test]
    public function upload_rejects_a_tmp_path_that_was_not_an_http_upload(): void
    {
        // error=OK but tmp_name isn't a real uploaded file → the is_uploaded_file guard fires (the
        // spoofed-path defense) BEFORE any extraction is attempted.
        $this->loginAs('superadmin');
        $_FILES['archive'] = ['name' => 'x.zip', 'tmp_name' => '/etc/hosts', 'error' => UPLOAD_ERR_OK, 'size' => 1];
        try {
            $res = $this->dispatch(['action' => 'upload']);
        } finally {
            unset($_FILES['archive']);
        }

        $this->assertSame(0, (int) $res->result);
        $this->assertStringContainsString('Invalid upload', $this->messages($res));
    }

    // ---- seeding ---------------------------------------------------------------------------------

    private function seedRegistryIndex(array $index): void
    {
        $file = rtrim(APPLICATION_ROOT, '/') . '/storage/cache/registry-index.json';
        $this->registryFile = $file;
        $this->registryOrig = is_file($file) ? (string) file_get_contents($file) : null;
        @mkdir(dirname($file), 0775, true);
        @file_put_contents($file, json_encode($index));
    }
}
