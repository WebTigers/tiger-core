<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Identity_AdminController — the Site Identity screen (site name, tagline, logo, favicon, social
 * links). Its own controller (and ACL resource), so access is grantable independently of the rest
 * of the admin — the seam that lets a multi-tenant install hand each org's admin the keys to its
 * own site identity. Thin per ADMIN.md: it renders the form pre-filled from the live config; the
 * save is an /api call (Identity_Service_Identity).
 */
class Identity_AdminController extends Tiger_Controller_Admin_Action
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

    /**
     * Render the Site Identity form, pre-filled from the live config. The two media references
     * (logo, favicon) are passed to the view separately — the picker field renders their inputs.
     *
     * @return void
     */
    public function indexAction()
    {
        $tiger  = Zend_Registry::get('Zend_Config')->get('tiger');
        $site   = $tiger ? $tiger->get('site') : null;
        $social = ($tiger && $tiger->get('seo')) ? $tiger->get('seo')->get('social') : null;

        $g = static function ($node, $key, $default = '') {
            return ($node && (string) $node->get($key) !== '') ? (string) $node->get($key) : $default;
        };

        $form = new Identity_Form_Identity();
        $form->populate([
            'site_name'        => $g($site, 'name', 'Tiger'),
            'tagline'          => $g($site, 'tagline'),
            'social_twitter'   => $g($social, 'twitter'),
            'social_facebook'  => $g($social, 'facebook'),
            'social_instagram' => $g($social, 'instagram'),
            'social_linkedin'  => $g($social, 'linkedin'),
            'social_youtube'   => $g($social, 'youtube'),
            'social_github'    => $g($social, 'github'),
        ]);

        $this->view->title    = 'Site Identity — Tiger Admin';
        $this->view->form     = $form;
        $this->view->logoId   = $g($site, 'logo');
        $this->view->faviconId = $g($site, 'favicon');
    }
}
