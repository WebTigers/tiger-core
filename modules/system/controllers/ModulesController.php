<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * System_ModulesController — the Modules admin screen (the WordPress "Plugins" analogue).
 *
 * Lists every module ON DISK (via Tiger_Module_Discovery — the runtime map hides deactivated
 * ones, so we scan directories) with its manifest details + activation state, and lets a
 * superadmin activate/deactivate the non-protected ones. Thin: mutations go through
 * System_Service_Modules over /api. ACL-gated to superadmin+ (configs/acl.ini).
 */
class System_ModulesController extends Tiger_Controller_Admin_Action
{
    /**
     * Admin shell (layout) comes from the base; keep the explicit init cascade.
     *
     * @return void
     */
    public function init()
    {
        parent::init();
    }

    /**
     * List every module on disk with its manifest, source, and activation state.
     *
     * @return void
     */
    public function indexAction()
    {
        $installed   = (new Tiger_Model_Module())->bySlugMap();
        $activeTheme = (string) (new Tiger_Model_Config())->get(Tiger_Model_Config::SCOPE_GLOBAL, '', 'tiger.theme');

        $modules = [];
        foreach (Tiger_Module_Discovery::all() as $slug => $m) {
            $row     = $installed[$slug] ?? null;
            $isTheme = ($m['type'] ?? 'module') === 'theme';
            // A theme's active state is the tiger.theme config (its KEY, one per scope); a module's is its flag.
            $active  = $isTheme ? ($activeTheme === ($m['key'] ?? $slug)) : ($row ? ((int) $row->active === 1) : true);
            $source  = $row ? $row->source : ($m['area'] === 'core' ? 'bundled' : 'custom');
            $modules[] = $m + [
                'active'    => $active,
                'source'    => $source,
                'protected' => in_array($slug, System_Service_Modules::PROTECTED, true),
                // Advisory: tested-version compat notice (never blocks) + who requires this module
                // (drives the "required by X, Y — deactivate anyway?" confirm; empty for most).
                'compat'      => Tiger_Module_Compat::check($m),
                'required_by' => $isTheme ? [] : Tiger_Module_Dependency::dependents($slug),
            ];
        }

        $this->view->title       = 'Modules — Tiger Admin';
        $this->view->modules     = $modules;
        $this->view->activeTheme = $activeTheme;
    }

    /**
     * Add New — registry search + install-from-URL (with a preview step). The screen shell;
     * search/inspect/install are /api calls to System_Service_Modules.
     *
     * @return void
     */
    public function addAction()
    {
        $this->view->title           = 'Add Module — Tiger Admin';
        $this->view->registryUrl     = Tiger_Module_Registry::indexUrl();
        $this->view->registryHasData = Tiger_Module_Registry::available();
    }
}
