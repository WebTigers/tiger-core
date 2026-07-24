<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\View;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_View_Helper_FormRecaptcha;

/**
 * Tiger_View_Helper_FormRecaptcha — renders the Google reCAPTCHA widget behind the form element (and
 * callable directly as `$this->formRecaptcha()`). v2 emits the checkbox div + the shared api.js once;
 * v3 emits a hidden field + the score script that refreshes a token on submit. When reCAPTCHA is off
 * (or has no site key) it renders NOTHING, so a form is unencumbered in dev.
 *
 * All state comes from `tiger.recaptcha.*` config (Tiger_Recaptcha reads the registry), so these unit
 * tests drive it directly. The "emit the api.js once" latch is a process-static — reset before each
 * test so ordering can't hide the second-render suppression.
 */
#[CoversClass(Tiger_View_Helper_FormRecaptcha::class)]
final class FormRecaptchaTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetScriptLatch();
    }

    protected function tearDown(): void
    {
        $this->resetScriptLatch();
        parent::tearDown();
    }

    private function resetScriptLatch(): void
    {
        (new ReflectionProperty(Tiger_View_Helper_FormRecaptcha::class, '_scriptEmitted'))->setValue(null, false);
    }

    private function helper(): Tiger_View_Helper_FormRecaptcha
    {
        return new Tiger_View_Helper_FormRecaptcha();
    }

    private function enable(string $version, string $siteKey = 'site-abc'): void
    {
        $this->setConfig(['tiger' => ['recaptcha' => [
            'enabled'  => '1',
            'version'  => $version,
            'site_key' => $siteKey,
        ]]]);
    }

    #[Test]
    public function it_renders_nothing_when_recaptcha_is_disabled(): void
    {
        $this->setConfig(['tiger' => ['recaptcha' => ['enabled' => '0', 'site_key' => 'site-abc']]]);
        $this->assertSame('', $this->helper()->formRecaptcha());
    }

    #[Test]
    public function it_renders_nothing_when_enabled_without_a_site_key(): void
    {
        $this->setConfig(['tiger' => ['recaptcha' => ['enabled' => '1', 'site_key' => '']]]);
        $this->assertSame('', $this->helper()->formRecaptcha());
    }

    #[Test]
    public function v2_renders_the_checkbox_widget_with_the_site_key(): void
    {
        $this->enable('v2');
        $html = $this->helper()->formRecaptcha();

        $this->assertStringContainsString('class="g-recaptcha"', $html);
        $this->assertStringContainsString('data-sitekey="site-abc"', $html);
        $this->assertStringContainsString('api.js', $html, 'the loader script is present on first render');
    }

    #[Test]
    public function v2_honors_theme_and_size_attributes(): void
    {
        $this->enable('v2');
        $html = $this->helper()->formRecaptcha('g-recaptcha-response', null, ['theme' => 'dark', 'size' => 'compact']);

        $this->assertStringContainsString('data-theme="dark"', $html);
        $this->assertStringContainsString('data-size="compact"', $html);
    }

    #[Test]
    public function v2_emits_the_shared_api_script_only_once_per_render_pass(): void
    {
        $this->enable('v2');
        $first  = $this->helper()->formRecaptcha();
        $second = $this->helper()->formRecaptcha();

        $this->assertStringContainsString('api.js', $first);
        $this->assertStringNotContainsString('api.js', $second, 'the second widget reuses the already-emitted loader');
    }

    #[Test]
    public function v3_renders_the_hidden_field_and_the_score_script(): void
    {
        $this->enable('v3');
        $html = $this->helper()->formRecaptcha('g-recaptcha-response', null, ['action' => 'login']);

        $this->assertStringContainsString('type="hidden"', $html);
        $this->assertStringContainsString('class="g-recaptcha-response"', $html);
        $this->assertStringContainsString('render=site-abc', $html, 'the v3 script loads with the site key');
        $this->assertStringContainsString('"login"', $html, 'the sanitized action rides the execute() call');
    }

    #[Test]
    public function v3_sanitizes_a_hostile_action_name(): void
    {
        $this->enable('v3');
        $html = $this->helper()->formRecaptcha('g-recaptcha-response', null, ['action' => 'log"in<scr>']);

        // Non-[A-Za-z0-9_/] chars are stripped before the action reaches the script: log"in<scr> -> loginscr.
        $this->assertStringNotContainsString('log"in', $html);
        $this->assertStringContainsString('"loginscr"', $html);
    }
}
