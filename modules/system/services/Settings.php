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
 *
 * @api
 */
class System_Service_Settings extends Tiger_Service_Service
{
    /**
     * Validate the settings form and write session + auto-logout values to the config table.
     *
     * @param  array $params the settings form payload
     * @return void
     */
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

            // reCAPTCHA tab — shared writer (encrypts the secret; blank secret keeps the current one).
            Tiger_Recaptcha::saveSettings([
                'enabled'    => !empty($v['recaptcha_enabled']) ? 1 : 0,
                'version'    => $v['recaptcha_version'],
                'site_key'   => $v['recaptcha_site_key'],
                'secret_key' => $v['recaptcha_secret_key'],
                'min_score'  => $v['recaptcha_min_score'] === '' ? 0.5 : $v['recaptcha_min_score'],
                'fail_open'  => !empty($v['recaptcha_fail_open']) ? 1 : 0,
                'hide_badge' => !empty($v['recaptcha_hide_badge']) ? 1 : 0,
            ]);

            // Location tab — provider selection + each adapter's own fields (secrets encrypted). The form
            // fields are dynamic per adapter, so they ride on $params directly (not the static form).
            if (class_exists('Tiger_Location') && method_exists('Tiger_Location', 'saveSettings')
                && array_key_exists('location_ip_provider', $params)) {
                Tiger_Location::saveSettings([
                    'ip_provider'      => $params['location_ip_provider'] ?? null,
                    'address_provider' => $params['location_address_provider'] ?? null,
                    'cache_ttl'        => $params['location_cache_ttl'] ?? null,
                    'adapters'         => (isset($params['location_adapter']) && is_array($params['location_adapter'])) ? $params['location_adapter'] : [],
                ]);
            }

            // Cookies tab — GDPR consent mode + banner copy (shared writer; rides on $params).
            if (class_exists('Tiger_Consent') && array_key_exists('consent_mode', $params)) {
                Tiger_Consent::saveSettings([
                    'mode'         => $params['consent_mode']         ?? null,
                    'message'      => $params['consent_message']      ?? null,
                    'accept_label' => $params['consent_accept_label'] ?? null,
                    'reject_label' => $params['consent_reject_label'] ?? null,
                    'policy_url'   => $params['consent_policy_url']   ?? null,
                    'ccpa_notice'  => $params['consent_ccpa_notice']  ?? null,
                    'honor_gpc'    => !empty($params['consent_honor_gpc']),   // unchecked box = absent = off
                ]);
            }

            $this->_success([], 'system.settings.saved', '/system/settings');
        } catch (Throwable $e) {
            $this->_error(APPLICATION_ENV !== 'production' ? $e->getMessage() : 'core.api.error.general');
        }
    }

    /**
     * Live IP-geolocation test for the Location tab's "Test" button. Uses the selected provider with the
     * form's current field values (so a just-typed key is testable without saving). Defaults to the
     * caller's own IP. Returns the lookup result as data (ok + country/city/label, or an error message).
     *
     * @param  array $params ip?, provider, config[field]
     * @return void
     */
    public function locationTest(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }
        if (!class_exists('Tiger_Location') || !method_exists('Tiger_Location', 'test')) {
            $this->_error('core.api.error.general'); return;
        }
        $ip = trim((string) ($params['ip'] ?? ''));
        if ($ip === '') { $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? ''); }
        $provider = (string) ($params['provider'] ?? '');
        $config   = (isset($params['config']) && is_array($params['config'])) ? $params['config'] : [];

        $this->_success(Tiger_Location::test($ip, $provider, $config));
    }
}
