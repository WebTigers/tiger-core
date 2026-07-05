<?php
/**
 * AdminController — the default-namespace admin dashboard (MIT, Tiger-original).
 *
 * Proves the PUMA admin shell end-to-end: it opts into the 'admin' layout and
 * renders a dashboard. ACL-gated to admin and above (see core/configs/acl.ini) by
 * Tiger_Controller_Plugin_Authorization — a guest hitting /admin is bounced to
 * login, a logged-in non-admin gets 403.
 *
 * Apps typically build their own admin as a MODULE; this ships so a fresh install
 * has a working, themed back office out of the box.
 */
class AdminController extends Tiger_Controller_Action
{
    /** Every action in this controller renders inside the admin shell. */
    public function init()
    {
        parent::init();
        $this->_helper->layout()->setLayout('admin');
    }

    /** The dashboard. Demo tiles now; a real app wires these to its own models. */
    public function indexAction()
    {
        $this->view->title = 'Dashboard — Tiger Admin';

        // Live counts where the substrate exists; graceful zeros otherwise.
        try {
            $this->view->orgCount  = (new Tiger_Model_Org())->activeSelect()->query()->rowCount();
            $this->view->userCount = (new Tiger_Model_User())->activeSelect()->query()->rowCount();
        } catch (Throwable $e) {
            $this->view->orgCount  = 0;
            $this->view->userCount = 0;
        }
    }
}
