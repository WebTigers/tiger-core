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
    /**
     * Strip deactivated modules from the controller-directory map, then delegate to the
     * stock module bootstrapping.
     *
     * @return mixed the stock resource's initialized module bootstraps
     */
    public function init()
    {
        $front   = Zend_Controller_Front::getInstance();
        $default = $front->getDefaultModule();

        // Defensive: a stray NON-module directory in modules/ (e.g. a leftover ".bak" backup from an
        // interrupted update) makes ZF1's scan try to bootstrap a class that doesn't exist and brick
        // the ENTIRE app. A real module's name is a slug ([a-z0-9_-]); anything with other characters
        // (a dot, a space) isn't a module, so drop it from the map before the scan can trip on it.
        foreach (array_keys($front->getControllerDirectory()) as $name) {
            if ((string) $name !== $default && preg_match('/[^a-z0-9_-]/i', (string) $name)) {
                $front->removeControllerDirectory($name);
            }
        }

        try {
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
