<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Admin_Header — the registry for action slots in the admin's TOP HEADER BAR.
 *
 * Sibling of Tiger_Admin_Nav / _Settings / _UserMenu: a module adds an icon action to the header bar
 * itself (next to the notification bell) — a support launcher, a "new" shortcut, a status indicator.
 * Two equal ways, ACL- and activation-gated for free:
 *   - **Config** — a `configs/header.ini` (auto-discovered at bootstrap; see discover()). Zero code.
 *   - **Code** — `register()` from the module Bootstrap.
 * The PUMA admin-header renders these (ACL-filtered, ordered) as icon buttons before the notifications
 * area. Each is an icon linking somewhere; `label` is the tooltip/aria-label.
 *
 *   Tiger_Admin_Header::register([
 *       'key'      => 'support',                    // unique; dedupes
 *       'label'    => 'Support',                    // tooltip / aria-label (icon-only button)
 *       'icon'     => 'fa-life-ring',
 *       'href'     => '/support',
 *       'resource' => 'Support_IndexController',     // ACL resource — hides if denied
 *       'order'    => 50,                            // sort weight (lower first)
 *   ]);
 *
 * @api
 */
class Tiger_Admin_Header
{
    /** @var array<string,array> key => item definition */
    protected static $_items = [];

    /** @var bool whether module header.ini files have been discovered this request */
    protected static $_loaded = false;

    /**
     * Register (or replace, by key) a header-bar action. Requires key, label, href.
     *
     * @param  array $item key, label (tooltip), href, and optional icon/resource/order
     * @return void
     */
    public static function register(array $item)
    {
        if (empty($item['key']) || empty($item['label']) || empty($item['href'])) {
            return;
        }
        self::$_items[$item['key']] = $item + ['icon' => 'fa-circle', 'resource' => null, 'order' => 100];
    }

    /**
     * The registered items sorted by order then label. ACL filtering happens in the view, live per role.
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
                'icon'     => $p['icon'],
                'resource' => $p['resource'],
                'order'    => $p['order'],
            ];
        }, $items);
    }

    /** Reset the registry (tests). @return void */
    public static function clear()
    {
        self::$_items  = [];
        self::$_loaded = false;
    }

    /**
     * Auto-discover items from each ACTIVE module's `configs/header.ini` — mirrors Tiger_Admin_Nav.
     *
     *   ; modules/<name>/configs/header.ini
     *   header.support.label    = "Support"
     *   header.support.icon     = "fa-life-ring"
     *   header.support.href     = "/support"
     *   header.support.resource = "Support_IndexController"
     *   header.support.order    = 50
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
        if (defined('TIGER_CORE_PATH'))  { $dirs[] = TIGER_CORE_PATH . '/modules'; }
        if (defined('APPLICATION_PATH')) { $dirs[] = APPLICATION_PATH . '/modules'; }

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
                    continue;
                }
                $ini = $moduleDir . '/configs/header.ini';
                if (!is_file($ini)) {
                    continue;
                }
                try {
                    $cfg = (new Zend_Config_Ini($ini))->get('header');
                    if (!($cfg instanceof Zend_Config)) {
                        continue;
                    }
                    foreach ($cfg as $key => $item) {
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
                    error_log('Tiger_Admin_Header: header.ini failed to load: ' . $ini . ' — ' . $e->getMessage());
                }
            }
        }
    }
}
