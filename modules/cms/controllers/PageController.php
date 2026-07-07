<?php
/**
 * Cms_PageController — the CMS authoring surface, rendered in the PUMA admin shell.
 *
 * Two thin screens: a DataTables list of all content (index) and the page editor
 * (edit). Per the thin-controller rule this class only READS and RENDERS — every
 * mutation goes through the /api service Cms_Service_Page (save/delete/restore).
 * ACL-gated to admin+ (modules/cms/configs/acl.ini) by the unbypassable
 * Authorization plugin; a guest is bounced to login, a non-admin gets a themed 403.
 */
class Cms_PageController extends Tiger_Controller_Action
{
    /** @var Tiger_Model_Page */
    protected $_pages;

    /** Every action renders inside the admin layout. */
    public function init()
    {
        parent::init();
        $this->_helper->layout()->setLayout('admin');
        $this->_pages = new Tiger_Model_Page();
    }

    /**
     * The content list — just the shell. The rows load over AJAX from the /api
     * service (Cms_Service_Page::datatable), per the client/server paradigm: the
     * server renders the page, the browser fetches the data as a Tiger Webservice.
     */
    public function indexAction()
    {
        $this->view->title         = 'Content — Tiger Admin';
        $this->view->useDataTables = true;   // the layout loads jQuery + DataTables when set
    }

    /** Create (no id) or edit (id) — renders the form; saving is an /api call, not a post-back. */
    public function editAction()
    {
        $id   = (string) $this->getParam('id', '');
        $page = $id !== '' ? $this->_pages->findById($id) : null;

        $form = new Cms_Form_Page();
        if ($page) {
            $form->populate($this->_editValues($page));
        }

        $this->view->title    = ($page ? 'Edit' : 'New') . ' Page — Tiger Admin';
        $this->view->form     = $form;
        $this->view->page     = $page;
        $this->view->versions = $page
            ? (new Tiger_Model_PageVersion())->recentForPage($id)
            : [];
    }

    /** Map a page row to editor form values. */
    protected function _editValues($page)
    {
        return [
            'page_id'      => $page->page_id,
            'title'        => $page->title,
            'slug'         => $page->slug,
            'page_key'     => $page->page_key,
            'type'         => $page->type,
            'format'       => $page->format,
            'status'       => $page->status,
            'locale'       => $page->locale,
            'layout_key'   => $page->layout_key,
            'published_at' => $page->published_at,
            'body'         => $page->body,
        ];
    }
}
