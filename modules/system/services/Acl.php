<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * System_Service_Acl — the ACL Simulator (ACL.md §7, "debuggability first").
 *
 * `simulate()` answers "would role X reach resource Y · privilege Z, and WHY?" by running the live
 * `Tiger_Acl_Acl::explain()` — the decision plus the deciding rule (or deny-by-default) and the role
 * inheritance chain. `catalog()` feeds the screen's role/resource pickers. Read-only; changes nothing.
 */
class System_Service_Acl extends Tiger_Service_Service
{
    /**
     * Explain an authorization decision for an arbitrary (role, resource, privilege).
     *
     * @param  array $params {role, resource, privilege}
     * @return void
     */
    public function simulate(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }
        $acl = $this->_acl();
        if (!$acl) { $this->_error('system.acl.unavailable'); return; }

        $role      = trim((string) ($params['role'] ?? ''))      ?: null;
        $resource  = trim((string) ($params['resource'] ?? ''))  ?: null;
        $privilege = trim((string) ($params['privilege'] ?? '')) ?: null;

        $this->_success(['explain' => $acl->explain($role, $resource, $privilege)]);
    }

    /**
     * The roles + resources the ACL knows about (for the Simulator's pickers).
     *
     * @param  array $params (none)
     * @return void
     */
    public function catalog(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }
        $acl = $this->_acl();
        if (!$acl) { $this->_error('system.acl.unavailable'); return; }

        $roles = method_exists($acl, 'getRoles') ? $acl->getRoles() : [];
        $resources = method_exists($acl, 'getResources') ? $acl->getResources() : [];
        sort($resources);
        $this->_success(['roles' => $roles, 'resources' => $resources]);
    }

    /** @return Tiger_Acl_Acl|null the live ACL. */
    protected function _acl()
    {
        $acl = Zend_Registry::isRegistered('Zend_Acl') ? Zend_Registry::get('Zend_Acl') : null;
        return $acl instanceof Tiger_Acl_Acl ? $acl : null;
    }
}
