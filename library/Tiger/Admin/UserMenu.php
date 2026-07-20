<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Admin_UserMenu — the registry for items in the header's USER dropdown (the avatar menu).
 *
 * Sibling of Tiger_Admin_Nav (sidebar) and Tiger_Admin_Settings (settings tree): a module drops an
 * item into the user menu — a "Billing", "My Team", "API Keys" link, etc. — two equal ways, ACL- and
 * activation-gated for free:
 *   - **Config** — a `configs/usermenu.ini` (auto-discovered at bootstrap; see discover()). Zero code.
 *   - **Code** — `register()` from the module Bootstrap, for an item needing logic.
 * The PUMA admin-header renders these (ACL-filtered, ordered) between the core account items and the
 * Lock/Sign-out group.
 *
 *   Tiger_Admin_UserMenu::register([
 *       'key'      => 'billing',                   // unique; dedupes
 *       'label'    => 'Billing',
 *       'icon'     => 'fa-credit-card',
 *       'href'     => '/billing/account',
 *       'resource' => 'Billing_AccountController',  // ACL resource — the item hides if denied
 *       'order'    => 50,                           // sort weight among registered items (lower first)
 *   ]);
 *
 * @api
 */
class Tiger_Admin_UserMenu
{
    /** @var array<string,array> key => item definition */
    protected static $_items = [];

    /** @var bool whether module usermenu.ini files have been discovered this request */
    protected static $_loaded = false;

    /**
     * Register (or replace, by key) a user-menu item. Requires key, label, href.
     *
     * @param  array $item key, label, href, and optional icon/resource/order
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
     * Auto-discover items from each ACTIVE module's `configs/usermenu.ini` — mirrors Tiger_Admin_Nav.
     * A module declares a user-menu item purely in config, no Bootstrap code:
     *
     *   ; modules/<name>/configs/usermenu.ini
     *   usermenu.billing.label    = "Billing"
     *   usermenu.billing.icon     = "fa-credit-card"
     *   usermenu.billing.href     = "/billing/account"
     *   usermenu.billing.resource = "Billing_AccountController"
     *   usermenu.billing.order    = 50
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
                $ini = $moduleDir . '/configs/usermenu.ini';
                if (!is_file($ini)) {
                    continue;
                }
                try {
                    $cfg = (new Zend_Config_Ini($ini))->get('usermenu');
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
                    error_log('Tiger_Admin_UserMenu: usermenu.ini failed to load: ' . $ini . ' — ' . $e->getMessage());
                }
            }
        }
    }
}
