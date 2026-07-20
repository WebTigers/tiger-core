<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Profile module bootstrap.
 *
 * The BASE self-service profile surface — a person edits their own account (/profile) and an org
 * admin edits their org (/profile/org). Deliberately unopinionated: Tiger ships the base edit
 * surfaces (basic info, contacts, addresses) and lets the product-builder decide what an "account"
 * means. The tab set is an extensible registry (Tiger_Profile_Tabs), so a later module — billing,
 * say — adds its own tab without editing this one.
 *
 * Resource autoloader (from Zend_Application_Module_Bootstrap) loads Profile_Service_* /
 * Profile_Form_*; controllers load via the registered module dir; configs/acl.ini + languages/ are
 * picked up by the core globs.
 */
class Profile_Bootstrap extends Zend_Application_Module_Bootstrap
{
    /**
     * Register the base profile tabs. Core ships Basic / Contacts / Addresses; a module extends the
     * screen by calling Tiger_Profile_Tabs::register() from its own Bootstrap.
     *
     * @return void
     */
    protected function _initProfileTabs()
    {
        Tiger_Profile_Tabs::register(Tiger_Profile_Tabs::CONTEXT_USER, [
            'key' => 'basic', 'label' => 'profile.tab.basic', 'icon' => 'fa-id-card', 'order' => 10, 'view' => 'index/_basic',
        ]);
        Tiger_Profile_Tabs::register(Tiger_Profile_Tabs::CONTEXT_USER, [
            'key' => 'security', 'label' => 'profile.tab.security', 'icon' => 'fa-key', 'order' => 15, 'view' => 'index/_security',
        ]);
        Tiger_Profile_Tabs::register(Tiger_Profile_Tabs::CONTEXT_USER, [
            'key' => 'contacts', 'label' => 'profile.tab.contacts', 'icon' => 'fa-address-book', 'order' => 20, 'view' => 'index/_contacts',
        ]);
        Tiger_Profile_Tabs::register(Tiger_Profile_Tabs::CONTEXT_USER, [
            'key' => 'addresses', 'label' => 'profile.tab.addresses', 'icon' => 'fa-location-dot', 'order' => 30, 'view' => 'index/_addresses',
        ]);
    }
}
