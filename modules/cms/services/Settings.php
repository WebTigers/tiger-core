<?php
/**
 * Cms_Service_Settings — /api service for the site/CMS Settings screen.
 *
 * Validates Cms_Form_Settings, then writes the values to the DB `config` table
 * (scope=global) via Tiger_Model_Config — the live-override tier of the config
 * cascade, so a change takes effect on the next request with no deploy. Storage is
 * the config table; there is no separate settings/option table (config-discipline).
 * ACL: admin+ (modules/cms/configs/acl.ini).
 */
class Cms_Service_Settings extends Tiger_Service_Service
{
    public function save(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }

        $form = new Cms_Form_Settings();
        if (!$form->isValid($params)) { $this->_formErrors($form); return; }
        $v = $form->getValues();

        try {
            $cfg = new Tiger_Model_Config();
            $g   = Tiger_Model_Config::SCOPE_GLOBAL;
            $cfg->set($g, '', 'tiger.site.name', trim((string) $v['site_name']));
            $cfg->set($g, '', 'tiger.site.home_page', (string) $v['home_page']);

            // Session & security. Max session timeout overrides the standard-user idle TTL;
            // auto-logout is the proactive inactivity feature (toggle + window + action).
            $cfg->set($g, '', 'tiger.session.ttl.authed', (string) max(60, (int) $v['session_ttl']));
            $cfg->set($g, '', 'tiger.session.autologout.enabled', !empty($v['autologout_enabled']) ? '1' : '0');
            $cfg->set($g, '', 'tiger.session.autologout.seconds', (string) max(30, (int) $v['autologout_seconds']));
            $cfg->set($g, '', 'tiger.session.autologout.action', $v['autologout_action'] === 'lock' ? 'lock' : 'logout');

            $this->_success([], 'cms.settings.saved', '/cms/settings');
        } catch (Throwable $e) {
            $this->_error(APPLICATION_ENV !== 'production' ? $e->getMessage() : 'core.api.error.general');
        }
    }
}
