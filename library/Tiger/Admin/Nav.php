<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Admin_Nav — the admin sidebar's TOP-LEVEL nav registry (the module hook).
 *
 * Sibling of Tiger_Admin_Settings: where that adds a page under the **Settings** submenu, this adds
 * a **top-level** item to the admin sidebar. A module contributes one **two equal ways**:
 *   - **Config** — drop a `configs/navigation.ini` (auto-discovered at bootstrap; see discover()).
 *     The zero-code default: declarative, no Bootstrap method.
 *   - **Code** — call `register()` from the module Bootstrap, for an item that needs logic (a
 *     computed label/href, a conditional item).
 * Either way it's ACL-gated + activation-gated for free (a deactivated module's item is skipped).
 * The PUMA `admin-menu` partial merges these in ahead of Settings:
 *
 *   Tiger_Admin_Nav::register([
 *       'key'      => 'docs_help',                 // unique; dedupes
 *       'label'    => 'Help',
 *       'icon'     => 'fa-circle-question',
 *       'href'     => '/docs/admin/help',
 *       'match'    => '/docs/admin/help',          // path prefix that marks it active (default: href)
 *       'resource' => 'Docs_AdminController',      // ACL resource — the item hides if denied
 *       'order'    => 90,                          // sort weight among registered items (lower first)
 *   ]);
 *
 * @api
 */
class Tiger_Admin_Nav
{
    /** @var array<string,array> key => item definition */
    protected static $_items = [];

    /** @var bool whether module menu.ini files have been discovered this request */
    protected static $_loaded = false;

    /**
     * Register (or replace, by key) a top-level nav item. Requires key, label, href.
     *
     * @param  array $item item definition (key, label, href, and optional icon/match/resource/order)
     * @return void
     */
    public static function register(array $item)
    {
        if (empty($item['key']) || empty($item['label']) || empty($item['href'])) {
            return;
        }
        self::$_items[$item['key']] = $item + [
            'icon'     => 'fa-circle',
            'match'    => $item['href'],
            'resource' => null,
            'order'    => 100,
        ];
    }

    /**
     * The registered items as sidebar nav-item arrays (label/href/match/icon/resource), sorted by
     * order then label. ACL filtering happens in the menu partial, live per role.
     *
     * @return array<int,array>
     */
    public static function items()
    {
        self::discover();
        $items = array_values(self::$_items);
        usort($items, static function ($a, $b) {
            return [$a['order'], $a['label']] <=> [$b['order'], $b['label']];
        });
        return array_map(static function ($p) {
            return [
                'key'      => $p['key'],
                'label'    => $p['label'],
                'href'     => $p['href'],
                'match'    => $p['match'],
                'icon'     => $p['icon'],
                'resource' => $p['resource'],
                'order'    => $p['order'],
            ];
        }, $items);
    }

    /**
     * Reset the registry (tests).
     *
     * @return void
     */
    public static function clear()
    {
        self::$_items = [];
        self::$_loaded = false;
    }

    /**
     * Auto-discover top-level nav items from each ACTIVE module's `configs/navigation.ini` — mirrors
     * how `Tiger_Acl_Acl` discovers `acl.ini`, and consumed once at bootstrap
     * (`Tiger_Application_Bootstrap::_initAdminNavigation`). A module can declare a sidebar item
     * purely in config, with **no** Bootstrap code:
     *
     *   ; modules/<name>/configs/navigation.ini
     *   nav.analytics.label    = "Analytics"
     *   nav.analytics.icon     = "fa-chart-line"
     *   nav.analytics.href     = "/analytics/admin/dashboard"
     *   nav.analytics.match    = "/analytics/admin"        ; path prefix that marks it active
     *   nav.analytics.resource = "Analytics_AdminController" ; ACL-gated — hides for denied roles
     *   nav.analytics.order    = 40
     *
     * This is one of TWO equal ways to register a nav item — the other is `register()` from a
     * module Bootstrap (for items that need code, e.g. a computed label). Both coexist and dedupe
     * by key. Idempotent + memoized (safe to call from bootstrap and again from items()).
     *
     * @return void
     */
    public static function discover()
    {
        if (self::$_loaded) {
            return;
        }
        self::$_loaded = true;

        $dirs = [];
        if (defined('TIGER_CORE_PATH'))  { $dirs[] = TIGER_CORE_PATH . '/modules'; }   // first-party (vendor)
        if (defined('APPLICATION_PATH')) { $dirs[] = APPLICATION_PATH . '/modules'; }  // app (wins on collision)

        $inactive = [];
        try {
            if (class_exists('Tiger_Model_Module')) {
                $inactive = (new Tiger_Model_Module())->inactiveSlugs();
            }
        } catch (Throwable $e) {
            // DB not ready (install/CLI) — show everything rather than nothing.
        }

        foreach ($dirs as $modsDir) {
            foreach (glob($modsDir . '/*', GLOB_ONLYDIR) ?: [] as $moduleDir) {
                if (in_array(basename($moduleDir), $inactive, true)) {
                    continue;   // a deactivated module never shows a nav link
                }
                $ini = $moduleDir . '/configs/navigation.ini';
                if (!is_file($ini)) {
                    continue;
                }
                try {
                    $nav = (new Zend_Config_Ini($ini))->get('nav');
                    if (!($nav instanceof Zend_Config)) {
                        continue;
                    }
                    foreach ($nav as $key => $item) {
                        if (!($item instanceof Zend_Config)) {
                            continue;
                        }
                        $arr = $item->toArray();
                        if (empty($arr['key'])) {
                            $arr['key'] = (string) $key;
                        }
                        self::register($arr);
                    }
                } catch (Throwable $e) {
                    error_log('Tiger_Admin_Nav: menu.ini failed to load: ' . $ini . ' — ' . $e->getMessage());
                }
            }
        }
    }
}
