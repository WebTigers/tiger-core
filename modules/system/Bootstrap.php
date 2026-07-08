<?php
/**
 * System module bootstrap — platform administration (the Module manager, for now).
 *
 * First-party, always-on module (it manages the OTHER modules' activation, so it's in the
 * protected set and can never be deactivated). Auto-discovered like any module.
 */
class System_Bootstrap extends Zend_Application_Module_Bootstrap
{
}
