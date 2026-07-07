<?php
/**
 * Access_OrgController — the Organizations admin (tenants), in the PUMA admin shell.
 *
 * Thin: index renders the DataTables shell (rows load from Access_Service_Org::
 * datatable), edit renders the org form (saving is an /api call). ACL-gated to
 * admin+ (modules/access/configs/acl.ini). An org is a tenant with a self-referential
 * parent (org hierarchies); membership (who belongs, and their role) is managed
 * separately.
 */
class Access_OrgController extends Tiger_Controller_Action
{
    public function init()
    {
        parent::init();
        $this->_helper->layout()->setLayout('admin');
    }

    /** Orgs list — shell only; rows arrive over AJAX. */
    public function indexAction()
    {
        $this->view->title         = 'Organizations — Tiger Admin';
        $this->view->useDataTables = true;
    }

    /** Create (no id) or edit (id) an org — renders the form; saving is an /api call. */
    public function editAction()
    {
        $id  = (string) $this->getParam('id', '');
        $org = $id !== '' ? (new Tiger_Model_Org())->findById($id) : null;

        $form = new Access_Form_Org($id);   // exclude self from the parent options
        if ($org) {
            $form->populate([
                'org_id'        => $org->org_id,
                'name'          => $org->name,
                'slug'          => $org->slug,
                'parent_org_id' => $org->parent_org_id,
                'status'        => $org->status,
            ]);
        }

        $this->view->title = ($org ? 'Edit' : 'New') . ' Organization — Tiger Admin';
        $this->view->form  = $form;
        $this->view->org   = $org;
    }
}
