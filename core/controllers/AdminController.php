<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * AdminController — the default-namespace admin dashboard (MIT, Tiger-original).
 *
 * Proves the PUMA admin shell end-to-end: it opts into the 'admin' layout and
 * renders a dashboard. ACL-gated to admin and above (see core/configs/acl.ini) by
 * Tiger_Controller_Plugin_Authorization — a guest hitting /admin is bounced to
 * login, a logged-in non-admin gets 403.
 *
 * Apps typically build their own admin as a MODULE; this ships so a fresh install
 * has a working, themed back office out of the box.
 */
class AdminController extends Tiger_Controller_Admin_Action
{
    /**
     * Admin shell (layout) comes from the base; keep the explicit init cascade.
     *
     * @return void
     */
    public function init()
    {
        parent::init();
    }

    /** Per-user dashboard layout (order + collapsed) lives in the lazy option tier under this key. */
    const LAYOUT_KEY = 'tiger.dashboard.layout';

    /** Per-user widget visibility ({"hidden":[id,…]}) — which widgets the user has switched off. */
    const PREFS_KEY = 'tiger.dashboard.prefs';

    /**
     * The dashboard — a grid of module-registered widgets (Tiger_Dashboard). ACL-filtered to the
     * current admin, ordered by the user's saved layout, and rendered into a Muuri masonry grid by the
     * view. Nothing is hard-coded: the dashboard is empty until a module registers a widget.
     *
     * @return void
     */
    public function indexAction()
    {
        $this->view->title = 'Dashboard — Tiger Admin';

        $widgets = Tiger_Dashboard::allowed();               // registered + ACL-filtered for this role

        // Per-user personalization from the lazy option tier: saved order + collapsed state, plus the
        // set of widgets the user switched off (those render as a lightweight header shell, out of the
        // grid — the body is NOT rendered, so a hidden widget costs no work; see the view + service).
        $layout = [];
        $hidden = [];
        $uid    = self::_userId();
        if ($uid !== '') {
            $opt   = new Tiger_Model_Option();
            $saved = $opt->getJson(Tiger_Model_Option::SCOPE_USER, $uid, self::LAYOUT_KEY, []);
            if (is_array($saved)) { $layout = $saved; }
            $prefs = $opt->getJson(Tiger_Model_Option::SCOPE_USER, $uid, self::PREFS_KEY, []);
            if (is_array($prefs) && isset($prefs['hidden']) && is_array($prefs['hidden'])) { $hidden = $prefs['hidden']; }
        }

        $widgets   = self::_applyLayout($widgets, $layout);
        $hiddenSet = array_flip(array_map('strval', $hidden));
        foreach ($widgets as &$w) { $w['hidden'] = isset($hiddenSet[$w['id']]); }
        unset($w);

        $this->view->widgets = $widgets;
    }

    /** The current user id, or '' when unauthenticated. */
    protected static function _userId()
    {
        try {
            $identity = Zend_Auth::getInstance()->getIdentity();
            return ($identity && !empty($identity->user_id)) ? (string) $identity->user_id : '';
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * Order the allowed widgets by the user's saved order (new/unknown widgets append in their default
     * order) and attach each one's saved collapsed flag. Layout shape:
     * {"order":[id,…], "collapsed":{id:true}}.
     *
     * @param  array $widgets ACL-filtered descriptors
     * @param  array $layout  the user's saved layout
     * @return array<int,array> ordered descriptors, each with a bool 'collapsed'
     */
    protected static function _applyLayout(array $widgets, array $layout)
    {
        $order     = (isset($layout['order']) && is_array($layout['order'])) ? $layout['order'] : [];
        $collapsed = (isset($layout['collapsed']) && is_array($layout['collapsed'])) ? $layout['collapsed'] : [];

        $byId = [];
        foreach ($widgets as $w) { $byId[$w['id']] = $w; }

        $ordered = [];
        foreach ($order as $id) {
            if (isset($byId[$id])) { $ordered[] = $byId[$id]; unset($byId[$id]); }
        }
        foreach ($byId as $w) { $ordered[] = $w; }          // widgets not yet in the saved order

        foreach ($ordered as &$w) { $w['collapsed'] = !empty($collapsed[$w['id']]); }
        unset($w);
        return $ordered;
    }
}
