<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * System_Service_Nav — persists a user's admin-menu sort order. The sidebar (tiger.admin-nav.js)
 * posts the new order of a group's item keys after a drag; we write one `tiger.nav.<group>.<key>.sort`
 * config row per item at USER scope. Those rows fold into the config cascade at bootstrap (the USER
 * tier), so the menu partial renders them without any further wiring — a per-user override of the
 * default order, no deploy. Admin+ (modules/system/configs/acl.ini). Fail-soft: a malformed or
 * oversized payload is a no-op, never an error.
 *
 * @api
 */
class System_Service_Nav extends Tiger_Service_Service
{
    /** Sanity cap: a single group won't ever have this many items. */
    const MAX_KEYS = 60;

    /**
     * Save the order of one nav group for the current user.
     *
     * @param  array $params `group` (the parent key: 'root' or a submenu key) + `keys` (JSON array of
     *                        item keys in their new order)
     * @return void
     */
    public function sort(array $params): void
    {
        if (!$this->_isAdmin()) {
            $this->_error('core.api.error.not_allowed');
            return;
        }
        $uid = $this->_userId();
        if ($uid === '') {
            $this->_error('core.api.error.login_required');
            return;
        }

        $group = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($params['group'] ?? ''));
        $keys  = json_decode((string) ($params['keys'] ?? ''), true);
        if ($group === '' || !is_array($keys) || count($keys) > self::MAX_KEYS) {
            $this->_success([]);   // fail-soft — nothing to save
            return;
        }

        try {
            $this->_transaction(function () use ($group, $keys, $uid) {
                $cfg = new Tiger_Model_Config();
                $i   = 0;
                foreach ($keys as $key) {
                    $key = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $key);
                    if ($key === '') { continue; }
                    // Index-based (matches the partial's position-based default scale), user scope.
                    $cfg->set(Tiger_Model_Config::SCOPE_USER, $uid, 'tiger.nav.' . $group . '.' . $key . '.sort', (string) $i);
                    $i++;
                }
            });
            $this->_success([]);
        } catch (Throwable $e) {
            $this->_error(APPLICATION_ENV !== 'production' ? $e->getMessage() : 'core.api.error.general');
        }
    }

    /** The current user id, or '' when unauthenticated. */
    protected function _userId()
    {
        $identity = Zend_Auth::getInstance()->getIdentity();
        return ($identity && !empty($identity->user_id)) ? (string) $identity->user_id : '';
    }
}
