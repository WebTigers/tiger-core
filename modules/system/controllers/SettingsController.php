<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * System_SettingsController — the System settings screen (in the admin shell).
 *
 * Thin: renders the form pre-filled from live config; saving is an /api call
 * (System_Service_Settings). ACL-gated admin+ (modules/system/configs/acl.ini).
 * Reached from the sidebar's Settings › System (registered via Tiger_Admin_Settings).
 */
class System_SettingsController extends Tiger_Controller_Action
{
    public function init()
    {
        parent::init();
        $this->_helper->layout()->setLayout('admin');
    }

    public function indexAction()
    {
        $cfg     = Zend_Registry::get('Zend_Config');
        $tiger   = $cfg->get('tiger');
        $session = $tiger ? $tiger->get('session') : null;

        $ttlAuthed = 604800;   // 7d default (matches Tiger_Session_SaveHandler_DbTable)
        if ($session && $session->get('ttl') && (int) $session->ttl->get('authed') > 0) {
            $ttlAuthed = (int) $session->ttl->authed;
        }
        $al = (new Tiger_Service_Authentication())->autologoutConfig();

        $form = new System_Form_Settings();
        $form->populate([
            'session_ttl'        => $ttlAuthed,
            'autologout_enabled' => $al['enabled'] ? 1 : 0,
            'autologout_seconds' => $al['seconds'],
            'autologout_action'  => $al['action'],
        ]);

        $this->view->title = 'System Settings — Tiger Admin';
        $this->view->form  = $form;
    }
}
