<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Controller_Action — project base controller for CONVENIENCES ONLY.
 *
 * Controllers may extend this for shared sugar (config/translate handles, flash
 * messenger, a JSON helper). Authorization is deliberately NOT here — it's the
 * unbypassable Tiger_Controller_Plugin_Authorization, so security never depends on
 * a controller remembering to extend this class.
 *
 * @api
 */
class Tiger_Controller_Action extends Zend_Controller_Action
{
    /** @var Zend_Config|null */
    protected $_config = null;

    /** @var Zend_Translate|null */
    protected $_translate = null;

    /** @var Zend_Controller_Action_Helper_FlashMessenger */
    protected $_flash;

    public function init()
    {
        parent::init();
        if (Zend_Registry::isRegistered('Zend_Config')) {
            $this->_config = Zend_Registry::get('Zend_Config');
        }
        if (Zend_Registry::isRegistered('Zend_Translate')) {
            $this->_translate = Zend_Registry::get('Zend_Translate');
        }
        $this->_flash = $this->_helper->getHelper('FlashMessenger');
    }

    /** Disable layout/view and send a JSON body. */
    protected function _json($data, $status = 200)
    {
        $this->_helper->layout()->disableLayout();
        $this->_helper->viewRenderer->setNoRender(true);
        $this->getResponse()
            ->setHttpResponseCode($status)
            ->setHeader('Content-Type', 'application/json; charset=UTF-8')
            ->setBody(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
