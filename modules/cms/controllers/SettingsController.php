<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Cms_SettingsController — the site/CMS Settings screen (in the admin shell).
 *
 * Thin: renders the settings form pre-filled from the live config; saving is an /api
 * call (Cms_Service_Settings). ACL-gated admin+ (modules/cms/configs/acl.ini).
 */
class Cms_SettingsController extends Tiger_Controller_Action
{
    public function init()
    {
        parent::init();
        $this->_helper->layout()->setLayout('admin');
    }

    public function indexAction()
    {
        $cfg   = Zend_Registry::get('Zend_Config');
        $tiger = $cfg->get('tiger');
        $site  = $tiger ? $tiger->get('site') : null;

        $form = new Cms_Form_Settings();
        $form->populate([
            'site_name' => ($site && (string) $site->get('name') !== '') ? (string) $site->name : 'Tiger',
            'home_page' => $site ? (string) $site->get('home_page') : '',
        ]);

        $this->view->title = 'Settings — Tiger Admin';
        $this->view->form  = $form;
    }
}
