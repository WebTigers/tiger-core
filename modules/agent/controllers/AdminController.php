<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Agent_AdminController — the TigerAgent settings screen (ADMIN.md template).
 *
 * Thin: it renders the shell and prefills the form from live config; the save is an /api call
 * to Agent_Service_Settings. The API key is never sent back to the browser — the field shows a
 * "connected" state instead (see the view).
 */
class Agent_AdminController extends Tiger_Controller_Admin_Action
{
    /** Base sets layout('admin'); keep the explicit cascade hook. */
    public function init()
    {
        parent::init();
    }

    /** Settings: provider, model, BYO key, and the on/off switch. */
    public function indexAction()
    {
        $form = new Agent_Form_Settings();
        $form->populate([
            'provider' => Tiger_Agent::provider(),
            'model'    => Tiger_Agent::model(),
        ]);

        $this->view->title        = Zend_Registry::get('Zend_Translate')->translate('agent.settings.title') . ' — Tiger Admin';
        $this->view->form         = $form;
        $this->view->enabled      = Tiger_Agent::isEnabled();
        $this->view->connected    = Tiger_Agent::isConnected();
        $this->view->providers    = Tiger_Agent_Provider_Factory::options();
        $this->view->provider     = Tiger_Agent::provider();
        $this->view->model        = Tiger_Agent::model();
        $this->view->modeMax      = Tiger_Agent::modeMax();
        $this->view->cryptoReady  = Tiger_Crypto::isConfigured();
    }
}
