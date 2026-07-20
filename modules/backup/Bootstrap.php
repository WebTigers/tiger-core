<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Backup module bootstrap — the admin UI over the core Tiger_Backup engine. The scheduled-backup job
 * is registered here (disabled until an admin turns it on via the embedded schedule control), so
 * nothing is scheduled by merely installing the module. Registered in code, not schedule.ini, because
 * the job key is dotted (`backup.run`) and .ini dot-notation would nest it instead of keying on it.
 */
class Backup_Bootstrap extends Zend_Application_Module_Bootstrap
{
    /** The scheduled-backup job (OFF by default; runs Tiger_Backup::runScheduled when enabled + due). */
    protected function _initScheduleJob()
    {
        if (!class_exists('Tiger_Schedule')) { return; }
        Tiger_Schedule::register([
            'key'     => 'backup.run',
            'label'   => 'Scheduled site backup',
            'run'     => 'Tiger_Backup::runScheduled',
            'every'   => Tiger_Schedule::DAILY,
            'at'      => '02:00',
            'enabled' => false,   // an admin enables it from the Backup screen's schedule control
        ]);
    }

    /** Top-level "Backup" sidebar item (ACL-gated to admin+). */
    protected function _initAdminNav()
    {
        Tiger_Admin_Nav::register([
            'key'      => 'backup',
            'label'    => 'Backup',
            'icon'     => 'fa-box-archive',
            'href'     => '/backup',
            'resource' => 'Backup_IndexController',
            'order'    => 18,
        ]);
    }
}
