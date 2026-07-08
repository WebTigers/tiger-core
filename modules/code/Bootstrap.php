<?php
/**
 * Tiger Code module bootstrap.
 *
 * First-party module — the authoring surface for Tiger Code (the `code` table). The engine
 * (compile/execute + the global loader) lives in the platform layer (Tiger_Model_Code,
 * Tiger_Code_Runtime, Bootstrap::_initCode); this module is the admin UI on top of it.
 *
 * Extending Zend_Application_Module_Bootstrap gives the module its resource autoloader, so
 * Code_Service_* (services/) and Code_Form_* (forms/) load by convention; controllers load
 * via the registered module dir; configs/acl.ini + languages/ are picked up by the core globs.
 */
class Code_Bootstrap extends Zend_Application_Module_Bootstrap
{
}
