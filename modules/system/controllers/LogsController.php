<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * System_LogsController — the application Logs viewer ("what just broke?").
 *
 * Thin: it renders the screen; entries come from System_Service_Logs over /api (tail + filter of the
 * file/stream sink). Read-only, superadmin+. See ADMIN.md.
 */
class System_LogsController extends Tiger_Controller_Admin_Action
{
    public function init()
    {
        parent::init();
    }

    public function indexAction()
    {
        $this->view->title = 'Logs — Tiger Admin';
    }
}
