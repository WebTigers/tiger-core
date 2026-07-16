<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Dashboard — the admin dashboard widget registry (the module hook).
 *
 * A module contributes an at-a-glance card to the admin dashboard the same way it contributes a
 * settings page (Tiger_Admin_Settings) — one `registerWidget()` call from its Bootstrap, no core file
 * to edit, ACL- and activation-gated for free (an inactive module never bootstraps, so its widget never
 * registers). This is Tiger's answer to WordPress's `wp_add_dashboard_widget()`.
 *
 *   Tiger_Dashboard::registerWidget([
 *       'id'       => 'billing.revenue',            // unique, dot-namespaced by module (dedupes)
 *       'module'   => 'billing',
 *       'title'    => 'Revenue',                    // card heading (i18n key or literal)
 *       'icon'     => 'fa-chart-line',
 *       'widget'   => 'Billing_Widget_Revenue',     // a class with render():string (+ optional data())
 *       'resource' => 'Billing_AdminController',     // ACL resource — the card hides if the role is denied
 *       'width'    => 1,                             // column span, 1..4 (clamped)
 *       'order'    => 50,                            // sort weight (lower first); default 100
 *       'refresh'  => 60,                            // optional client auto-refresh seconds (0 = static)
 *   ]);
 *
 * The dashboard **shell** owns the card chrome (drag handle, collapse, sizing, the Muuri masonry grid,
 * and per-user layout persistence in the `option` tier); the **widget class** owns only the card BODY
 * (its `render()` output). Widgets are self-contained — a class exposing `render()` is enough; there's
 * no base class to extend (though one may be added later for caching/partials).
 *
 * @api
 */
class Tiger_Dashboard
{
    /** @var array<string,array> id => descriptor */
    protected static $_widgets = [];

    /**
     * Register (or replace, by id) a dashboard widget. Requires `id` and `widget`; everything else
     * takes a sane default.
     *
     * @param  array $descriptor id, widget, and optional module/title/icon/resource/width/order/refresh
     * @return void
     */
    public static function registerWidget(array $descriptor)
    {
        if (empty($descriptor['id']) || empty($descriptor['widget'])) {
            return;
        }
        $descriptor += [
            'module'   => null,
            'title'    => $descriptor['id'],
            'icon'     => 'fa-square',
            'resource' => null,
            'width'    => 1,
            'order'    => 100,
            'refresh'  => 0,
        ];
        $descriptor['width'] = max(1, min(4, (int) $descriptor['width']));   // 1..4 columns
        self::$_widgets[$descriptor['id']] = $descriptor;
    }

    /**
     * All registered widgets, sorted by order then title.
     *
     * @return array<int,array>
     */
    public static function all()
    {
        $widgets = array_values(self::$_widgets);
        usort($widgets, function ($a, $b) {
            return [$a['order'], $a['title']] <=> [$b['order'], $b['title']];
        });
        return $widgets;
    }

    /**
     * The widgets the CURRENT identity may see — `all()` filtered by each descriptor's ACL `resource`
     * against the live role. A widget with no `resource` is visible to anyone who can reach the
     * dashboard (which is already admin-gated). An unknown/denied resource hides the widget (fail-safe).
     *
     * @return array<int,array>
     */
    public static function allowed()
    {
        $acl  = Zend_Registry::isRegistered('Zend_Acl') ? Zend_Registry::get('Zend_Acl') : null;
        $role = null;
        try {
            $identity = Zend_Auth::getInstance()->getIdentity();
            if ($identity && isset($identity->role)) { $role = $identity->role; }
        } catch (Throwable $e) { /* no identity → role stays null */ }

        $out = [];
        foreach (self::all() as $descriptor) {
            if (!empty($descriptor['resource']) && $acl) {
                try {
                    if (!$acl->isAllowed($role, $descriptor['resource'])) { continue; }
                } catch (Throwable $e) {
                    continue;   // resource not in the ACL → hide it rather than error the dashboard
                }
            }
            $out[] = $descriptor;
        }
        return $out;
    }

    /**
     * A single descriptor by id, or null.
     *
     * @param  string $id
     * @return array|null
     */
    public static function get($id)
    {
        return self::$_widgets[$id] ?? null;
    }

    /**
     * Render a widget's BODY HTML by instantiating its class and calling render(). Fail-soft: a broken
     * widget yields a small notice, never a dashboard-wide error.
     *
     * @param  array $descriptor a registered descriptor
     * @return string the card-body HTML
     */
    public static function renderBody(array $descriptor)
    {
        $class = $descriptor['widget'] ?? '';
        if ($class === '' || !class_exists($class)) {
            return '';
        }
        try {
            $widget = new $class();
            if (!method_exists($widget, 'render')) {
                return '';
            }
            return (string) $widget->render();
        } catch (Throwable $e) {
            if (class_exists('Tiger_Log')) {
                Tiger_Log::warn('dashboard.widget', ['id' => $descriptor['id'] ?? '?', 'error' => $e->getMessage()]);
            }
            return '<div class="text-body-secondary small">This widget could not be displayed.</div>';
        }
    }

    /**
     * Reset the registry (tests).
     *
     * @return void
     */
    public static function clear()
    {
        self::$_widgets = [];
    }
}
