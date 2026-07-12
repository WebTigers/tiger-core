<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Acl_Acl — the AUTHORIZATION engine (what may you do).
 *
 * A Zend_Acl subclass that only LOADS policy from data, in override order — it
 * hard-codes no rules. Ported from AskLevi's Levi_Acl_Acl, adapted to Tiger's
 * layout (Core is a package + default namespace, not a `core` module).
 *
 * Load order (Zend_Acl is last-write-wins per role/resource/privilege, so order =
 * precedence):
 *   1. Roles from ini   — the canonical role graph shipped in code (core + module
 *                         `configs/acl.ini`). Source of truth.
 *   2. Roles from DB    — acl_role rows layered on top (a derived app adds roles by
 *                         data; existing ini roles are left as-is).
 *   3. Resources + rules from ini — each unit's default policy.
 *   4. Resources + rules from DB  — loaded LAST, so DB overrides win.
 *
 * The deny-all baseline, god-mode, and public exemptions are all DATA (core
 * acl.ini), never code. See core/configs/acl.ini.
 *
 * acl.ini format (per unit):
 *   acl.roles.{k}.role / .parent (comma-sep) / .description
 *   acl.resources.{k}.resource / .description
 *   acl.rules.{k}.role / .resource / .privilege / .permission (allow|deny|removeAllow|removeDeny)
 *   `*` (or 'all'/'') = Zend_Acl wildcard (null).
 *
 * @api
 */
class Tiger_Acl_Acl extends Zend_Acl
{
    /**
     * Build the ACL by loading policy in override order (ini roles, DB roles, ini
     * rules, DB rules — last write wins).
     *
     * @return void
     */
    public function __construct()
    {
        $this->_loadRolesFromIni();
        $this->_loadRolesFromDb();
        $this->_loadRulesFromIni();
        $this->_loadRulesFromDb();
    }

    /**
     * Authorize a role against a resource and privilege (deny-by-default).
     *
     * Unknown role => not allowed (never a Zend_Acl exception). A user has exactly
     * one role per request (resolved from their org_user membership); callers pass
     * that single string.
     *
     * @param  string|null $role      the role name (null = the Zend_Acl wildcard)
     * @param  string|null $resource  the resource name (null = wildcard)
     * @param  string|null $privilege the privilege (null = wildcard)
     * @return bool                   true if allowed
     */
    public function isAllowed($role = null, $resource = null, $privilege = null)
    {
        if (is_string($role) && !$this->hasRole($role)) {
            return false;
        }
        return parent::isAllowed($role, $resource, $privilege);
    }

    /**
     * EXPLAIN an authorization decision — the debuggability pillar (ACL.md §7): the answer to "why am
     * I locked out?" Returns the decision PLUS the reasoning — the role's inheritance chain and the
     * deciding rule (explicit allow/deny, possibly inherited) or **deny-by-default**. Read-only; it
     * never changes policy. `isAllowed()` stays authoritative for the decision; the reason is derived
     * by probing the rules in Zend_Acl's specificity order, defensively (a Zend quirk degrades the
     * *reason*, never the decision).
     *
     * @param  string|null $role
     * @param  string|null $resource
     * @param  string|null $privilege
     * @return array {allowed, role, resource, privilege, roleKnown, resourceKnown, roleChain, rule, reason}
     */
    public function explain($role = null, $resource = null, $privilege = null)
    {
        $allowed   = $this->isAllowed($role, $resource, $privilege);
        $roleKnown = $role === null || $this->hasRole($role);
        $resKnown  = $resource === null || $this->has($resource);
        $chain     = $this->_roleChain($role);
        $rule      = null;

        if ($roleKnown) {
            try {
                // Walk specificity: each role in the chain then the wildcard; resource then wildcard;
                // privilege then wildcard. The first EXPLICIT rule is the one that decides.
                foreach (array_merge($chain, [null]) as $r) {
                    $roleObj = ($r !== null) ? $this->_getRoleRegistry()->get($r) : null;
                    foreach ([$resource, null] as $res) {
                        $resObj = ($res !== null && $this->has($res)) ? $this->get($res) : null;
                        foreach ([$privilege, null] as $priv) {
                            $type = $this->_getRuleType($resObj, $roleObj, $priv);
                            if ($type !== null) {
                                $rule = ['role' => $r ?? '*', 'resource' => $res ?? '*',
                                         'privilege' => $priv ?? '*', 'permission' => $type];
                                break 3;
                            }
                        }
                    }
                }
            } catch (Throwable $e) {
                $rule = null;   // reason degrades to the robust default below; decision unaffected
            }
        }

        return [
            'allowed'       => $allowed,
            'role'          => $role,
            'resource'      => $resource,
            'privilege'     => $privilege,
            'roleKnown'     => $roleKnown,
            'resourceKnown' => $resKnown,
            'roleChain'     => $chain,
            'rule'          => $rule,
            'reason'        => $this->_explainReason($role, $resource, $privilege, $allowed, $roleKnown, $rule),
        ];
    }

    /** Human sentence for an explain() result. */
    protected function _explainReason($role, $resource, $privilege, $allowed, $roleKnown, $rule)
    {
        $priv = $privilege !== null ? " · {$privilege}" : '';
        if (!$roleKnown) {
            return "DENY — unknown role '{$role}'. A user's role must exist in the ACL.";
        }
        if ($rule !== null) {
            $verb = $rule['permission'] === self::TYPE_ALLOW ? 'ALLOW' : 'DENY';
            $via  = ($rule['role'] !== $role && $rule['role'] !== '*') ? " (inherited: {$role} → {$rule['role']})" : '';
            return "{$verb} by rule — role={$rule['role']} · resource={$rule['resource']} · privilege={$rule['privilege']}{$via}.";
        }
        return $allowed
            ? "ALLOW — {$role} on {$resource}{$priv}."
            : "DENY (deny-by-default) — no rule grants role '{$role}' access to '{$resource}'{$priv}.";
    }

    /** A role's inheritance chain, most-specific first (the role, then its parents, transitively). */
    protected function _roleChain($role)
    {
        if ($role === null || !$this->hasRole($role)) {
            return $role === null ? [] : [$role];
        }
        $chain = [$role];
        $seen  = [$role => true];
        $queue = [$role];
        try {
            while ($queue) {
                $r = array_shift($queue);
                foreach ($this->_getRoleRegistry()->getParents($r) as $pid => $parent) {
                    if (empty($seen[$pid])) { $seen[$pid] = true; $chain[] = $pid; $queue[] = $pid; }
                }
            }
        } catch (Throwable $e) {
            // partial chain is fine
        }
        return $chain;
    }

    /**
     * Every `configs/acl.ini` Tiger should read: the core package, then app
     * modules, then first-party (package) modules.
     *
     * @return string[]
     */
    protected function _aclIniPaths()
    {
        $paths = [];

        $core = TIGER_CORE_PATH . '/core/configs/acl.ini';
        if (is_file($core)) {
            $paths[] = $core;
        }
        foreach ([APPLICATION_PATH . '/modules', TIGER_CORE_PATH . '/modules'] as $modsDir) {
            foreach (glob($modsDir . '/*', GLOB_ONLYDIR) ?: [] as $moduleDir) {
                $ini = $moduleDir . '/configs/acl.ini';
                if (is_file($ini)) {
                    $paths[] = $ini;
                }
            }
        }
        return $paths;
    }

    // ----- roles -------------------------------------------------------------

    protected function _loadRolesFromIni()
    {
        $parents = [];
        foreach ($this->_aclIniPaths() as $iniPath) {
            try {
                $acl = (new Zend_Config_Ini($iniPath, APPLICATION_ENV))->get('acl');
                if (!$acl || !$acl->get('roles')) { continue; }
                foreach ($acl->roles as $role) {
                    $name = $role->role ?? null;
                    if (empty($name)) { continue; }
                    $parents[$name] = !empty($role->parent)
                        ? array_filter(array_map('trim', explode(',', (string) $role->parent)))
                        : [];
                }
            } catch (Throwable $e) {}
        }
        $this->_addRolesTopologically($parents);
    }

    protected function _loadRolesFromDb()
    {
        try {
            $parents = [];
            foreach ((new Tiger_Model_AclRole())->getRoleList() as $row) {
                $parents[$row->role] = !empty($row->parent_role)
                    ? array_filter(array_map('trim', explode(',', (string) $row->parent_role)))
                    : [];
            }
            $this->_addRolesTopologically($parents);
        } catch (Throwable $e) {}

        if (!$this->hasRole('guest')) {
            $this->addRole(new Zend_Acl_Role('guest'));   // guest must always exist
        }
    }

    /**
     * Register roles parent-before-child (a child added before its parent orphans
     * the chain). Pre-existing roles satisfy parent deps, so ini stays canonical
     * and DB only adds new roles. Cycles/missing parents are added parentless.
     *
     * @param array<string,string[]> $parents role => parent names
     */
    protected function _addRolesTopologically(array $parents)
    {
        $added = array_values(array_filter(array_keys($parents), function ($n) { return $this->hasRole($n); }));
        $pending = array_keys($parents);

        while ($pending) {
            $next = [];
            $progress = false;
            foreach ($pending as $name) {
                $unmet = array_filter($parents[$name], function ($p) use ($added) {
                    return !in_array($p, $added, true) && !$this->hasRole($p);
                });
                if (empty($unmet)) {
                    if (!$this->hasRole($name)) {
                        $this->addRole(new Zend_Acl_Role($name), $parents[$name] ?: null);
                    }
                    $added[] = $name;
                    $progress = true;
                } else {
                    $next[] = $name;
                }
            }
            if (!$progress) {
                foreach ($next as $name) {
                    if (!$this->hasRole($name)) { $this->addRole(new Zend_Acl_Role($name)); }
                }
                break;
            }
            $pending = $next;
        }
    }

    // ----- resources + rules -------------------------------------------------

    protected function _loadRulesFromIni()
    {
        foreach ($this->_aclIniPaths() as $iniPath) {
            try {
                $acl = (new Zend_Config_Ini($iniPath, APPLICATION_ENV))->get('acl');
                if (!$acl) { continue; }

                if ($acl->get('resources')) {
                    foreach ($acl->resources as $res) {
                        $this->_registerResource($res->resource ?? null);
                    }
                }
                if ($acl->get('rules')) {
                    foreach ($acl->rules as $rule) {
                        $this->_applyRule($rule->role ?? null, $rule->resource ?? null,
                            $rule->privilege ?? null, $rule->permission ?? 'allow');
                    }
                }
            } catch (Throwable $e) {
                // A unit's ACL failing to load is security-relevant (under deny-all
                // it locks that unit out) — surface it, don't swallow silently.
                error_log('Tiger_Acl_Acl: acl.ini failed to load: ' . $iniPath . ' — ' . $e->getMessage());
            }
        }
    }

    protected function _loadRulesFromDb()
    {
        try {
            foreach ((new Tiger_Model_AclResource())->getResourceList() as $row) {
                $this->_registerResource($row->resource);
            }
        } catch (Throwable $e) {}

        try {
            foreach ((new Tiger_Model_AclRule())->getRuleList() as $row) {
                $this->_applyRule($row->role, $row->resource, $row->privilege, $row->permission ?? 'allow');
            }
        } catch (Throwable $e) {}
    }

    protected function _registerResource($resource)
    {
        if (empty($resource) || $this->has($resource)) { return; }
        $this->addResource(new Zend_Acl_Resource($resource));
    }

    protected function _applyRule($role, $resource, $privilege, $permission)
    {
        // '*'/'all'/'' => Zend_Acl wildcard (null) for role, resource AND privilege,
        // so `role=* resource=* deny` becomes deny(null,null,null) — the deny-all baseline.
        $wild = function ($v) { return ($v === '*' || $v === 'all' || $v === '' || $v === null) ? null : $v; };
        $role = $wild($role); $resource = $wild($resource); $privilege = $wild($privilege);

        // A named (non-wildcard) role/resource that isn't registered can't be ruled on.
        if ($resource !== null && !$this->has($resource))  { return; }
        if ($role     !== null && !$this->hasRole($role))   { return; }

        switch ($permission) {
            case 'allow':       $this->allow($role, $resource, $privilege); break;
            case 'deny':        $this->deny($role, $resource, $privilege); break;
            case 'removeAllow': $this->removeAllow($role, $resource, $privilege); break;
            case 'removeDeny':  $this->removeDeny($role, $resource, $privilege); break;
        }
    }
}
