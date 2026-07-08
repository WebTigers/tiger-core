<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Cms module bootstrap.
 *
 * First-party CMS module — the AUTHORING surface for the platform's page content.
 * The engine (data + rendering) lives in the platform layer (Tiger_Model_Page,
 * Tiger_Cms_Renderer, the PageDispatch plugin, the public PageController); this
 * module is the admin UI on top of it: a DataTables content list + a page editor,
 * writing through the /api service Cms_Service_Page.
 *
 * Extending Zend_Application_Module_Bootstrap gives the module its resource
 * autoloader, so Cms_Service_* (services/) and Cms_Form_* (forms/) load by
 * convention. Controllers load via the registered module dir; the module's
 * configs/acl.ini and languages/ are picked up by the core globs.
 */
class Cms_Bootstrap extends Zend_Application_Module_Bootstrap
{
    /** Contribute the CMS page to the admin Settings tree (ACL-gated in the menu). */
    protected function _initAdminSettings()
    {
        Tiger_Admin_Settings::register([
            'key'      => 'cms',
            'label'    => 'CMS',
            'icon'     => 'fa-file-lines',
            'href'     => '/cms/settings',
            'resource' => 'Cms_SettingsController',
            'order'    => 10,
        ]);
    }
}
