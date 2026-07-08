<?php
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
        $cfg     = Zend_Registry::get('Zend_Config');
        $tiger   = $cfg->get('tiger');
        $site    = $tiger ? $tiger->get('site') : null;
        $session = $tiger ? $tiger->get('session') : null;

        $ttlAuthed = 604800;   // 7d default (matches Tiger_Session_SaveHandler_DbTable)
        if ($session && $session->get('ttl') && (int) $session->ttl->get('authed') > 0) {
            $ttlAuthed = (int) $session->ttl->authed;
        }
        $al = (new Tiger_Service_Authentication())->autologoutConfig();

        $form = new Cms_Form_Settings();
        $form->populate([
            'site_name'          => ($site && (string) $site->get('name') !== '') ? (string) $site->name : 'Tiger',
            'home_page'          => $site ? (string) $site->get('home_page') : '',
            'session_ttl'        => $ttlAuthed,
            'autologout_enabled' => $al['enabled'] ? 1 : 0,
            'autologout_seconds' => $al['seconds'],
            'autologout_action'  => $al['action'],
        ]);

        $this->view->title = 'Settings — Tiger Admin';
        $this->view->form  = $form;
    }
}
