<?php
/**
 * Access_UserController — the Users admin (identity), in the PUMA admin shell.
 *
 * Thin: index renders the DataTables shell (rows load from Access_Service_User::
 * datatable), edit renders the identity form (saving is an /api call). ACL-gated to
 * admin+ (modules/access/configs/acl.ini). Users are deliberately thin identities
 * (email / username / status); profile fields live in an Account module, and role is
 * per-membership (managed in the org context), not here.
 */
class Access_UserController extends Tiger_Controller_Action
{
    public function init()
    {
        parent::init();
        $this->_helper->layout()->setLayout('admin');
    }

    /** Users list — shell only; rows arrive over AJAX. */
    public function indexAction()
    {
        $this->view->title         = 'Users — Tiger Admin';
        $this->view->useDataTables = true;
    }

    /** Create (no id) or edit (id) a user — renders the form; saving is an /api call. */
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
                'status'   => $user->status,
            ]);
        }

        $this->view->title = ($user ? 'Edit' : 'New') . ' User — Tiger Admin';
        $this->view->form  = $form;
        $this->view->user  = $user;
    }
}
