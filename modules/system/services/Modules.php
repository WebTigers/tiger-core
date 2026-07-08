<?php
/**
 * System_Service_Modules — the /api service for the Module manager (activate / deactivate).
 *
 * Toggling flips the `installed_module.active` flag; the activation gate
 * (Tiger_Application_Resource_Modules) picks it up on the NEXT request — a deactivated module
 * drops off routing + bootstrapping entirely. Gated to `superadmin`+ (managing modules is a
 * platform-admin privilege). A PROTECTED set can never be deactivated so you can't lock
 * yourself out of the manager, user admin, or core dispatch.
 */
class System_Service_Modules extends Tiger_Service_Service
{
    /** Modules that must always stay active. */
    const PROTECTED = ['default', 'system', 'access'];

    public function activate(array $params): void   { $this->_toggle($params, true); }
    public function deactivate(array $params): void { $this->_toggle($params, false); }

    protected function _toggle(array $params, $on): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }

        $slug = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($params['slug'] ?? ''));
        if ($slug === '') { $this->_error('core.api.error.general'); return; }
        if (in_array($slug, self::PROTECTED, true)) { $this->_error('system.error.protected'); return; }

        $discovered = Tiger_Module_Discovery::all();
        if (!isset($discovered[$slug])) { $this->_error('system.error.unknown'); return; }

        try {
            $d = $discovered[$slug];
            (new Tiger_Model_InstalledModule())->setActive($slug, $on, ['name' => $d['name'], 'version' => $d['version']]);
            $this->_success(
                ['slug' => $slug, 'active' => $on],
                $on ? 'system.module.activated' : 'system.module.deactivated',
                '/system/modules'
            );
        } catch (Throwable $e) {
            $this->_error(APPLICATION_ENV !== 'production' ? $e->getMessage() : 'core.api.error.general');
        }
    }
}
