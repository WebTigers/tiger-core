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
    }
}
