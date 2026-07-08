<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Service_Service — abstract base for every /api service.
 *
 * This is the class services extend SO THAT they can consume and route API
 * requests. Ported from AskLevi's Levi_Service_Service (the proven TIGER pattern
 * from the "REST is Dying" design): the ServiceFactory constructs a concrete
 * child with the WHOLE request message, and the base routes it to the action
 * method named by the message. The action receives the entire `$params` payload —
 * you don't hand-wire arguments; the message IS the argument.
 *
 *   class Billing_Service_Invoice extends Tiger_Service_Service
 *   {
 *       public function create(array $params): void
 *       {
 *           if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }
 *           // ... do work with $params ...
 *           $this->_success($invoice);
 *       }
 *   }
 *
 * The caller then just reads getResponse() — all the work happened during
 * construction. That's the "slick" bit from the article: instantiate, and it's
 * already done.
 *
 * @api
 */
abstract class Tiger_Service_Service
{
    /** @var Tiger_Model_ResponseObject */
    protected $_response;

    /** @var Zend_Config|null */
    protected $_config = null;

    /** @var Zend_Translate|null */
    protected $_translate = null;

    /** @var mixed|null authenticated identity */
    protected $_identity = null;

    /** @var string|null current user id (from identity) */
    protected $_user_id = null;

    /** @var string|null current org id (the tenant the identity is acting in) */
    protected $_org_id = null;

    public function __construct(array $params = [])
    {
        $this->_response = new Tiger_Model_ResponseObject();

        if (Zend_Registry::isRegistered('Zend_Config')) {
            $this->_config = Zend_Registry::get('Zend_Config');
        }

        if (Zend_Registry::isRegistered('Zend_Translate')) {
            $this->_translate = Zend_Registry::get('Zend_Translate');
            // /api is never locale-prefixed, so response text takes its locale from
            // the payload (an explicit `locale`) or the request's current LANG.
            $payloadLang = isset($params['locale'])
                ? strtolower(substr((string) $params['locale'], 0, 2))
                : null;
            if ($payloadLang && defined('SUPPORTED_LANGS') && in_array($payloadLang, SUPPORTED_LANGS, true)) {
                try { $this->_translate->setLocale($payloadLang); } catch (Throwable $e) {}
            }
        }

        $identity = Zend_Auth::getInstance()->getIdentity();
        if ($identity) {
            $this->_identity = $identity;
            $this->_user_id  = $identity->user_id ?? null;
            $this->_org_id   = $identity->org_id  ?? null;
        }

        $this->init();
        $this->_dispatch($params);
    }

    /** Optional child setup that runs before dispatch. */
    protected function init() {}

    // -------------------------------------------------------------------------

    /**
     * Route the message to the action method it names. The method receives the
     * full $params. Unknown/uncallable actions and thrown errors become a clean
     * error response — nothing leaks.
     */
    protected function _dispatch(array $params)
    {
        if (empty($params['action'])) {
            return;
        }
        $action = preg_replace('/[^A-Za-z0-9_]/', '', $params['action']);

        // Only real, callable methods dispatch. (The '_' guard below keeps the
        // message from reaching protected/internal helpers named like actions.)
        if ($action === '' || $action[0] === '_'
            || !method_exists($this, $action) || !is_callable([$this, $action])) {
            $this->_error('core.api.error.invalid_action');
            return;
        }

        try {
            $this->{$action}($params);
        } catch (Throwable $e) {
            $this->_error(APPLICATION_ENV !== 'production' ? $e->getMessage() : 'core.api.error.general');
        }
    }

    // ----- response helpers --------------------------------------------------

    protected function _success($data = null, $message = 'core.api.success', $redirect = null)
    {
        $this->_response->result     = 1;
        $this->_response->data       = $data;
        $this->_response->redirect   = $redirect;
        $this->_response->messages[] = new Tiger_Model_MessageObject($message, 'success');
    }

    protected function _error($message = 'core.api.error.general', $data = null)
    {
        $this->_response->result     = 0;
        $this->_response->data       = $data;
        $this->_response->messages[] = new Tiger_Model_MessageObject($message, 'error');
    }

    protected function _formErrors(Zend_Form $form)
    {
        $this->_response->result     = 0;
        $this->_response->form       = $form->getMessages();
        $this->_response->messages[] = new Tiger_Model_MessageObject('core.api.error.form', 'error');
    }

    // ----- authorization -----------------------------------------------------

    /**
     * ACL gate — never a raw role-string compare. Resolves the caller's role from
     * the authenticated identity (guest when unauthenticated) and asks Zend_Acl.
     * Defaults the resource to the calling service's own class.
     *
     * NOTE (Tiger evolution): the role will be read from the caller's CURRENT
     * org_user membership (role-on-membership) once Tiger_Service_Acl lands; the
     * gate signature stays the same, only where `role` comes from sharpens.
     *
     * @param string|null $resource  defaults to static::class
     * @param string|null $privilege null = resource-level
     */
    protected function _isAdmin($resource = null, $privilege = null)
    {
        if (!Zend_Registry::isRegistered('Zend_Acl')) {
            return false;
        }
        $acl      = Zend_Registry::get('Zend_Acl');
        $identity = Zend_Auth::getInstance()->getIdentity();
        $role     = $identity->role ?? 'guest';
        $resource = $resource ?? static::class;

        if (!$acl->has($resource)) {
            return false;   // an ungoverned resource can't grant access
        }
        return $acl->isAllowed($role, $resource, $privilege);
    }

    // ----- data / transactions ----------------------------------------------

    /** The default DB adapter, or a clear failure if none is configured. */
    protected function _db()
    {
        $db = Zend_Db_Table_Abstract::getDefaultAdapter();
        if (!$db) {
            throw new RuntimeException('No database adapter is configured.');
        }
        return $db;
    }

    /**
     * Run $work inside a DB transaction: begin -> $work($db) -> commit. Any
     * throwable rolls back and re-throws, so the caller's catch turns it into an
     * error response. This is the canonical Tiger service flow — validate a form
     * FIRST, then wrap the mutation:
     *
     *   public function create(array $params): void
     *   {
     *       $form = new Some_Form();
     *       if (!$form->isValid($params)) { $this->_formErrors($form); return; }   // isValidPartial() for PATCH
     *       try {
     *           $id = $this->_transaction(function ($db) use ($params) {
     *               // ... inserts/updates; throw to abort + roll back ...
     *               return $newId;
     *           });
     *           $this->_success(['id' => $id], 'some.success');
     *       } catch (Throwable $e) {
     *           $this->_error(APPLICATION_ENV !== 'production' ? $e->getMessage() : 'core.api.error.general');
     *       }
     *   }
     *
     * @param  callable $work receives the adapter; its return value is passed back
     * @return mixed
     */
    protected function _transaction(callable $work)
    {
        $db = $this->_db();
        $db->beginTransaction();
        try {
            $result = $work($db);
            $db->commit();
            return $result;
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    // ----- DataTables (server-side processing) -------------------------------

    /**
     * Normalize a DataTables server-side-processing request into a tidy shape:
     * `draw`, `start`, `length` (clamped to [1, $maxLength]), a single `search`
     * string, and `order` as [ ['column'=>int, 'dir'=>'ASC'|'DESC'], … ]. A grid's
     * `datatable` action reads these, runs its count + page queries, and answers
     * with _dtResponse(). Part of the client/server paradigm: data grids fetch rows
     * from /api, they are never server-rendered (see AGENTS.md, WEBSERVICES.md §5).
     *
     * @return array{draw:int,start:int,length:int,search:string,order:array}
     */
    protected function _dtParams(array $params, int $maxLength = 100): array
    {
        $length = (int) ($params['length'] ?? 25);
        $length = $length === -1 ? $maxLength : max(1, min($maxLength, $length));

        $search = '';
        if (isset($params['search'])) {
            $search = is_array($params['search'])
                ? (string) ($params['search']['value'] ?? '')
                : (string) $params['search'];
        }

        $order = [];
        foreach ((array) ($params['order'] ?? []) as $o) {
            if (!is_array($o) || !isset($o['column'])) { continue; }
            $order[] = [
                'column' => (int) $o['column'],
                'dir'    => (strtolower((string) ($o['dir'] ?? 'asc')) === 'desc') ? 'DESC' : 'ASC',
            ];
        }

        return [
            'draw'   => max(0, (int) ($params['draw'] ?? 0)),
            'start'  => max(0, (int) ($params['start'] ?? 0)),
            'length' => $length,
            'search' => trim($search),
            'order'  => $order,
        ];
    }

    /**
     * Emit the DataTables response envelope ({draw, recordsTotal, recordsFiltered,
     * data}) via the standard success response. The shared client helper
     * (tiger.datatable.js) unwraps the Tiger envelope into what DataTables consumes.
     * `$data` is structured rows only — never HTML; the client renders cells (and
     * gates controls off per-row permission flags the service puts on each row).
     */
    protected function _dtResponse(int $draw, int $recordsTotal, int $recordsFiltered, array $data, string $message = 'core.api.success'): void
    {
        $this->_success([
            'draw'            => $draw,
            'recordsTotal'    => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data'            => $data,
        ], $message);
    }

    // -------------------------------------------------------------------------

    public function getResponse()
    {
        return $this->_response;
    }
}
