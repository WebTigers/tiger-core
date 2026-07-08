<?php
/**
 * Code_IndexController — the Tiger Code authoring surface, in the PUMA admin shell.
 *
 * A DataTables list (index) + the snippet editor (edit) on the CodeMirror component. Thin:
 * it only READS/RENDERS — every mutation goes through Code_Service_Code. Gated to
 * `superadmin`+ (configs/acl.ini): this screen writes server-executed code.
 */
class Code_IndexController extends Tiger_Controller_Action
{
    /** @var Tiger_Model_Code */
    protected $_code;

    public function init()
    {
        parent::init();
        $this->_helper->layout()->setLayout('admin');
        $this->_code = new Tiger_Model_Code();
    }

    public function indexAction()
    {
        $this->view->title         = 'Code — Tiger Admin';
        $this->view->useDataTables = true;
    }

    public function editAction()
    {
        $id  = (string) $this->getParam('id', '');
        $row = $id !== '' ? $this->_code->findById($id) : null;

        $form = new Code_Form_Code();
        if ($row) {
            $form->populate([
                'code_id'     => $row->code_id,
                'name'        => $row->name,
                'description' => $row->description,
                'priority'    => $row->priority,
                'active'      => (int) $row->active === 1,
                'code'        => $row->code,
            ]);
        }

        $this->view->title    = ($row ? 'Edit' : 'New') . ' Snippet — Tiger Admin';
        $this->view->form     = $form;
        $this->view->row      = $row;
        $this->view->versions = $row ? (new Tiger_Model_CodeVersion())->recentFor($id) : [];
    }
}
