<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Update;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_Module;
use Tiger_Update_Checker;

/**
 * Tiger_Update_Checker::modules() — the per-module update diff, which the unit CheckerTest can't reach
 * (it needs the `module` table to enumerate installer-managed modules). This seeds real module rows and
 * PRE-SEEDS the per-module remote cache (the "latest ref" GitHub lookup) so the diff runs offline:
 *
 *   - an installer-managed row (repository + version) whose cached latest is NEWER → flagged as an update,
 *     with a licensed manifest on disk driving the license annotation (Tiger_License_Checker::status);
 *   - a discovered/local row (no repository) → skipped (nothing authoritative to diff against).
 */
#[CoversClass(Tiger_Update_Checker::class)]
final class CheckerTest extends IntegrationTestCase
{
    /** @var string[] planted module dirs to remove. */
    private array $planted = [];
    /** @var string[] cache files to remove. */
    private array $wrote = [];

    protected function tearDown(): void
    {
        foreach ($this->planted as $d) { $this->rrmdir($d); }
        foreach ($this->wrote as $f) { @unlink($f); }
        @rmdir(APPLICATION_PATH . '/modules');
        parent::tearDown();
    }

    private function primeCache(string $key, $value): void
    {
        $dir = dirname(APPLICATION_PATH) . '/var/cache/updates';
        @mkdir($dir, 0775, true);
        $file = $dir . '/' . preg_replace('/[^a-z0-9._-]/i', '_', $key) . '.json';
        file_put_contents($file, json_encode(['v' => $value]));
        $this->wrote[] = $file;
    }

    private function plantModule(string $slug, array $manifest): void
    {
        $dir = APPLICATION_PATH . '/modules/' . $slug;
        @mkdir($dir, 0775, true);
        file_put_contents($dir . '/module.json', json_encode($manifest + ['slug' => $slug]));
        $this->planted[] = $dir;
    }

    #[Test]
    public function modulesDiffsInstallerManagedRowsAndSkipsLocalOnes(): void
    {
        $mod = new Tiger_Model_Module();

        // An installer-managed, licensed module at 1.0.0, with a newer cached "latest".
        $mod->install('w4upd', [
            'name' => 'W4 Upd', 'version' => '1.0.0',
            'repository' => 'https://github.com/WebTigers/W4Upd', 'ref' => 'v1.0.0', 'source' => Tiger_Model_Module::SOURCE_URL,
        ]);
        $this->plantModule('w4upd', [
            'name' => 'W4 Upd', 'version' => '1.0.0',
            'pricing' => ['model' => 'licensed', 'authority' => 'https://store.example/authority', 'vendor' => 'acme/TigerVendor'],
        ]);
        $this->primeCache('mod-w4upd', 'v2.0.0');   // GitHub says the latest tag is v2.0.0

        // A discovered/local module — no repository → nothing to diff, must be skipped.
        $mod->setActive('w4local', true, ['source' => Tiger_Model_Module::SOURCE_DISCOVERED, 'name' => 'W4 Local', 'version' => '9.9.9']);

        $rows = Tiger_Update_Checker::modules();
        $byslug = [];
        foreach ($rows as $r) { $byslug[$r['slug']] = $r; }

        $this->assertArrayHasKey('w4upd', $byslug, 'the installer-managed module is diffed');
        $this->assertTrue($byslug['w4upd']['update'], '1.0.0 < 2.0.0 → an update is available');
        $this->assertSame('2.0.0', $byslug['w4upd']['latest']);
        $this->assertArrayHasKey('license', $byslug['w4upd'], 'a licensed module carries its license verdict');

        $this->assertArrayNotHasKey('w4local', $byslug, 'a module with no repository is skipped');
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
