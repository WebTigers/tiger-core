<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\Generator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Generator_Module;
use RuntimeException;

/**
 * Tiger_Generator_Module — the `make:module` scaffolder. Pure filesystem: it writes a self-contained
 * module tree into a target dir with `{{name}}`/`{{Name}}` placeholders substituted. Tests drive it
 * against a throwaway temp dir and assert the created file set, the placeholder substitution across
 * the templates, and both guard clauses (bad name, already-exists).
 */
#[CoversClass(Tiger_Generator_Module::class)]
final class ModuleTest extends UnitTestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/tiger_gen_' . getmypid() . '_' . bin2hex(random_bytes(4));
        @mkdir($this->tmp, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmp);
        parent::tearDown();
    }

    #[Test]
    public function it_generates_the_full_module_tree(): void
    {
        $created = Tiger_Generator_Module::generate('shop', $this->tmp);

        $dir = $this->tmp . '/shop';
        $this->assertDirectoryExists($dir);

        // Every declared template landed on disk.
        foreach ([
            'Bootstrap.php',
            'controllers/IndexController.php',
            'services/Example.php',
            'views/scripts/index/index.phtml',
            'layouts/.gitkeep',
            'assets/.gitkeep',
            'configs/module.ini',
            'configs/acl.ini',
            'configs/routes.ini',
            'configs/dependency.ini',
            'models/.gitkeep',
            'migrations/.gitkeep',
        ] as $rel) {
            $this->assertFileExists($dir . '/' . $rel, "$rel should be created");
            $this->assertContains($dir . '/' . $rel, $created, "$rel should be in the returned list");
        }
        $this->assertCount(12, $created);
    }

    #[Test]
    public function placeholders_are_substituted_lowercase_and_ucfirst(): void
    {
        Tiger_Generator_Module::generate('shop', $this->tmp);
        $dir = $this->tmp . '/shop';

        $bootstrap = file_get_contents($dir . '/Bootstrap.php');
        $this->assertStringContainsString('class Shop_Bootstrap extends', $bootstrap, '{{Name}} → Shop');
        $this->assertStringNotContainsString('{{Name}}', $bootstrap);
        $this->assertStringNotContainsString('{{name}}', $bootstrap);

        $service = file_get_contents($dir . '/services/Example.php');
        $this->assertStringContainsString('class Shop_Service_Example extends Tiger_Service_Service', $service);
        $this->assertStringContainsString("'module' => 'shop'", $service, '{{name}} → shop in emitted code');

        $controller = file_get_contents($dir . '/controllers/IndexController.php');
        $this->assertStringContainsString('class Shop_IndexController extends Tiger_Controller_Action', $controller);

        $acl = file_get_contents($dir . '/configs/acl.ini');
        $this->assertStringContainsString('"Shop_IndexController"', $acl);
        $this->assertStringContainsString('acl.resources.shop_index.resource', $acl);
    }

    #[Test]
    public function a_name_is_trimmed_and_lowercased_before_validation(): void
    {
        // Leading/trailing whitespace and mixed case are normalized, not rejected.
        $created = Tiger_Generator_Module::generate('  Blog  ', $this->tmp);
        $this->assertDirectoryExists($this->tmp . '/blog');
        $this->assertNotEmpty($created);
    }

    #[Test]
    public function an_invalid_name_is_rejected(): void
    {
        foreach (['1shop', 'my-shop', 'my_shop', 'Shop!', ''] as $bad) {
            try {
                Tiger_Generator_Module::generate($bad, $this->tmp);
                $this->fail("name '$bad' should have been rejected");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('lowercase word', $e->getMessage());
            }
        }
    }

    #[Test]
    public function an_existing_module_is_refused(): void
    {
        Tiger_Generator_Module::generate('shop', $this->tmp);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Module 'shop' already exists");
        Tiger_Generator_Module::generate('shop', $this->tmp);
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
