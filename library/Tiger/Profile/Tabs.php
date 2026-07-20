<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Profile_Tabs — the extensible tab registry behind the self-service profile surfaces.
 *
 * Tiger ships only a BASE profile: a person edits their own account, and an org admin edits
 * their org — each a tabbed screen. The tab set is deliberately OPEN: a module registers its
 * own tab the same way it registers an admin Settings page (Tiger_Admin_Settings::register), so
 * a future billing module drops a "Billing" tab into the user profile without touching this
 * module. That extensibility is the platform's job; the *opinion* of what a profile contains is
 * the product-builder's (see the base-SaaS principle in ARCHITECTURE / AGENTS).
 *
 * Two independent contexts:
 *   - CONTEXT_USER  a person's own account   (/profile,     role `user`)
 *   - CONTEXT_ORG   an org's own profile     (/profile/org, role `admin`)
 *
 * A tab is a plain array: ['key','label','icon','order','view'] where `label` is a translate
 * key, `icon` a Font Awesome class, `order` sorts ascending, and `view` is the module-relative
 * partial that renders the pane (e.g. 'index/_basic'). The registering module owns that partial,
 * which is how a third-party tab supplies its own UI.
 *
 * @api
 */
class Tiger_Profile_Tabs
{
    const CONTEXT_USER = 'user';
    const CONTEXT_ORG  = 'org';

    /** @var array<string,array<string,array>> context => key => tab (last registration of a key wins) */
    protected static $_tabs = [self::CONTEXT_USER => [], self::CONTEXT_ORG => []];

    /**
     * Register (or override) a tab in a context. Re-registering the same key wins, so an app can
     * replace a built-in tab with its own. No-ops on an unknown context or a keyless tab.
     *
     * @param  string $context CONTEXT_USER | CONTEXT_ORG
     * @param  array  $tab     ['key','label','icon'?,'order'?,'view']
     * @return void
     */
    public static function register($context, array $tab)
    {
        if (!array_key_exists($context, self::$_tabs) || empty($tab['key'])) {
            return;
        }
        $tab += ['label' => (string) $tab['key'], 'icon' => 'fa-circle', 'order' => 100, 'view' => ''];
        self::$_tabs[$context][$tab['key']] = $tab;
    }

    /**
     * All registered tabs for a context, ordered by `order` then `label`.
     *
     * @param  string $context CONTEXT_USER | CONTEXT_ORG
     * @return array<int,array> ordered tabs
     */
    public static function all($context)
    {
        $tabs = array_values(self::$_tabs[$context] ?? []);
        usort($tabs, static function ($a, $b) {
            return ($a['order'] <=> $b['order']) ?: strcmp((string) $a['label'], (string) $b['label']);
        });
        return $tabs;
    }

    /** Clear the registry — test seam only. @return void */
    public static function reset()
    {
        self::$_tabs = [self::CONTEXT_USER => [], self::CONTEXT_ORG => []];
    }
}
