<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Support;

use Zend_Controller_Action_HelperBroker;
use Zend_Controller_Front;
use Zend_Controller_Request_Http;
use Zend_Controller_Response_Http;
use Zend_Layout;
use Zend_View;

/**
 * ControllerTestCase — a minimal ZF1 dispatch harness for covering the default-namespace controllers
 * (`core/controllers/*`) and any module controller, WITHOUT booting the whole `Tiger_Application`.
 *
 * The harness dispatches a controller ACTION with view-rendering turned OFF and returns the response +
 * the controller instance for inspection. Turning off the ViewRenderer is deliberate: the goal is
 * controller-ACTION coverage — the branch logic, the view-var assignment, the `_json` body, the
 * redirect / `_forward` decision — not the `.phtml` output (view scripts aren't PHP classes and aren't
 * in the coverage source set). So we skip the theme/layout/view-helper stack a full render would need,
 * and assert on what the action DID: `$response->getBody()` (for `_json`/`echo`), the redirect header,
 * the forwarded request target, and `controller()->view->*`.
 *
 * A real front controller is configured (core + module controller dirs, a view with the core script
 * path) so `$this->_helper->{viewRenderer,layout,redirector}` resolve exactly as in a live request; the
 * redirector's `gotoUrl()` sets the response's Location (no process exit), so redirects are assertable.
 * Extends IntegrationTestCase, so the DB + per-test transaction + `login()`/`loginAs()` are all available
 * (a controller reads the auth identity + the real ACL just like in production).
 *
 * @internal test infrastructure.
 */
abstract class ControllerTestCase extends IntegrationTestCase
{
    /** @var Zend_Controller_Response_Http|null the response from the last dispatch */
    protected $response;

    /** @var \Zend_Controller_Action|null the controller instance from the last dispatch */
    protected $controller;

    /** @var string captured stdout the action echoed (ApiController writes JSON with echo, not setBody) */
    protected $echoed = '';

    protected function setUp(): void
    {
        parent::setUp();

        $front = Zend_Controller_Front::getInstance();
        $front->resetInstance();
        $front->addControllerDirectory(TIGER_CORE_PATH . '/core/controllers', 'default');
        foreach ([TIGER_CORE_PATH . '/modules', APPLICATION_PATH . '/modules'] as $md) {
            if (is_dir($md)) { $front->addModuleDirectory($md); }
        }
        $front->returnResponse(true);
        $front->setResponse(new Zend_Controller_Response_Http());

        // A view with the core script path + the Tiger view-helper prefix, so an action that touches the
        // view (assigns vars, or renders when a test opts in) has a working Zend_View behind it.
        $view = new Zend_View();
        $view->addScriptPath(TIGER_CORE_PATH . '/core/views/scripts');
        $view->addHelperPath(TIGER_CORE_PATH . '/library/Tiger/View/Helper', 'Tiger_View_Helper');

        Zend_Controller_Action_HelperBroker::resetHelpers();
        $vr = Zend_Controller_Action_HelperBroker::getStaticHelper('viewRenderer');
        $vr->setView($view);
        $vr->setNoRender(true);                              // cover action logic, not .phtml rendering

        Zend_Layout::startMvc()->setView($view)->disableLayout();
    }

    protected function tearDown(): void
    {
        $_POST = $_GET = [];
        unset($_SERVER['REQUEST_METHOD']);
        Zend_Controller_Front::getInstance()->resetInstance();
        Zend_Layout::resetMvcInstance();
        Zend_Controller_Action_HelperBroker::resetHelpers();
        parent::tearDown();
    }

    /**
     * Instantiate a controller and dispatch ONE action, view-rendering off. Captures any echoed output,
     * stores the controller + response, and returns the response.
     *
     * @param  string $class      the controller class (e.g. ApiController, Access_UserController)
     * @param  string $action     the bare action name (e.g. 'index' → indexAction)
     * @param  array  $params     request params (merged POST+GET+route, as ZF1 delivers them)
     * @param  string $method     the HTTP method to report (GET|POST|…)
     * @return Zend_Controller_Response_Http the response after dispatch (body / redirect / code)
     */
    protected function dispatchAction(string $class, string $action, array $params = [], string $method = 'GET'): Zend_Controller_Response_Http
    {
        // ZF1 reads the HTTP method from $_SERVER and POST/GET data from the superglobals, not a setter.
        $method = strtoupper($method);
        $_SERVER['REQUEST_METHOD'] = $method;
        if ($method === 'POST') { $_POST = $params; $_GET = []; } else { $_GET = $params; $_POST = []; }

        $request = new Zend_Controller_Request_Http();
        $request->setModuleName('default')
                ->setControllerName('test')
                ->setActionName($action)
                ->setParams($params)
                ->setDispatched(true);

        $response = new Zend_Controller_Response_Http();
        Zend_Controller_Front::getInstance()->setRequest($request)->setResponse($response);

        $class = (string) $class;
        $this->controller = new $class($request, $response, []);

        // The ViewRenderer re-arms per controller, so disable rendering on THIS controller's helper
        // right before dispatch — we cover the action body, not the .phtml (an action that self-disables,
        // like ApiController, is unaffected). A test that wants a real render can re-enable it.
        $this->controller->getHelper('viewRenderer')->setNoRender(true);

        ob_start();
        try {
            $this->controller->dispatch($action . 'Action');
        } finally {
            $this->echoed = (string) ob_get_clean();
        }

        $this->response = $response;
        return $response;
    }

    /** The controller instance from the last dispatch (to read `->view->*`). */
    protected function controller()
    {
        return $this->controller;
    }

    /** The decoded JSON the last action produced — whether it used `_json` (setBody) or `echo`. */
    protected function jsonResponse(): array
    {
        $body = $this->response && $this->response->getBody() !== '' ? $this->response->getBody() : $this->echoed;
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** The Location header set by a redirector `gotoUrl()`, or '' if the action didn't redirect. */
    protected function redirectLocation(): string
    {
        if (!$this->response) { return ''; }
        foreach ($this->response->getHeaders() as $h) {
            if (strcasecmp($h['name'], 'Location') === 0) { return (string) $h['value']; }
        }
        return '';
    }

    /** Where an action `_forward`ed to (module/controller/action), read off the mutated request. */
    protected function forwardedTo(): array
    {
        $r = $this->controller ? $this->controller->getRequest() : null;
        return $r ? [
            'module'     => $r->getModuleName(),
            'controller' => $r->getControllerName(),
            'action'     => $r->getActionName(),
            'dispatched' => $r->isDispatched(),
        ] : [];
    }
}
