<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Admin_Header;
use Tiger_Admin_Nav;
use Tiger_Admin_Settings;
use Tiger_Admin_UserMenu;

/**
 * The admin-surface registries — Tiger_Admin_Nav (sidebar top-level), Tiger_Admin_Settings (the
 * Settings submenu), Tiger_Admin_Header (top-bar actions), and Tiger_Admin_UserMenu (avatar menu).
 *
 * All four are the module hook that lets a module contribute an admin UI item with NO core edit — a
 * `register()` call (or an auto-discovered `*.ini`), deduped by key, sorted by (order, label), and
 * ACL-/activation-gated downstream in the view. They're process-static, so each test clears the
 * registry; the three ini-discovering registries also get their `_loaded` latch flipped on (via
 * reflection) so `items()` returns ONLY what the test registered, not whatever a real module ships.
 *
 * A separate test exercises the real discover() path (with the latch off) to prove config auto-
 * discovery runs and a bare install never fatals when the DB isn't up (inactiveSlugs() throws → all
 * items shown, not none).
 */
#[CoversClass(Tiger_Admin_Nav::class)]
#[CoversClass(Tiger_Admin_Settings::class)]
#[CoversClass(Tiger_Admin_Header::class)]
#[CoversClass(Tiger_Admin_UserMenu::class)]
final class RegistryTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAll();
    }

    protected function tearDown(): void
    {
        $this->clearAll();
        parent::tearDown();
    }

    private function clearAll(): void
    {
        Tiger_Admin_Nav::clear();
        Tiger_Admin_Settings::clear();
        Tiger_Admin_Header::clear();
        Tiger_Admin_UserMenu::clear();
    }

    /** Flip the discovery latch ON so items() won't glob real module *.ini files — pure isolation. */
    private function suppressDiscovery(string $class): void
    {
        (new ReflectionProperty($class, '_loaded'))->setValue(null, true);
    }

    // ----- Tiger_Admin_Settings (no discovery — the simplest of the four) ----------------------

    #[Test]
    public function settings_register_applies_defaults_and_pages_returns_the_shaped_item(): void
    {
        Tiger_Admin_Settings::register(['key' => 'billing', 'label' => 'Billing', 'href' => '/billing/settings']);
        $pages = Tiger_Admin_Settings::pages();

        $this->assertCount(1, $pages);
        $this->assertSame('billing', $pages[0]['key']);
        $this->assertSame('/billing/settings', $pages[0]['href']);
        $this->assertSame('/billing/settings', $pages[0]['match'], 'match defaults to href');
        $this->assertSame('fa-sliders', $pages[0]['icon'], 'default icon');
        $this->assertNull($pages[0]['resource']);
    }

    #[Test]
    public function settings_requires_key_label_and_href(): void
    {
        Tiger_Admin_Settings::register(['label' => 'No key', 'href' => '/x']);
        Tiger_Admin_Settings::register(['key' => 'k', 'href' => '/x']);          // no label
        Tiger_Admin_Settings::register(['key' => 'k', 'label' => 'No href']);    // no href
        $this->assertSame([], Tiger_Admin_Settings::pages());
    }

    #[Test]
    public function settings_dedupes_by_key_last_registration_wins(): void
    {
        Tiger_Admin_Settings::register(['key' => 'cms', 'label' => 'CMS', 'href' => '/cms/settings']);
        Tiger_Admin_Settings::register(['key' => 'cms', 'label' => 'Content', 'href' => '/cms/settings']);
        $pages = Tiger_Admin_Settings::pages();

        $this->assertCount(1, $pages);
        $this->assertSame('Content', $pages[0]['label']);
    }

    #[Test]
    public function settings_sorts_by_order_then_label(): void
    {
        Tiger_Admin_Settings::register(['key' => 'z', 'label' => 'Zed',   'href' => '/z', 'order' => 50]);
        Tiger_Admin_Settings::register(['key' => 'a', 'label' => 'Alpha', 'href' => '/a', 'order' => 100]);
        Tiger_Admin_Settings::register(['key' => 'b', 'label' => 'Beta',  'href' => '/b', 'order' => 100]);

        $keys = array_column(Tiger_Admin_Settings::pages(), 'key');
        // order 50 first; then order 100 broken by label ASC (Alpha before Beta).
        $this->assertSame(['z', 'a', 'b'], $keys);
    }

    // ----- Tiger_Admin_Nav (discovery-latch suppressed) ----------------------------------------

    #[Test]
    public function nav_register_shapes_the_item_with_defaults(): void
    {
        $this->suppressDiscovery(Tiger_Admin_Nav::class);
        Tiger_Admin_Nav::register(['key' => 'help', 'label' => 'Help', 'href' => '/docs/admin/help']);
        $items = Tiger_Admin_Nav::items();

        $this->assertCount(1, $items);
        $this->assertSame('help', $items[0]['key']);
        $this->assertSame('fa-circle', $items[0]['icon']);
        $this->assertSame('/docs/admin/help', $items[0]['match'], 'match defaults to href');
        $this->assertSame(100, $items[0]['order'], 'default order');
    }

    #[Test]
    public function nav_requires_key_label_and_href(): void
    {
        $this->suppressDiscovery(Tiger_Admin_Nav::class);
        Tiger_Admin_Nav::register(['label' => 'x', 'href' => '/x']);
        Tiger_Admin_Nav::register(['key' => 'k', 'label' => 'x']);
        $this->assertSame([], Tiger_Admin_Nav::items());
    }

    #[Test]
    public function nav_sorts_by_order_then_label(): void
    {
        $this->suppressDiscovery(Tiger_Admin_Nav::class);
        Tiger_Admin_Nav::register(['key' => 'a', 'label' => 'Apps',  'href' => '/a', 'order' => 90, 'resource' => 'A_C']);
        Tiger_Admin_Nav::register(['key' => 'b', 'label' => 'Boards','href' => '/b', 'order' => 10]);

        $keys = array_column(Tiger_Admin_Nav::items(), 'key');
        $this->assertSame(['b', 'a'], $keys);
        // the resource passes through untouched for the ACL filter downstream.
        $this->assertSame('A_C', Tiger_Admin_Nav::items()[1]['resource']);
    }

    #[Test]
    public function nav_clear_also_resets_the_discovery_latch(): void
    {
        $this->suppressDiscovery(Tiger_Admin_Nav::class);
        Tiger_Admin_Nav::register(['key' => 'x', 'label' => 'X', 'href' => '/x']);
        $this->assertCount(1, Tiger_Admin_Nav::items());

        Tiger_Admin_Nav::clear();
        // With the latch reset, items() runs discover() over the real module tree; assert it never
        // fatals and that our cleared item is gone.
        $items = Tiger_Admin_Nav::items();
        $this->assertIsArray($items);
        $this->assertNotContains('x', array_column($items, 'key'));
    }

    #[Test]
    public function nav_discovery_reads_a_modules_navigation_ini_and_survives_a_dbless_boot(): void
    {
        // Latch OFF: items() runs the real discover(). tiger-core ships modules/analytics/configs/
        // navigation.ini, and there's no DB in a unit run — inactiveSlugs() throws and is swallowed
        // (show everything), so the analytics nav item is discovered from config with zero code.
        Tiger_Admin_Nav::clear();
        $keys = array_column(Tiger_Admin_Nav::items(), 'key');
        $this->assertContains('analytics', $keys, 'a module navigation.ini is auto-discovered');
    }

    // ----- Tiger_Admin_Header + Tiger_Admin_UserMenu (same shape) ------------------------------

    #[Test]
    public function header_register_items_shape_and_order(): void
    {
        $this->suppressDiscovery(Tiger_Admin_Header::class);
        Tiger_Admin_Header::register(['key' => 'support', 'label' => 'Support', 'icon' => 'fa-life-ring', 'href' => '/support', 'order' => 50]);
        Tiger_Admin_Header::register(['key' => 'new', 'label' => 'New', 'href' => '/new', 'order' => 10]);
        Tiger_Admin_Header::register(['label' => 'bad']);   // dropped — no key/href

        $items = Tiger_Admin_Header::items();
        $this->assertSame(['new', 'support'], array_column($items, 'key'));
        $this->assertSame('fa-life-ring', $items[1]['icon']);
        $this->assertSame('fa-circle', $items[0]['icon'], 'default icon');
    }

    #[Test]
    public function header_discovery_runs_over_the_module_tree_without_fatal(): void
    {
        // Latch OFF: items() runs the real discover() (glob the module dirs, read each header.ini).
        // No core module ships one, so the result is empty — but the discovery path itself must run
        // and, with no DB, must not fatal (inactiveSlugs() throws → swallowed).
        Tiger_Admin_Header::clear();
        $this->assertIsArray(Tiger_Admin_Header::items());
    }

    #[Test]
    public function usermenu_discovery_runs_over_the_module_tree_without_fatal(): void
    {
        Tiger_Admin_UserMenu::clear();
        $this->assertIsArray(Tiger_Admin_UserMenu::items());
    }

    #[Test]
    public function usermenu_register_items_shape_and_dedupe(): void
    {
        $this->suppressDiscovery(Tiger_Admin_UserMenu::class);
        Tiger_Admin_UserMenu::register(['key' => 'billing', 'label' => 'Billing', 'href' => '/billing/account', 'resource' => 'Billing_AccountController']);
        Tiger_Admin_UserMenu::register(['key' => 'billing', 'label' => 'Plan', 'href' => '/billing/plan']);   // replaces by key

        $items = Tiger_Admin_UserMenu::items();
        $this->assertCount(1, $items);
        $this->assertSame('Plan', $items[0]['label']);
        $this->assertNull($items[0]['resource'], 'the replacing registration carried no resource');
    }
}
