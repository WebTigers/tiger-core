<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Analytics_AdminController — the Google Analytics settings screen. Thin per ADMIN.md: renders the
 * form pre-filled from the live config; saving is an /api call (Analytics_Service_Analytics). Its own
 * ACL resource (admin+), so access is grantable independently.
 */
class Analytics_AdminController extends Tiger_Controller_Admin_Action
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
     * Render the settings form, pre-filled from the live config.
     *
     * @return void
     */
    public function indexAction()
    {
        $tiger = Zend_Registry::get('Zend_Config')->get('tiger');
        $a     = $tiger ? $tiger->get('analytics') : null;
        $ga4   = $a ? $a->get('ga4') : null;

        $form = new Analytics_Form_Settings();
        $form->populate([
            'ga4_measurement_id' => $ga4 ? (string) $ga4->get('measurement_id') : '',
        ]);

        $b = static function ($node, $key, $default = false) {
            return $node && $node->get($key) !== null
                ? filter_var((string) $node->get($key), FILTER_VALIDATE_BOOLEAN)
                : $default;
        };

        $this->view->title           = 'Analytics — Tiger Admin';
        $this->view->form            = $form;
        $this->view->enabled         = $b($a, 'enabled', false);
        $this->view->excludeSignedIn = $b($a, 'exclude_signed_in', true);

        // Reporting/OAuth connection state for the "Connect Google" section.
        $this->view->ga = [
            'connected'    => Tiger_Google_Analytics::isConnected(),
            'configurable' => Tiger_Google_Analytics::isConfigurable(),
            'client_id'    => Tiger_Google_Analytics::clientId(),
            'property_id'  => Tiger_Google_Analytics::propertyId(),
            'redirect_uri' => $this->_redirectUri(),
        ];
        $this->view->flash = $this->_takeFlash();
    }

    /**
     * `/analytics/admin/connect` — kick off the Google OAuth consent flow (offline access).
     *
     * @return void
     */
    public function connectAction()
    {
        $this->_helper->layout()->disableLayout();
        $this->_helper->viewRenderer->setNoRender(true);
        if (!Tiger_Google_Analytics::isConfigurable()) {
            $this->_flash('Enter your OAuth client ID, secret, and GA4 property ID first, then Save.', 'error');
            $this->_redirect('/analytics/admin');
            return;
        }
        $state = bin2hex(random_bytes(16));
        $ns = new Zend_Session_Namespace('AnalyticsOauth');
        $ns->state = $state;
        $this->_redirect(Tiger_Google_Analytics::authUrl($this->_redirectUri(), $state));
    }

    /**
     * `/analytics/admin/callback` — Google redirects here with ?code & ?state; exchange for tokens.
     *
     * @return void
     */
    public function callbackAction()
    {
        $this->_helper->layout()->disableLayout();
        $this->_helper->viewRenderer->setNoRender(true);
        $req = $this->getRequest();
        $ns  = new Zend_Session_Namespace('AnalyticsOauth');
        $expected = (string) ($ns->state ?? '');
        unset($ns->state);

        if ((string) $req->getParam('error') !== '') {
            $this->_flash('Google authorization was cancelled or denied.', 'error');
        } elseif ($expected === '' || !hash_equals($expected, (string) $req->getParam('state'))) {
            $this->_flash('The connection request expired or did not match. Please try again.', 'error');
        } else {
            $res = Tiger_Google_Analytics::exchangeCode((string) $req->getParam('code'), $this->_redirectUri());
            $this->_flash($res['ok'] ? 'Connected to Google Analytics.' : ('Could not connect: ' . $res['error']), $res['ok'] ? 'success' : 'error');
        }
        $this->_redirect('/analytics/admin');
    }

    /**
     * `/analytics/admin/disconnect` — forget the stored Google connection (drop the refresh token).
     *
     * @return void
     */
    public function disconnectAction()
    {
        $this->_helper->layout()->disableLayout();
        $this->_helper->viewRenderer->setNoRender(true);
        Tiger_Google_Analytics::disconnect();
        $this->_flash('Disconnected from Google Analytics.', 'success');
        $this->_redirect('/analytics/admin');
    }

    /** The OAuth redirect URI (must match the one registered in the Google OAuth client). */
    private function _redirectUri()
    {
        $req = $this->getRequest();
        return $req->getScheme() . '://' . $req->getHttpHost() . '/analytics/admin/callback';
    }

    /** Stash a one-shot flash message (survives the OAuth redirect) in the session. */
    private function _flash($message, $type)
    {
        $ns = new Zend_Session_Namespace('AnalyticsFlash');
        $ns->message = (string) $message;
        $ns->type    = (string) $type;
    }

    /** Read + clear the flash message, or null. */
    private function _takeFlash()
    {
        $ns = new Zend_Session_Namespace('AnalyticsFlash');
        if (empty($ns->message)) { return null; }
        $flash = ['message' => (string) $ns->message, 'type' => (string) ($ns->type ?: 'info')];
        unset($ns->message, $ns->type);
        return $flash;
    }
}
