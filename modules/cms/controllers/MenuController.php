<?php
/**
 * Cms_MenuController — the CMS Menus admin (admin shell). Two thin screens:
 * a DataTables list of menus (index) and the item editor for one menu (edit).
 *
 * Thin by rule: this reads + renders only; every mutation goes through the /api
 * service Cms_Service_Menu (save/delete/deleteMenu/reorder). ACL-gated admin+ via
 * modules/cms/configs/acl.ini. The drag-drop reordering UI builds on the tree this
 * renders (each item carries data-menu-id for the sortable to persist via reorder).
 */
class Cms_MenuController extends Tiger_Controller_Action
{
    public function init()
    {
        parent::init();
        $this->_helper->layout()->setLayout('admin');
    }

    /** The menus list — shell only; rows load over AJAX (Cms_Service_Menu::datatable). */
    public function indexAction()
    {
        $this->view->title         = 'Menus — Tiger Admin';
        $this->view->useDataTables = true;
    }

    /** Edit one menu (by key), or start a new one (no key). Renders the item tree + editor. */
    public function editAction()
    {
        $key   = (string) $this->getParam('key', '');
        $orgId = '';   // global scope for now (per-tenant menu editing is a later concern)

        $model = new Tiger_Model_Menu();
        $this->view->menuKey = $key;
        $this->view->orgId   = $orgId;
        $this->view->tree    = ($key !== '') ? $model->tree($key, $orgId, false) : [];
        $this->view->form    = new Cms_Form_MenuItem();
        $this->view->pages   = $this->_pagePalette();
        $this->view->title   = ($key !== '' ? 'Edit Menu' : 'New Menu') . ' — Tiger Admin';
    }

    /** Published pages with a stable key — the "Pages" source column for the builder. */
    protected function _pagePalette()
    {
        $pm   = new Tiger_Model_Page();
        $rows = $pm->fetchAll(
            $pm->activeSelect()
               ->where('type = ?', Tiger_Model_Page::TYPE_PAGE)
               ->where('page_key IS NOT NULL')
               ->order(['title ASC', 'page_key ASC'])
        );
        $out = [];
        foreach ($rows as $p) {
            if ((string) $p->page_key === '') { continue; }
            $out[] = ['page_key' => (string) $p->page_key, 'title' => (string) ($p->title ?: $p->slug ?: $p->page_key)];
        }
        return $out;
    }
}
