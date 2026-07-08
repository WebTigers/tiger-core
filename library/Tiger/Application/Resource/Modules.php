<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Application_Resource_Modules — module bootstrapping, with an activation gate.
 *
 * Drop-in replacement for Zend_Application_Resource_Modules (resolved in place of it because
 * Tiger_Application_Bootstrap::_initTigerPaths registers the `Tiger_Application_Resource`
 * plugin prefix). It does ONE thing before delegating to the stock behavior: remove
 * deactivated modules from the front controller's controller-directory map.
 *
 * That map is the single control point ZF1 uses for BOTH bootstrapping (parent::init()
 * iterates it) and dispatch (the router/dispatcher read it) — so stripping a module here
 * means it neither loads its Bootstrap nor answers any URL. Deactivation = invisible, not
 * deleted.
 *
 * Fail-safe: any error (no DB, no `module` table on a fresh install) leaves the map
 * untouched, so every module stays active — never a worse state than stock ZF1.
 *
 * @api
 */
class Tiger_Application_Resource_Modules extends Zend_Application_Resource_Modules
{
    public function init()
    {
        try {
            $front = Zend_Controller_Front::getInstance();
            $default = $front->getDefaultModule();
            foreach ((new Tiger_Model_Module())->inactiveSlugs() as $slug) {
                $slug = (string) $slug;
                // never strip the default (core) namespace; the protected set is enforced by the
                // admin service so a row for one can't exist, but guard the kernel here regardless.
                if ($slug !== '' && $slug !== $default) {
                    $front->removeControllerDirectory($slug);
                }
            }
        } catch (Throwable $e) {
            // no DB / no table yet -> leave every module active (safe default)
        }

        return parent::init();
    }
}
