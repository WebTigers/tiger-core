<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * ApiController — the single TIGER webservice gateway (default namespace).
 *
 * ALL /api traffic dispatches here (see the /api routes registered in
 * Tiger_Application_Bootstrap::_initApiRoutes). It stays dumb: hand the request to
 * Tiger_Ajax_ServiceFactory, then either _forward (controller mode) or JSON-encode
 * the ResponseObject (service mode). All logic/authorization lives in the factory
 * and the services. Ported from AskLevi's Core_ApiController.
 */
class ApiController extends Zend_Controller_Action
{
    /**
     * Configure the request for a machine endpoint: no layout, no view render, JSON headers.
     *
     * @return void
     */
    public function init()
    {
        $this->_helper->layout->disableLayout();
        $this->_helper->viewRenderer->setNoRender(true);
        $this->getResponse()->setHeader('Content-Type', 'application/json; charset=UTF-8');

        // /api is a machine endpoint — the body must be pure JSON, so suppress
        // display_errors for this request (a stray notice can never leak into the
        // response). Errors still hit the log.
        @ini_set('display_errors', '0');
    }

    /**
     * Dispatch the /api message: forward in controller mode, else emit the ResponseObject as JSON.
     *
     * @return void
     */
    public function indexAction()
    {
        $factory = new Tiger_Ajax_ServiceFactory($this->getRequest());

        // Controller mode: the factory ACL-checked; the target controller owns its
        // own response. Hand off through the normal dispatch loop — no JSON here.
        if ($fwd = $factory->getForward()) {
            $this->_forward($fwd['action'], $fwd['controller'], $fwd['module'], $fwd['params']);
            return;
        }

        // Service mode: emit the resolved ResponseObject as JSON.
        $this->getResponse()->setHttpResponseCode(200);
        echo json_encode($factory->getResponse(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * GET /api/openapi — the self-describing OpenAPI 3 catalog of the `/api` surface, generated from
     * the live services (`Tiger_OpenApi_Generator`). No route needed: the default `:controller/:action`
     * route dispatches it here. See WEBSERVICES.md §9.
     *
     * OPT-IN: 404 unless `tiger.api.discovery` is enabled — a shared-host CMS install shouldn't
     * publish its API surface; a SaaS building a public API turns it on. The Swagger UI that renders
     * this is a separate, unbundled add-on (not shipped with base Tiger).
     *
     * @return void
     */
    public function openapiAction()
    {
        if (!$this->_discoveryEnabled()) {
            $this->getResponse()->setHttpResponseCode(404);
            return;
        }
        $gen = new Tiger_OpenApi_Generator($this->_openapiInfo());
        $this->getResponse()->setHttpResponseCode(200);
        echo json_encode($gen->generate($gen->discover($this->_serviceDirs())), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Opt-in gate — `tiger.api.discovery` (default off). */
    protected function _discoveryEnabled()
    {
        try {
            $node = Zend_Registry::get('Zend_Config')->tiger->api->discovery ?? null;
        } catch (Throwable $e) {
            return false;
        }
        return in_array(strtolower((string) $node), ['1', 'true', 'yes', 'on'], true);
    }

    /** `services/` dirs of every discovered module (app + first-party core). */
    protected function _serviceDirs()
    {
        $dirs = [];
        foreach (Tiger_Module_Discovery::all() as $slug => $m) {
            $base = ($m['area'] === 'app' && defined('APPLICATION_PATH')) ? APPLICATION_PATH
                  : (defined('TIGER_CORE_PATH') ? TIGER_CORE_PATH : null);
            if ($base !== null) {
                $dirs[] = $base . '/modules/' . $slug . '/services';
            }
        }
        return $dirs;
    }

    /** OpenAPI `info` — the site name as the title when configured. */
    protected function _openapiInfo()
    {
        $title = 'Tiger API';
        try {
            $name = (string) (Zend_Registry::get('Zend_Config')->tiger->site->name ?? '');
            if ($name !== '') { $title = $name . ' API'; }
        } catch (Throwable $e) {
        }
        return ['title' => $title, 'version' => Tiger_Version::VERSION];
    }
}
