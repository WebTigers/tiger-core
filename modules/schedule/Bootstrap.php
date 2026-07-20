<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Scheduler module bootstrap — the admin UI over the core Tiger_Schedule engine (the engine itself
 * runs from the core bootstrap, so scheduling works even if this UI module is off).
 */
class Schedule_Bootstrap extends Zend_Application_Module_Bootstrap
{
    /** Top-level "Scheduler" sidebar item (ACL-gated to admin+). */
    protected function _initAdminNav()
    {
        Tiger_Admin_Nav::register([
            'key'      => 'schedule',
            'label'    => 'Scheduler',
            'icon'     => 'fa-clock',
            'href'     => '/schedule',
            'resource' => 'Schedule_IndexController',
            'order'    => 19,
        ]);
    }
}
