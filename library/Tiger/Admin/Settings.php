<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Admin_Settings — the admin "Settings" registry (the module hook).
 *
 * A module contributes its own settings page under the admin sidebar's **Settings** tree by
 * registering here from its Bootstrap — no core file to edit, and it's ACL-gated + activation-
 * gated for free (an inactive module never bootstraps, so its page never registers). Core
 * pre-registers CMS and System; a third party adds one line:
 *
 *   Tiger_Admin_Settings::register([
 *       'key'      => 'billing',                 // unique; dedupes
 *       'label'    => 'Billing',
 *       'icon'     => 'fa-credit-card',          // shown on a landing page (nav shows submenu text)
 *       'href'     => '/billing/settings',       // the module's own admin-layout screen
 *       'resource' => 'Billing_SettingsController', // ACL resource — the item hides if denied
 *       'order'    => 50,                        // sort weight (lower first); default 100
 *   ]);
 *
 * The sidebar builds the Settings submenu from pages(); URL namespace follows module ownership
 * (/cms/settings, /system/settings, /billing/settings) while the shared admin layout + this
 * registry deliver one consistent experience.
 *
 * @api
 */
class Tiger_Admin_Settings
{
    /** @var array<string,array> key => page definition */
    protected static $_pages = [];

    /**
     * Register (or replace, by key) a settings page. Requires key, label, href; icon,
     * resource, order, and match are optional.
     */
    public static function register(array $page)
    {
        if (empty($page['key']) || empty($page['label']) || empty($page['href'])) {
            return;
        }
        self::$_pages[$page['key']] = $page + [
            'icon'     => 'fa-sliders',
            'resource' => null,
            'order'    => 100,
            'match'    => $page['href'],
        ];
    }

    /**
     * The registered pages as sidebar nav-item arrays (label/href/match/icon/resource),
     * sorted by order then label. ACL filtering happens in the menu partial, live per role.
     *
     * @return array<int,array>
     */
    public static function pages()
    {
        $pages = array_values(self::$_pages);
        usort($pages, function ($a, $b) {
            return [$a['order'], $a['label']] <=> [$b['order'], $b['label']];
        });
        return array_map(function ($p) {
            return [
                'label'    => $p['label'],
                'href'     => $p['href'],
                'match'    => $p['match'],
                'icon'     => $p['icon'],
                'resource' => $p['resource'],
            ];
        }, $pages);
    }

    /** Reset the registry (tests). */
    public static function clear()
    {
        self::$_pages = [];
    }
}
