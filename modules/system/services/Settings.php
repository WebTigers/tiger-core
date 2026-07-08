<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * System_Service_Settings — /api service for the System settings screen.
 *
 * Validates System_Form_Settings, then writes to the DB `config` table (scope=global)
 * via Tiger_Model_Config — the live-override tier, effective next request, no deploy.
 * Config-discipline: the config store, not a settings table. ACL: admin+
 * (modules/system/configs/acl.ini).
 */
class System_Service_Settings extends Tiger_Service_Service
{
    public function save(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }

        $form = new System_Form_Settings();
        if (!$form->isValid($params)) { $this->_formErrors($form); return; }
        $v = $form->getValues();

        try {
            $cfg = new Tiger_Model_Config();
            $g   = Tiger_Model_Config::SCOPE_GLOBAL;

            // Max session timeout overrides the standard-user idle TTL; auto-logout is the
            // proactive inactivity feature (toggle + window + action).
            $cfg->set($g, '', 'tiger.session.ttl.authed', (string) max(60, (int) $v['session_ttl']));
            $cfg->set($g, '', 'tiger.session.autologout.enabled', !empty($v['autologout_enabled']) ? '1' : '0');
            $cfg->set($g, '', 'tiger.session.autologout.seconds', (string) max(30, (int) $v['autologout_seconds']));
            $cfg->set($g, '', 'tiger.session.autologout.action', $v['autologout_action'] === 'lock' ? 'lock' : 'logout');

            $this->_success([], 'system.settings.saved', '/system/settings');
        } catch (Throwable $e) {
            $this->_error(APPLICATION_ENV !== 'production' ? $e->getMessage() : 'core.api.error.general');
        }
    }
}
