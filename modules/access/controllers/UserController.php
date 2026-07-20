<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Access_UserController — the Users admin (identity), in the PUMA admin shell.
 *
 * Thin: index renders the DataTables shell (rows load from Access_Service_User::
 * datatable), edit renders the identity form (saving is an /api call). ACL-gated to
 * admin+ (modules/access/configs/acl.ini). Users are deliberately thin identities
 * (email / username / status); profile fields live in an Account module, and role is
 * per-membership (managed in the org context), not here.
 */
class Access_UserController extends Tiger_Controller_Admin_Action
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
     * Users list — shell only; rows arrive over AJAX.
     *
     * @return void
     */
    public function indexAction()
    {
        $this->view->title         = 'Users — Tiger Admin';
        $this->view->useDataTables = true;
    }

    /**
     * Create (no id) or edit (id) a user — renders the form; saving is an /api call.
     *
     * @return void
     */
    public function editAction()
    {
        $id   = (string) $this->getParam('id', '');
        $user = $id !== '' ? (new Tiger_Model_User())->findById($id) : null;

        $form = new Access_Form_User();
        if ($user) {
            $form->populate([
                'user_id'  => $user->user_id,
                'email'    => $user->email,
                'username' => $user->username,
                'locale'   => (string) $user->locale,
                'timezone' => (string) $user->timezone,
                'status'   => $user->status,
            ]);
        }

        $this->view->title = ($user ? 'Edit' : 'New') . ' User — Tiger Admin';
        $this->view->form  = $form;
        $this->view->user  = $user;
    }
}
