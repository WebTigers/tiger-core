<?php
/**
 * Tiger_Controller_Plugin_Authorization — the AUTHORIZATION gate.
 *
 * A front-controller plugin (NOT a base-controller preDispatch) so it runs for
 * EVERY dispatch regardless of what a controller extends. This is the deliberate
 * improvement over AskLevi's base-controller approach: authorization can't be
 * bypassed by forgetting to extend a base class — deny-by-default applies to every
 * controller, uniformly, for the default namespace and modules alike.
 *
 * Two design choices worth knowing:
 *   - LIVE ROLE. The session stores only "who + which org" (user_id + org_id). The
 *     role is resolved FRESH from org_user every request, so a revoked or changed
 *     membership takes effect on the very next request — no stale session
 *     permissions, no forced re-login. (This is where the actor is stamped too.)
 *   - EXEMPTIONS ARE DATA. Public entry points (login, /api, errors, the public
 *     home) aren't hardcoded here — they're `allow guest` rules in core acl.ini.
 *
 * @api
 */
class Tiger_Controller_Plugin_Authorization extends Zend_Controller_Plugin_Abstract
{
    const ROLE_GUEST         = 'guest';
    const ROLE_AUTHENTICATED = 'user';

    public function preDispatch(Zend_Controller_Request_Abstract $request)
    {
        // Don't gate a request with no dispatchable controller — let ZF's
        // ErrorHandler render a clean 404 for everyone, instead of bouncing a
        // guest to login for a URL that doesn't even exist. Real controllers
        // (dispatchable) are still gated deny-by-default below.
        $dispatcher = Zend_Controller_Front::getInstance()->getDispatcher();
        if (!$dispatcher->isDispatchable($request)) {
            return;
        }

        // A LOCKED screen is a suspended session: authorize it as guest (below), so
        // guest-accessible resources (the public site, the auth pages) still render,
        // and anything requiring auth is denied and bounced to the lock card by
        // _deny(). Enforced through the SAME ACL path — pages and /api services alike.
        $role     = $this->_resolveRole();
        $resource = $this->_resourceFor($request);
        if ($resource === null) {
            return;   // nothing dispatchable to gate yet
        }

        // Fail OPEN only if the ACL never loaded (partial boot) — never lock the
        // whole app out. Once Tiger_Acl_Acl is registered this is fail-CLOSED.
        if (!Zend_Registry::isRegistered('Zend_Acl')) {
            return;
        }
        /** @var Zend_Acl $acl */
        $acl = Zend_Registry::get('Zend_Acl');

        // Deny-by-default: register the resource so the baseline deny governs it,
        // then evaluate. A resource with no explicit allow is denied, never open.
        if (!$acl->has($resource)) {
            $acl->addResource(new Zend_Acl_Resource($resource));
        }

        if ($acl->isAllowed($role, $resource, $request->getActionName())) {
            return;   // allowed — proceed to the action
        }
        $this->_deny($role);
    }

    /**
     * Resolve the caller's role for THIS request. Guests get 'guest'. Authenticated
     * callers get the role from their active org's membership, read LIVE from
     * org_user (immediate revocation). Also stamps the actor and refreshes the
     * in-memory identity so services see the fresh role.
     */
    protected function _resolveRole()
    {
        $identity = Zend_Auth::getInstance()->getIdentity();
        if (!$identity || empty($identity->user_id)) {
            return self::ROLE_GUEST;
        }

        // Locked screen: the session is SUSPENDED — authorize as guest everywhere
        // (pages AND /api services, which read $identity->role too) until the user
        // re-verifies at the lock card. Identity is otherwise preserved.
        if ((new Tiger_Service_Authentication())->isLocked()) {
            $identity->role = self::ROLE_GUEST;
            return self::ROLE_GUEST;
        }

        Tiger_Model_Table::setActor($identity->user_id);   // created_by/updated_by flow

        $role = self::ROLE_AUTHENTICATED;
        if (!empty($identity->org_id)) {
            try {
                $live = (new Tiger_Model_OrgUser())->roleOf($identity->org_id, $identity->user_id);
                $role = $live ?: self::ROLE_AUTHENTICATED;  // membership gone -> base role
            } catch (Throwable $e) {
                $role = isset($identity->role) ? $identity->role : self::ROLE_AUTHENTICATED;
            }
        }
        $identity->role = $role;   // refresh for services (_isAdmin)
        return $role;
    }

    /** The ACL resource for this dispatch = the controller class name (ZF1 convention). */
    protected function _resourceFor(Zend_Controller_Request_Abstract $request)
    {
        $controller = (string) $request->getControllerName();
        if ($controller === '') {
            return null;
        }
        $module  = (string) $request->getModuleName();
        $default = Zend_Controller_Front::getInstance()->getDispatcher()->getDefaultModule();

        $class = $this->_studly($controller) . 'Controller';
        if ($module !== '' && $module !== $default) {
            $class = $this->_studly($module) . '_' . $class;
        }
        return $class;
    }

    /** Denied: locked -> lock card; guests -> login (302); authed-but-forbidden -> themed 403. */
    protected function _deny($role)
    {
        $auth = new Tiger_Service_Authentication();
        $path = (string) $this->getRequest()->getPathInfo();
        $redirector = Zend_Controller_Action_HelperBroker::getStaticHelper('redirector');

        // Remember where they were headed (in SESSION, not a URL param) so login /
        // unlock can return them there. setReturnTo ignores non-local + auth paths.
        $auth->setReturnTo($path);

        // Authenticated but LOCKED: bounce to the lock card to re-verify. They're
        // signed in (just suspended), so this is neither a login redirect nor a 403.
        if ($auth->isLocked()) {
            $redirector->gotoUrlAndExit('/auth/lock');
            return;
        }

        if ($role === self::ROLE_GUEST) {
            $redirector->gotoUrlAndExit('/auth/login');
        }

        // Authenticated but forbidden: re-dispatch to the themed 403 page instead of
        // emitting a bare string. ErrorController is public in acl.ini, so the
        // re-dispatch passes this same gate cleanly (no deny loop).
        $request = $this->getRequest();
        $request->setModuleName('default')
                ->setControllerName('error')
                ->setActionName('forbidden')
                ->setDispatched(false);
        $this->getResponse()->setHttpResponseCode(403);
    }

    /** hyphen/dot/underscore-slug -> StudlyCase (user-admin -> UserAdmin). */
    private function _studly($name)
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '.', '_'], ' ', strtolower($name))));
    }
}
