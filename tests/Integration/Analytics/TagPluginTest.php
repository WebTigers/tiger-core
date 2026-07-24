<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Analytics;

use Analytics_Plugin_Tag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Tiger\Tests\Support\IntegrationTestCase;
use Zend_Config;
use Zend_Controller_Request_Simple;
use Zend_Registry;
use Zend_View;

/**
 * Analytics_Plugin_Tag — emits the GA4 gtag.js snippet into the `tigerTracking` head placeholder on
 * public pages, only when GA is enabled with a valid `G-XXXX` id AND consent allows AND the visitor
 * isn't excluded signed-in staff. Wave-4 coverage: `measurementId()`/`isConfigured()` gating (disabled,
 * invalid id, valid id), and `preDispatch()` emission — the happy path appends the snippet, and the
 * staff-exclusion path stays silent for a signed-in admin.
 *
 * The plugin has a process-wide emit-once latch (`$_done`); each test resets it via reflection.
 */
#[CoversClass(Analytics_Plugin_Tag::class)]
final class TagPluginTest extends IntegrationTestCase
{
    private Zend_View $view;

    protected function setUp(): void
    {
        parent::setUp();
        // A fresh view the plugin will reach for the placeholder helper (shared via the registry).
        $this->view = new Zend_View();
        Zend_Registry::set('Zend_View', $this->view);
        // Placeholder containers are a process-wide singleton — clear the one this plugin writes so
        // an emission from a prior test can't bleed into this one.
        $this->view->placeholder('tigerTracking')->exchangeArray([]);
        $this->resetLatch();
    }

    protected function tearDown(): void
    {
        $this->resetLatch();
        parent::tearDown();
    }

    /** Reset the plugin's emit-once static so each test starts clean. */
    private function resetLatch(): void
    {
        $p = new ReflectionProperty(Analytics_Plugin_Tag::class, '_done');
        $p->setValue(null, false);
    }

    private function config(array $analytics): void
    {
        Zend_Registry::set('Zend_Config', new Zend_Config(['tiger' => ['analytics' => $analytics]], true));
    }

    private function tracking(): string
    {
        return (string) $this->view->placeholder('tigerTracking');
    }

    // ----- measurementId / isConfigured ---------------------------------------------------------

    #[Test]
    public function measurement_id_is_empty_when_disabled(): void
    {
        $this->config(['enabled' => '0', 'ga4' => ['measurement_id' => 'G-VALID123']]);
        $this->assertSame('', Analytics_Plugin_Tag::measurementId(), 'disabled → no id, even with one set');
        $this->assertFalse(Analytics_Plugin_Tag::isConfigured());
    }

    #[Test]
    public function measurement_id_is_empty_for_an_invalid_id(): void
    {
        $this->config(['enabled' => '1', 'ga4' => ['measurement_id' => 'not-a-ga4-id']]);
        $this->assertSame('', Analytics_Plugin_Tag::measurementId(), 'a non G-XXXX id is rejected');
        $this->assertFalse(Analytics_Plugin_Tag::isConfigured());
    }

    #[Test]
    public function measurement_id_returns_a_valid_enabled_id(): void
    {
        $this->config(['enabled' => '1', 'ga4' => ['measurement_id' => 'G-VALID123']]);
        $this->assertSame('G-VALID123', Analytics_Plugin_Tag::measurementId());
        $this->assertTrue(Analytics_Plugin_Tag::isConfigured());
    }

    // ----- preDispatch emission -----------------------------------------------------------------

    #[Test]
    public function predispatch_appends_the_snippet_when_configured_for_a_guest(): void
    {
        // Guest (no identity) → not staff; consent allows by default; enabled + valid id → emit.
        $this->config(['enabled' => '1', 'ga4' => ['measurement_id' => 'G-EMIT4567']]);

        (new Analytics_Plugin_Tag())->preDispatch(new Zend_Controller_Request_Simple());

        $out = $this->tracking();
        $this->assertStringContainsString('googletagmanager.com/gtag/js?id=G-EMIT4567', $out, 'gtag script emitted');
        $this->assertStringContainsString("gtag('config','G-EMIT4567')", $out, 'config call emitted');
    }

    #[Test]
    public function predispatch_emits_nothing_when_unconfigured(): void
    {
        $this->config(['enabled' => '0', 'ga4' => ['measurement_id' => 'G-EMIT4567']]);
        (new Analytics_Plugin_Tag())->preDispatch(new Zend_Controller_Request_Simple());
        $this->assertSame('', $this->tracking(), 'disabled → nothing in the placeholder');
    }

    #[Test]
    public function predispatch_skips_signed_in_staff_when_exclusion_is_on(): void
    {
        // exclude_signed_in on (default) + a signed-in admin (staff) → the tag is withheld.
        $this->config(['enabled' => '1', 'exclude_signed_in' => '1', 'ga4' => ['measurement_id' => 'G-STAFF999']]);
        $this->loginAs('admin');

        (new Analytics_Plugin_Tag())->preDispatch(new Zend_Controller_Request_Simple());
        $this->assertSame('', $this->tracking(), 'staff traffic is not tagged');
    }

    #[Test]
    public function predispatch_tags_signed_in_staff_when_exclusion_is_off(): void
    {
        // With exclusion turned off, even a signed-in admin gets tagged.
        $this->config(['enabled' => '1', 'exclude_signed_in' => '0', 'ga4' => ['measurement_id' => 'G-ALLSTAFF']]);
        $this->loginAs('admin');

        (new Analytics_Plugin_Tag())->preDispatch(new Zend_Controller_Request_Simple());
        $this->assertStringContainsString('G-ALLSTAFF', $this->tracking(), 'exclusion off → staff tagged');
    }

    #[Test]
    public function the_emit_once_latch_prevents_a_second_append(): void
    {
        $this->config(['enabled' => '1', 'ga4' => ['measurement_id' => 'G-ONCE1234']]);
        $plugin = new Analytics_Plugin_Tag();
        $plugin->preDispatch(new Zend_Controller_Request_Simple());
        $plugin->preDispatch(new Zend_Controller_Request_Simple());   // second dispatch/forward
        $this->assertSame(1, substr_count($this->tracking(), 'gtag/js?id='), 'the snippet is appended exactly once');
    }
}
