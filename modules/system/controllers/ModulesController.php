<?php
/**
 * System_ModulesController — the Modules admin screen (the WordPress "Plugins" analogue).
 *
 * Lists every module ON DISK (via Tiger_Module_Discovery — the runtime map hides deactivated
 * ones, so we scan directories) with its manifest details + activation state, and lets a
 * superadmin activate/deactivate the non-protected ones. Thin: mutations go through
 * System_Service_Modules over /api. ACL-gated to superadmin+ (configs/acl.ini).
 */
class System_ModulesController extends Tiger_Controller_Action
{
    public function init()
    {
        parent::init();
        $this->_helper->layout()->setLayout('admin');
    }

    public function indexAction()
    {
        $installed = (new Tiger_Model_InstalledModule())->bySlugMap();

        $modules = [];
        foreach (Tiger_Module_Discovery::all() as $slug => $m) {
            $row    = $installed[$slug] ?? null;
            $active = $row ? ((int) $row->active === 1) : true;   // absent = active (default)
            $source = $row ? $row->source : ($m['area'] === 'core' ? 'bundled' : 'custom');
            $modules[] = $m + [
                'active'    => $active,
                'source'    => $source,
                'protected' => in_array($slug, System_Service_Modules::PROTECTED, true),
            ];
        }

        $this->view->title   = 'Modules — Tiger Admin';
        $this->view->modules = $modules;
    }
}
