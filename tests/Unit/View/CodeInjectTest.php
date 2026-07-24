<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\View;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_View_Helper_CodeInject;

/**
 * Tiger_View_Helper_CodeInject — emit Tiger Code's client tier (`<?= $this->codeInject('head') ?>`).
 *
 * It reads the compiled injection manifest (Tiger_Code_Runtime, cached — no per-request DB query) and
 * renders each item: css/js → a versioned <link>/<script>, html → verbatim markup, phtml → server-
 * rendered (guarded). The two early guards (Code disabled, or no version token) short-circuit to ''.
 *
 * Unit-testable by driving the config (kill-switch + version token both ride `tiger.code.*`) and, for
 * the render loop, priming a manifest file at the exact cache path the runtime `include`s — so the
 * helper never touches the DB. The primed manifest is written under APPLICATION_ROOT and removed in
 * tearDown so nothing is left in the worktree.
 */
#[CoversClass(Tiger_View_Helper_CodeInject::class)]
final class CodeInjectTest extends UnitTestCase
{
    private const VERSION = 987654;

    private string $cacheDir;
    private string $manifestFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheDir     = APPLICATION_ROOT . '/storage/cache/code';
        $this->manifestFile = $this->cacheDir . '/inject.global.' . self::VERSION . '.php';
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        if (is_file($this->manifestFile)) { @unlink($this->manifestFile); }
    }

    private function helper(): Tiger_View_Helper_CodeInject
    {
        return new Tiger_View_Helper_CodeInject();
    }

    #[Test]
    public function it_emits_nothing_when_code_execution_is_disabled(): void
    {
        // `tiger.code.enabled = 0` is the config kill-switch (Tiger_Code_Runtime::enabled()).
        $this->setConfig(['tiger' => ['code' => ['enabled' => '0', 'version' => (string) self::VERSION]]]);
        $this->assertSame('', $this->helper()->codeInject('head'));
    }

    #[Test]
    public function it_emits_nothing_when_there_is_no_version_token(): void
    {
        // Enabled but version 0 (nothing compiled) => nothing to inject.
        $this->setConfig(['tiger' => ['code' => ['enabled' => '1', 'version' => '0']]]);
        $this->assertSame('', $this->helper()->codeInject('head'));
    }

    #[Test]
    public function it_renders_css_js_and_html_items_from_the_manifest(): void
    {
        $this->primeManifest([
            'head' => [
                ['type' => 'css_asset', 'url' => '/_code/global.1.css'],
                ['type' => 'html',      'html' => '<meta name="x" content="y">'],
            ],
            'footer' => [
                ['type' => 'js_asset', 'url' => '/_code/global.1.js'],
            ],
        ]);
        $this->setConfig(['tiger' => ['code' => ['enabled' => '1', 'version' => (string) self::VERSION]]]);

        $head = $this->helper()->codeInject('head');
        $this->assertStringContainsString('<link rel="stylesheet" href="/_code/global.1.css">', $head);
        $this->assertStringContainsString('<meta name="x" content="y">', $head);
        $this->assertStringNotContainsString('<script', $head, 'footer items do not leak into head');

        $footer = $this->helper()->codeInject('footer');
        $this->assertStringContainsString('<script src="/_code/global.1.js"></script>', $footer);
    }

    #[Test]
    public function it_emits_nothing_for_a_position_with_no_items(): void
    {
        $this->primeManifest(['head' => [['type' => 'html', 'html' => 'x']], 'footer' => []]);
        $this->setConfig(['tiger' => ['code' => ['enabled' => '1', 'version' => (string) self::VERSION]]]);

        $this->assertSame('', $this->helper()->codeInject('footer'));
    }

    #[Test]
    public function a_phtml_item_is_server_rendered_through_the_cms_renderer_guarded(): void
    {
        // The phtml branch renders server-side via Tiger_Cms_Renderer, guarded so a bad snippet can
        // never break the page. Whether the renderer succeeds or the guard swallows it, codeInject
        // must return a string (never throw) — exercising the phtml case + its try/catch.
        $this->primeManifest(['head' => [['type' => 'phtml', 'code' => '<?= 2 + 2 ?>']]]);
        $this->setConfig(['tiger' => ['code' => ['enabled' => '1', 'version' => (string) self::VERSION]]]);

        $this->assertIsString($this->helper()->codeInject('head'));
    }

    #[Test]
    public function an_unknown_item_type_is_skipped(): void
    {
        $this->primeManifest(['head' => [['type' => 'mystery', 'x' => 1], ['type' => 'html', 'html' => 'kept']]]);
        $this->setConfig(['tiger' => ['code' => ['enabled' => '1', 'version' => (string) self::VERSION]]]);

        $out = $this->helper()->codeInject('head');
        $this->assertStringContainsString('kept', $out);
        $this->assertStringNotContainsString('mystery', $out);
    }

    #[Test]
    public function a_manifest_url_is_html_escaped(): void
    {
        $this->primeManifest(['head' => [['type' => 'css_asset', 'url' => '/x.css?a=1&b=2']]]);
        $this->setConfig(['tiger' => ['code' => ['enabled' => '1', 'version' => (string) self::VERSION]]]);

        $this->assertStringContainsString('/x.css?a=1&amp;b=2', $this->helper()->codeInject('head'));
    }

    /** Write a manifest exactly where Tiger_Code_Runtime::injectManifest() will `include` it. */
    private function primeManifest(array $manifest): void
    {
        if (!is_dir($this->cacheDir)) { @mkdir($this->cacheDir, 0777, true); }
        file_put_contents($this->manifestFile, '<?php return ' . var_export($manifest, true) . ';');
    }
}
