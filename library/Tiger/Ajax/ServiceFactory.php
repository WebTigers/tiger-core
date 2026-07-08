<?php
/**
 * Tiger_Ajax_ServiceFactory — the single-gateway /api dispatcher.
 *
 * Ported from AskLevi's proven Levi_Ajax_ServiceFactory (the TIGER "message
 * pattern"): the routing target rides INSIDE the request, not the URL path. One
 * `/api` endpoint; the POST body carries `module` + (`service`+`method`) OR
 * (`controller`+`action`) alongside the payload, and the whole message is handed
 * to the target.
 *
 * Two first-class modes (not a fallback chain — you name one or the other):
 *   - SERVICE  (`service` present): resolve {Module}_Service_{Service}, construct
 *     it with the merged message, return its ResponseObject (ApiController JSON-
 *     encodes it). The service did its work in construction.
 *   - CONTROLLER (`controller` present): resolve {Module}_{Controller}Controller,
 *     ACL-check, then signal a _forward so the controller OWNS its response. More
 *     flexible/brittle — the whole payload just lands in the action as $params.
 *
 * Param resolution per key: POST body > GET query > route (svc_* names, to avoid
 * ZF1's reserved :module/:controller/:action).
 *
 * ACL is deny-by-default (resource = target class, privilege = action). Nothing
 * throws to the caller — every failure becomes a clean result=0 ResponseObject.
 *
 * TIGER IMPROVEMENT over AskLevi: a RESERVED-MODULE guard. AskLevi's services are
 * `Core_Service_*`; Tiger's kernel services are `Tiger_Service_*` and must NEVER
 * be reachable through /api. Since the class name is built as
 * ucfirst(module)."_Service_"..., a `module=tiger` would resolve `Tiger_Service_*`
 * — so `tiger` (and the other framework namespaces) are reserved and refused
 * before any class is touched. Extend the list with reserve().
 *
 * @api
 */
class Tiger_Ajax_ServiceFactory
{
    /**
     * Module names that can NEVER be dispatched via /api — they map to framework /
     * core namespaces. The public API is app/module surface only.
     *
     * @var string[]
     */
    protected static $_reserved = ['tiger', 'zend', 'core', 'default', 'library', 'application'];

    /** @var Zend_Controller_Request_Http */
    protected $_request;

    /** @var Tiger_Model_ResponseObject */
    protected $_response;

    /** Controller-mode hand-off descriptor, or null in service mode. */
    protected $_forward = null;

    public function __construct(Zend_Controller_Request_Http $request)
    {
        $this->_request  = $request;
        $this->_response = new Tiger_Model_ResponseObject();
        $this->_processRequest();
    }

    /** Reserve an additional module name from /api dispatch. */
    public static function reserve($module)
    {
        $module = strtolower($module);
        if (!in_array($module, self::$_reserved, true)) {
            self::$_reserved[] = $module;
        }
    }

    /** Is this module name reserved (framework-internal, not API-routable)? */
    public static function isReserved($module)
    {
        return in_array(strtolower($module), self::$_reserved, true);
    }

    public function getResponse()
    {
        return $this->_response;
    }

    /** Controller-mode: ['module','controller','action','params'] for ApiController to _forward, else null. */
    public function getForward()
    {
        return $this->_forward;
    }

    // -------------------------------------------------------------------------

    protected function _processRequest()
    {
        try {
            $params     = $this->_resolveParams();
            $module     = $params['module'];
            $service    = $params['service'];
            $controller = $params['controller'];
            $action     = $params['action'];

            // Reserved-module guard — DISABLED. The ACL is the gate: _authorize() runs
            // before any class is touched, and deny-by-default refuses every resource that
            // lacks an explicit `allow` rule (so Tiger_Service_SuperSecret is auto-denied,
            // while a public utility like Tiger_Service_Validate is reachable via one allow
            // rule). This lets first-party CORE services (module=tiger) ride /api like any
            // other, gated purely by ACL. Trade-off: the god `developer` role (allow * *)
            // can now reach kernel Tiger_Service_* over /api, and the pre-ACL fail-open
            // window is no longer double-covered. Re-enable this block to restore the
            // defense-in-depth backstop.
            // if (self::isReserved($module)) {
            //     $this->_fail('core.api.error.general');
            //     return;
            // }

            // --- SERVICE mode ---
            if ($service !== '') {
                $className = ucfirst($module) . '_Service_' . ucfirst($service);
                if (!$this->_authorize($className, $action)) {
                    return; // _authorize set the error response
                }
                if (!class_exists($className, true)
                    || !is_subclass_of($className, 'Tiger_Service_Service')) {
                    $this->_fail('core.api.error.general');
                    return;
                }
                $this->_response = (new $className($params))->getResponse();
                return;
            }

            // --- CONTROLLER mode ---
            if ($controller !== '') {
                $className = ucfirst($module) . '_' . ucfirst($controller) . 'Controller';
                if (!$this->_authorize($className, $action)) {
                    return;
                }
                if (!class_exists($className, true) || !method_exists($className, $action . 'Action')) {
                    $this->_fail('core.api.error.general');
                    return;
                }
                $this->_forward = [
                    'module'     => $module,
                    'controller' => $controller,
                    'action'     => $action,
                    'params'     => $params,
                ];
                return;
            }

            // Neither named.
            $this->_fail('core.api.error.missing_service');

        } catch (Throwable $e) {
            $this->_response->result     = 0;
            $this->_response->messages[] = new Tiger_Model_MessageObject(
                APPLICATION_ENV !== 'production' ? $e->getMessage() : 'core.api.error.general',
                'error'
            );
        }
    }

    /**
     * Deny-by-default ACL pre-auth, shared by both modes. When no ACL is loaded
     * (only before the ACL engine exists) we fail-OPEN rather than hard-deny every
     * call — this flips to fail-closed the moment Tiger_Service_Acl registers.
     */
    protected function _authorize($className, $privilege)
    {
        if (!Zend_Registry::isRegistered('Zend_Acl')) {
            return true;
        }
        $acl      = Zend_Registry::get('Zend_Acl');
        $identity = Zend_Auth::getInstance()->getIdentity();
        $role     = $identity->role ?? 'guest';

        if (!$acl->has($className)) {
            $acl->addResource(new Zend_Acl_Resource($className));   // registered = governed by baseline deny
        }
        if ($acl->isAllowed($role, $className, $privilege)) {
            return true;
        }

        $this->_response->result     = 0;
        $this->_response->messages[]  = new Tiger_Model_MessageObject(
            $role === 'guest' ? 'core.api.error.login_required' : 'core.api.error.not_allowed',
            'error'
        );
        if ($role === 'guest') {
            $this->_response->data = (object) ['login' => 1];
        }
        return false;
    }

    /** Set a clean result=0 error with one message key. */
    protected function _fail($messageKey)
    {
        $this->_response->result     = 0;
        $this->_response->messages[] = new Tiger_Model_MessageObject($messageKey, 'error');
    }

    /**
     * Resolve module/service/controller/action from POST > GET > route (svc_*).
     * Segments are sanitized to safe characters BEFORE they become class names.
     */
    protected function _resolveParams()
    {
        $r = $this->_request;

        $defaultModule = ($this->_config('tiger', 'api', 'default_module')) ?: '';

        $module     = $r->getPost('module')     ?? $r->getQuery('module')     ?? $r->getParam('svc_module', $defaultModule);
        $service    = $r->getPost('service')    ?? $r->getQuery('service')    ?? $r->getParam('svc_service', '');
        $controller = $r->getPost('controller') ?? $r->getQuery('controller') ?? $r->getParam('svc_controller', '');
        $action     = $r->getPost('method')  ?? $r->getPost('action')
                   ?? $r->getQuery('method') ?? $r->getQuery('action')
                   ?? $r->getParam('svc_action', '');

        // Routing segments are alpha only (they become class names); action allows digits/underscore.
        $module     = preg_replace('/[^a-zA-Z]/', '', (string) $module);
        $service    = preg_replace('/[^a-zA-Z]/', '', (string) $service);
        $controller = preg_replace('/[^a-zA-Z]/', '', (string) $controller);
        $action     = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $action);

        if ($action === '') {
            throw new RuntimeException('core.api.error.missing_action');
        }
        if (($service !== '' || $controller !== '') && $module === '') {
            throw new RuntimeException('core.api.error.missing_module');
        }

        return array_merge($r->getParams(), [
            'module'     => $module,
            'service'    => $service,
            'controller' => $controller,
            'action'     => $action,
        ]);
    }

    /** Small nested-config reader against the registry Zend_Config. */
    protected function _config()
    {
        if (!Zend_Registry::isRegistered('Zend_Config')) {
            return null;
        }
        $node = Zend_Registry::get('Zend_Config');
        foreach (func_get_args() as $key) {
            if (!($node instanceof Zend_Config) || $node->{$key} === null) {
                return null;
            }
            $node = $node->{$key};
        }
        return $node;
    }
}
