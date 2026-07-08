<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Media_IndexController — the Media Library screen (admin shell). Thin: renders the
 * shell (a drag-drop uploader + a DataTables grid); all data + mutations go through the
 * /api service Media_Service_Media. ACL-gated admin+ (configs/acl.ini).
 */
class Media_IndexController extends Tiger_Controller_Action
{
    public function init()
    {
        parent::init();
        $this->_helper->layout()->setLayout('admin');
    }

    public function indexAction()
    {
        // Tell the uploader whether it must make the thumbnail itself: when the server has
        // no GD (or variants.server is off), the browser resizes it on upload.
        $cfg = Zend_Registry::isRegistered('Zend_Config') ? Zend_Registry::get('Zend_Config') : null;
        $var = ($cfg && $cfg->get('media') && $cfg->media->get('variants')) ? $cfg->media->variants : null;
        $serverOn = $var ? ($var->get('server') === null || (int) $var->get('server') !== 0) : true;

        $this->view->clientThumb = !(Tiger_Media_Image::hasGd() && $serverOn);
        $this->view->thumbPx     = ($var && (int) $var->get('thumbnail') > 0) ? (int) $var->thumbnail : 200;
        $this->view->title         = 'Media Library — Tiger Admin';
        $this->view->useDataTables = true;
    }
}
