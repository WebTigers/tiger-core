<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * System_Service_Dashboard — /api service for the admin dashboard.
 *
 * Persists a user's widget layout (order + collapsed state) to the LAZY option tier
 * (Tiger_Model_Option, scope=user) — the config-discipline home for private per-user state, read only
 * when the dashboard renders (never folded into the request-wide config cascade). ACL: admin+
 * (modules/system/configs/acl.ini). The dashboard itself is rendered by the core AdminController.
 *
 * @api
 */
class System_Service_Dashboard extends Tiger_Service_Service
{
    const LAYOUT_KEY = 'tiger.dashboard.layout';
    const PREFS_KEY  = 'tiger.dashboard.prefs';

    /**
     * Save the current user's dashboard layout. Fail-soft: an unparseable or oversized payload is
     * ignored (layout is convenience state, never worth an error). Only known widget ids are stored.
     *
     * @param  array $params expects `layout` — a JSON string {"order":[id,…],"collapsed":{id:true}}
     * @return void
     */
    public function saveLayout(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }

        $uid = $this->_uid();
        if ($uid === '') { $this->_error('core.api.error.not_allowed'); return; }

        $raw = (string) ($params['layout'] ?? '');
        if ($raw === '' || strlen($raw) > 20000) { $this->_success([], 'system.dashboard.saved'); return; }
        $layout = json_decode($raw, true);
        if (!is_array($layout)) { $this->_success([], 'system.dashboard.saved'); return; }

        // Keep only known widget ids in the order + collapsed map (hygiene — no junk in the store).
        $known = [];
        foreach (Tiger_Dashboard::all() as $w) { $known[$w['id']] = true; }

        $order = [];
        foreach ((array) ($layout['order'] ?? []) as $id) {
            $id = (string) $id;
            if (isset($known[$id]) && !in_array($id, $order, true)) { $order[] = $id; }
        }
        $collapsed = [];
        foreach ((array) ($layout['collapsed'] ?? []) as $id => $on) {
            $id = (string) $id;
            if (isset($known[$id]) && $on) { $collapsed[$id] = true; }
        }

        try {
            (new Tiger_Model_Option())->setJson(
                Tiger_Model_Option::SCOPE_USER, $uid, self::LAYOUT_KEY,
                ['order' => $order, 'collapsed' => $collapsed]
            );
            $this->_success([], 'system.dashboard.saved');
        } catch (Throwable $e) {
            $this->_error(APPLICATION_ENV !== 'production' ? $e->getMessage() : 'core.api.error.general');
        }
    }

    /**
     * Save the current user's widget VISIBILITY — which widgets they've switched off (WP "Screen
     * Options" style). Fail-soft; only known widget ids are stored. An empty list means "show all".
     *
     * @param  array $params expects `hidden` — a JSON array ["id",…] of hidden widget ids
     * @return void
     */
    public function saveWidgetPrefs(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }

        $uid = $this->_uid();
        if ($uid === '') { $this->_error('core.api.error.not_allowed'); return; }

        $raw = (string) ($params['hidden'] ?? '');
        if (strlen($raw) > 20000) { $this->_success([], 'system.dashboard.saved'); return; }
        $hidden = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($hidden)) { $this->_success([], 'system.dashboard.saved'); return; }

        $known = [];
        foreach (Tiger_Dashboard::all() as $w) { $known[$w['id']] = true; }
        $clean = [];
        foreach ($hidden as $id) {
            $id = (string) $id;
            if (isset($known[$id]) && !in_array($id, $clean, true)) { $clean[] = $id; }
        }

        try {
            (new Tiger_Model_Option())->setJson(Tiger_Model_Option::SCOPE_USER, $uid, self::PREFS_KEY, ['hidden' => $clean]);
            $this->_success([], 'system.dashboard.saved');
        } catch (Throwable $e) {
            $this->_error(APPLICATION_ENV !== 'production' ? $e->getMessage() : 'core.api.error.general');
        }
    }

    /**
     * Render a single ALLOWED widget's body HTML on demand. Used when a hidden widget is switched back
     * on, so the dashboard updates without a page reload (a hidden widget isn't rendered on first load,
     * so its markup has to be fetched). ACL-scoped via Tiger_Dashboard::allowed() — you can only render
     * a widget you're permitted to see.
     *
     * @param  array $params expects `id` — the widget id
     * @return void
     */
    public function widgetBody(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }

        $id = (string) ($params['id'] ?? '');
        foreach (Tiger_Dashboard::allowed() as $w) {
            if ($w['id'] === $id) {
                $this->_success(['html' => Tiger_Dashboard::renderBody($w)]);
                return;
            }
        }
        $this->_error('core.api.error.not_allowed');
    }

    /** The current user id, or '' when unauthenticated. */
    protected function _uid()
    {
        try {
            $identity = Zend_Auth::getInstance()->getIdentity();
            return ($identity && !empty($identity->user_id)) ? (string) $identity->user_id : '';
        } catch (Throwable $e) {
            return '';
        }
    }
}
