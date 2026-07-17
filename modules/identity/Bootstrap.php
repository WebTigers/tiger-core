<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Identity module bootstrap.
 *
 * First-party "Site Identity" surface — the admin screen that sets the site name, tagline, logo,
 * favicon, and social links, all stored in the config tier (live-override, no deploy). It is a
 * module (not core) so it carries its own ACL resource and Settings entry; the values it writes
 * are plain core config, consumed by the layout (favicon), TigerSEO (Organization logo + sameAs),
 * and anywhere `tiger.site.*` is read.
 *
 * Extending Zend_Application_Module_Bootstrap gives the module its resource autoloader, so
 * Identity_Service_* / Identity_Form_* / Identity_Plugin_* load by convention; controllers load via
 * the registered module dir; configs/acl.ini + languages/ are picked up by the core globs.
 */
class Identity_Bootstrap extends Zend_Application_Module_Bootstrap
{
    /** Contribute the favicon to the head (config-driven; fail-open). High stackIndex = after routing. */
    protected function _initFavicon()
    {
        Zend_Controller_Front::getInstance()->registerPlugin(new Identity_Plugin_Favicon(), 91);
    }

    /** List Site Identity under the admin Settings tree (ACL-gated to Identity_AdminController). */
    protected function _initAdminSettings()
    {
        Tiger_Admin_Settings::register([
            'key'      => 'identity',
            'label'    => 'Site Identity',
            'icon'     => 'fa-fingerprint',
            'href'     => '/identity/admin',
            'resource' => 'Identity_AdminController',
            'order'    => 10,
        ]);
    }
}
