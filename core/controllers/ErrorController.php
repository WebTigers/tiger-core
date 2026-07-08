<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * ErrorController — clean 403 / 404 / 500 pages (default namespace).
 *
 * Entry points:
 *   errorAction     — ZF1's ErrorHandler plugin routes here on an uncaught
 *                     exception or a no-route/controller/action miss. We classify
 *                     404 vs 500, LOG every 500 via Tiger_Log (a 404 is expected —
 *                     skipped), and render a friendly page.
 *   forbiddenAction — Tiger_Controller_Plugin_Authorization forwards here for an
 *                     authenticated-but-denied request, so a 403 is a themed page
 *                     instead of a bare string.
 *
 * Non-production additionally gets a full debug bundle (exception + request + env)
 * in the view; production shows only the friendly message. Errors always render in
 * the safe public layout — never a controller's admin/custom layout, which may be
 * exactly what failed.
 */
class ErrorController extends Tiger_Controller_Action
{
    public function init()
    {
        parent::init();
        $this->_helper->layout()->setLayout('layout');
    }

    public function errorAction()
    {
        $errors = $this->_getParam('error_handler');

        if (!$errors instanceof ArrayObject) {
            $this->_render(500, 'Something went wrong.');
            return;
        }

        switch ($errors->type) {
            case Zend_Controller_Plugin_ErrorHandler::EXCEPTION_NO_ROUTE:
            case Zend_Controller_Plugin_ErrorHandler::EXCEPTION_NO_CONTROLLER:
            case Zend_Controller_Plugin_ErrorHandler::EXCEPTION_NO_ACTION:
                $code    = 404;
                $message = "That page doesn't exist.";
                break;
            default:
                // A controller can signal not-found explicitly by throwing a
                // Zend_Controller_Action_Exception with code 404.
                $ex = isset($errors->exception) ? $errors->exception : null;
                if ($ex instanceof Zend_Controller_Action_Exception && (int) $ex->getCode() === 404) {
                    $code    = 404;
                    $message = "That page doesn't exist.";
                } else {
                    $code    = 500;
                    $message = 'Something went wrong.';
                }
        }

        // Never let a 500 be silent — log the real cause (a 404 is expected).
        if ($code >= 500 && isset($errors->exception) && $errors->exception instanceof Throwable) {
            $this->_logException($errors->exception, $code, isset($errors->type) ? $errors->type : null);
        }

        $this->_render($code, $message, isset($errors->exception) ? $errors->exception : null);
    }

    /** 403 — the authorization plugin forwards here for authenticated-but-denied. */
    public function forbiddenAction()
    {
        $this->_render(403, "You don't have access to that.");
    }

    protected function _render($code, $message, $exception = null)
    {
        // Both errorAction and forbiddenAction share the one error/error.phtml view.
        $this->_helper->viewRenderer->setScriptAction('error');

        $this->getResponse()->setHttpResponseCode((int) $code);
        $this->view->statusCode = (int) $code;
        $this->view->message    = $message;
        $this->view->title      = $code . ' — Tiger';

        if (APPLICATION_ENV !== 'production' && $exception instanceof Throwable) {
            $req = $this->getRequest();
            $this->view->debug = [
                'exception' => [
                    'type'     => get_class($exception),
                    'message'  => $exception->getMessage(),
                    'code'     => $exception->getCode(),
                    'file'     => $exception->getFile(),
                    'line'     => $exception->getLine(),
                    'trace'    => $exception->getTraceAsString(),
                    'previous' => $exception->getPrevious()
                        ? get_class($exception->getPrevious()) . ': ' . $exception->getPrevious()->getMessage()
                        : null,
                ],
                'request' => [
                    'module'     => $req->getModuleName(),
                    'controller' => $req->getControllerName(),
                    'action'     => $req->getActionName(),
                    'method'     => $req->getMethod(),
                ],
                'server' => [
                    'uri'        => isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '',
                    'host'       => isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '',
                    'ip'         => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '',
                    'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '',
                    'php'        => phpversion(),
                    'env'        => APPLICATION_ENV,
                ],
            ];
        }
    }

    protected function _logException(Throwable $ex, $code, $type)
    {
        $req = $this->getRequest();
        Tiger_Log::error('Unhandled error rendered as ' . $code, [
            'type'  => $type,
            'exc'   => get_class($ex),
            'err'   => $ex->getMessage(),
            'where' => $ex->getFile() . ':' . $ex->getLine(),
            'uri'   => isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '',
            'route' => $req ? $req->getModuleName() . '/' . $req->getControllerName() . '/' . $req->getActionName() : '',
        ]);
    }
}
