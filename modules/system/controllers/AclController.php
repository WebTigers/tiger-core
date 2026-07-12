<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * System_AclController — the ACL Simulator screen ("why am I locked out?", ACL.md §7).
 *
 * Thin: it renders the tool; the role/resource catalog and each decision come from
 * `System_Service_Acl` over `/api`. See ADMIN.md.
 */
class System_AclController extends Tiger_Controller_Admin_Action
{
    public function init()
    {
        parent::init();
    }

    public function indexAction()
    {
        $this->view->title = 'ACL Simulator — Tiger Admin';
    }
}
