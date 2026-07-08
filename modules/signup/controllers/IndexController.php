<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Signup_IndexController — the public signup screen + the email-verify link handler.
 *
 * Thin: `index` renders the form (saving is the /api call Signup_Service_Signup::create);
 * `verify` consumes the emailed link (Signup_Service_Signup::verifyEmail) and activates the
 * account. Renders in the public 'auth' layout. Guest-reachable (modules/signup/configs/acl.ini).
 */
class Signup_IndexController extends Tiger_Controller_Action
{
    public function init()
    {
        parent::init();
        $this->_helper->layout()->setLayout('auth');
    }

    public function indexAction()
    {
        if (Zend_Auth::getInstance()->hasIdentity()) {
            $this->_helper->redirector->gotoUrl('/admin');
            return;
        }
        $this->view->title    = 'Create your account';
        $this->view->authWide = true;   // multi-column form needs the wide card
        $this->view->form     = new Signup_Form_Signup();
    }

    /** GET /signup/verify/cid/<id>/code/<token> — activate the account, then send to sign-in. */
    public function verifyAction()
    {
        $result = (new Signup_Service_Signup())->verifyEmail(
            (string) $this->getParam('cid', ''),
            (string) $this->getParam('code', '')
        );
        $this->view->title = 'Email verification';
        $this->view->ok    = !empty($result['ok']);
    }
}
