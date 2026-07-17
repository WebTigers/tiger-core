<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Identity_Service_Identity — the /api service behind the Site Identity screen. Validates the
 * form, then writes the identity to the config tier (the live-override store, no deploy):
 * the site name/tagline, the logo + favicon media references, and the social profile URLs
 * that feed Organization.sameAs in the JSON-LD.
 *
 * Scope: writes at GLOBAL scope for now — the single site's identity, visible to guests on
 * public pages (anonymous requests only receive GLOBAL config, by design; core stays
 * multi-site-unaware). The scope lives in one place (`_scope()`) so the future multi-site
 * module, which resolves the acting org per domain and loads that org's config for guests,
 * can flip identity to per-org scoping without touching the rest of this service.
 *
 * @api
 */
class Identity_Service_Identity extends Tiger_Service_Service
{
    /** The config keys this screen owns — form field name => dot-notation config key. */
    const KEYS = [
        'site_name'        => 'tiger.site.name',
        'tagline'          => 'tiger.site.tagline',
        'social_twitter'   => 'tiger.seo.social.twitter',
        'social_facebook'  => 'tiger.seo.social.facebook',
        'social_instagram' => 'tiger.seo.social.instagram',
        'social_linkedin'  => 'tiger.seo.social.linkedin',
        'social_youtube'   => 'tiger.seo.social.youtube',
        'social_github'    => 'tiger.seo.social.github',
    ];

    /**
     * Save the site identity: validate, then persist every field to the config store.
     *
     * @param  array $params the posted form values (+ logo_media_id / favicon_media_id)
     * @return void
     */
    public function save(array $params): void
    {
        if (!$this->_isAdmin()) {
            $this->_error('core.api.error.not_allowed');
            return;
        }

        $form = new Identity_Form_Identity();
        if (!$form->isValid($params)) {
            $this->_formErrors($form);
            return;
        }
        $v = $form->getValues();

        // Media references ride outside the form (the picker owns their hidden inputs); accept a
        // media_id (36-char UUID) or empty to clear. Anything else is ignored — never trusted raw.
        $logo    = self::_mediaId($params['logo_media_id']    ?? '');
        $favicon = self::_mediaId($params['favicon_media_id'] ?? '');

        try {
            $this->_transaction(function () use ($v, $logo, $favicon) {
                $cfg   = new Tiger_Model_Config();
                [$scope, $scopeId] = $this->_scope();
                foreach (self::KEYS as $field => $key) {
                    $cfg->set($scope, $scopeId, $key, trim((string) ($v[$field] ?? '')));
                }
                $cfg->set($scope, $scopeId, 'tiger.site.logo',    $logo);
                $cfg->set($scope, $scopeId, 'tiger.site.favicon', $favicon);
            });
            $this->_success([], 'identity.saved');
        } catch (Throwable $e) {
            $this->_error(APPLICATION_ENV !== 'production' ? $e->getMessage() : 'core.api.error.general');
        }
    }

    /**
     * The config scope for identity writes. GLOBAL today (the single site, guest-visible). The
     * multi-site module overrides this to (SCOPE_ORG, <domain's org>).
     *
     * @return array [scope, scopeId]
     */
    protected function _scope()
    {
        return [Tiger_Model_Config::SCOPE_GLOBAL, ''];
    }

    /** A value that looks like a media UUID, else '' (clears the reference). */
    private static function _mediaId($v)
    {
        $v = trim((string) $v);
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v) ? $v : '';
    }
}
