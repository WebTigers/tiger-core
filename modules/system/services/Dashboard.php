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

        $uid = '';
        try {
            $identity = Zend_Auth::getInstance()->getIdentity();
            $uid = ($identity && !empty($identity->user_id)) ? (string) $identity->user_id : '';
        } catch (Throwable $e) { /* fall through */ }
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
}
