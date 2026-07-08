<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * System module bootstrap — platform administration (the Module manager, for now).
 *
 * First-party, always-on module (it manages the OTHER modules' activation, so it's in the
 * protected set and can never be deactivated). Auto-discovered like any module.
 */
class System_Bootstrap extends Zend_Application_Module_Bootstrap
{
    /** Contribute the System page to the admin Settings tree (ACL-gated in the menu). */
    protected function _initAdminSettings()
    {
        Tiger_Admin_Settings::register([
            'key'      => 'system',
            'label'    => 'System',
            'icon'     => 'fa-server',
            'href'     => '/system/settings',
            'resource' => 'System_SettingsController',
            'order'    => 20,
        ]);
    }
}
