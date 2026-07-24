<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\Cms;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Cms_Renderer;
use Tiger_Model_Page;

/**
 * Tiger_Cms_Renderer — renders a CMS body by its `format` and runs the [shortcode] registry.
 *
 * These tests pin the format-dispatch CONTRACT and its security posture — which is the whole reason
 * the three formats exist as distinct trust tiers:
 *   - `html` / `builder`  -> emitted verbatim; embedded PHP is NEVER executed (it's inert text).
 *   - `markdown`          -> Parsedown, then shortcodes.
 *   - `phtml`             -> executed as a Zend_View template (TRUSTED authors only) with context vars.
 *
 * We test how *Tiger* dispatches + wires these (not Parsedown's internals): that phtml executes and
 * sees its context while html does not, that shortcodes substitute after the body render, and the
 * escaping posture of the shortcode substitution (handler output is inserted RAW — the handler owns
 * escaping). We DON'T touch render()'s layout path (it needs the DB `page` model); renderBody() is
 * the pure seam and carries the behavior.
 *
 * The shortcode registry is a process-global static with no public reset, so we clear it via
 * reflection around every test.
 */
#[CoversClass(Tiger_Cms_Renderer::class)]
final class RendererTest extends UnitTestCase
{
    private Tiger_Cms_Renderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearShortcodes();
        $this->renderer = new Tiger_Cms_Renderer();
    }

    protected function tearDown(): void
    {
        $this->clearShortcodes();   // never leak a registered handler into the next test
        parent::tearDown();
    }

    /** Reset the private static shortcode registry so tests don't bleed into each other. */
    private function clearShortcodes(): void
    {
        $prop = new ReflectionProperty(Tiger_Cms_Renderer::class, '_shortcodes');
        $prop->setValue(null, []);   // reflection reaches the private static without setAccessible (PHP 8.1+)
    }

    #[Test]
    public function html_is_emitted_verbatim(): void
    {
        $body = '<section class="hero"><b>Hi</b></section>';
        $this->assertSame($body, $this->renderer->renderBody($body, Tiger_Model_Page::FORMAT_HTML));
    }

    #[Test]
    public function html_does_not_execute_embedded_php(): void
    {
        // SECURITY INVARIANT: the html tier is untrusted content — a PHP short-echo string is data,
        // not code. It must come back untouched, never evaluated.
        $body = '<?= 6 * 7 ?>';
        $this->assertSame($body, $this->renderer->renderBody($body, Tiger_Model_Page::FORMAT_HTML));
    }

    #[Test]
    public function markdown_is_converted_to_html(): void
    {
        $out = $this->renderer->renderBody("# Title", Tiger_Model_Page::FORMAT_MARKDOWN);
        $this->assertStringContainsString('<h1>Title</h1>', $out);
    }

    #[Test]
    public function markdown_passes_raw_html_through_unescaped(): void
    {
        // DOCUMENTS THE ACTUAL POSTURE: Tiger dispatches to Parsedown WITHOUT safe-mode /
        // markup-escaping, so raw HTML embedded in a markdown body survives verbatim. See the
        // "markdown safe-mode" note in the report — the class docblock advertises markdown as
        // "safe", but the shipped dispatch does not enable Parsedown's safe mode. This test asserts
        // the real behavior so a future hardening (turning safe mode on) is a deliberate, visible change.
        $out = $this->renderer->renderBody('<script>alert(1)</script>', Tiger_Model_Page::FORMAT_MARKDOWN);
        $this->assertStringContainsString('<script>alert(1)</script>', $out);
    }

    #[Test]
    public function phtml_is_executed_as_a_template_with_its_context(): void
    {
        // The phtml tier IS code (trusted): the body is evaluated as a Zend_View template and can
        // read its assigned context vars.
        $out = $this->renderer->renderBody(
            '<?= 6 * 7 ?>-<?= $this->name ?>',
            Tiger_Model_Page::FORMAT_PHTML,
            ['name' => 'Thundarr']
        );
        $this->assertSame('42-Thundarr', $out);
    }

    #[Test]
    public function builder_format_is_treated_as_safe_verbatim_html_plus_shortcodes(): void
    {
        // builder output is self-contained <style>+markup (script stripped at save) — rendered like
        // html (verbatim) but still gets the shortcode pass.
        Tiger_Cms_Renderer::registerShortcode('year', static fn () => '2026');
        $out = $this->renderer->renderBody('<style>.x{}</style>[year]', Tiger_Model_Page::FORMAT_BUILDER);
        $this->assertSame('<style>.x{}</style>2026', $out);
    }

    #[Test]
    public function an_unknown_format_falls_back_to_verbatim_html(): void
    {
        $body = '<p>plain</p>';
        $this->assertSame($body, $this->renderer->renderBody($body, 'totally-made-up'));
    }

    #[Test]
    public function a_registered_shortcode_is_substituted(): void
    {
        Tiger_Cms_Renderer::registerShortcode('greet', static fn () => 'HELLO');
        $this->assertSame('say HELLO', $this->renderer->renderBody('say [greet]', Tiger_Model_Page::FORMAT_HTML));
    }

    #[Test]
    public function shortcode_names_are_case_insensitive(): void
    {
        Tiger_Cms_Renderer::registerShortcode('Greet', static fn () => 'HI');
        // registered mixed-case, invoked upper- and lower-case — both resolve.
        $this->assertSame('HI/HI', $this->renderer->renderBody('[GREET]/[greet]', Tiger_Model_Page::FORMAT_HTML));
    }

    #[Test]
    public function an_unregistered_shortcode_is_left_as_literal_text(): void
    {
        // No handler => the literal token is preserved (not stripped, not errored).
        $this->assertSame('[nope]', $this->renderer->renderBody('[nope]', Tiger_Model_Page::FORMAT_HTML));
    }

    #[Test]
    public function shortcode_attributes_are_parsed_into_the_handler(): void
    {
        Tiger_Cms_Renderer::registerShortcode('btn', static function (array $attrs) {
            return $attrs['label'] . '|' . $attrs['size'];
        });
        $out = $this->renderer->renderBody('[btn label="Buy" size="lg"]', Tiger_Model_Page::FORMAT_HTML);
        $this->assertSame('Buy|lg', $out);
    }

    #[Test]
    public function a_wrapping_shortcode_receives_its_inner_content(): void
    {
        Tiger_Cms_Renderer::registerShortcode('upper', static function (array $attrs, ?string $inner) {
            return strtoupper((string) $inner);
        });
        $out = $this->renderer->renderBody('[upper]quiet[/upper]', Tiger_Model_Page::FORMAT_HTML);
        $this->assertSame('QUIET', $out);
    }

    #[Test]
    public function a_self_closing_shortcode_receives_a_null_inner(): void
    {
        Tiger_Cms_Renderer::registerShortcode('probe', static function (array $attrs, ?string $inner) {
            return $inner === null ? 'NULL-INNER' : 'HAS-INNER';
        });
        $this->assertSame('NULL-INNER', $this->renderer->renderBody('[probe]', Tiger_Model_Page::FORMAT_HTML));
    }

    #[Test]
    public function shortcode_output_is_inserted_raw_so_the_handler_owns_escaping(): void
    {
        // ESCAPING POSTURE: the substitution does NOT escape a handler's return value — whatever the
        // handler emits lands in the document as-is. That's by design (a shortcode legitimately emits
        // markup), and it's the contract callers must respect: escape untrusted data INSIDE the
        // handler. We pin it so the posture can't silently change.
        Tiger_Cms_Renderer::registerShortcode('raw', static fn () => '<b>bold</b>');
        $this->assertSame('<b>bold</b>', $this->renderer->renderBody('[raw]', Tiger_Model_Page::FORMAT_HTML));
    }

    #[Test]
    public function the_shortcode_context_reaches_the_handler(): void
    {
        // renderBody passes its $context array through to the handler as the third arg — a shortcode
        // can read page/view state.
        Tiger_Cms_Renderer::registerShortcode('who', static function (array $attrs, ?string $inner, array $context) {
            return $context['user'] ?? 'anon';
        });
        $out = $this->renderer->renderBody('[who]', Tiger_Model_Page::FORMAT_HTML, ['user' => 'Ariel']);
        $this->assertSame('Ariel', $out);
    }

    #[Test]
    public function a_body_with_no_bracket_skips_the_shortcode_pass_untouched(): void
    {
        // Fast-path guard: no '[' => the string is returned as-is even with handlers registered.
        Tiger_Cms_Renderer::registerShortcode('greet', static fn () => 'HELLO');
        $this->assertSame('nothing here', $this->renderer->renderBody('nothing here', Tiger_Model_Page::FORMAT_HTML));
    }
}
