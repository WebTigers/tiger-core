<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Controller_Plugin_PageDispatch — route unmatched URLs to CMS pages.
 *
 * At routeShutdown (after routing, before dispatch) this checks ONLY requests that
 * did not resolve to a real controller — i.e. ones that would otherwise 404. If the
 * path matches a published `page` row it hands off to PageController::viewAction; if
 * it matches a `page_redirect` it 301s; otherwise it leaves the request untouched so
 * ZF's ErrorHandler renders a clean 404.
 *
 * Real controllers (`/admin`, `/api`, `/auth`, the `/` landing) are dispatchable, so
 * they're never intercepted. This is the WordPress-style "if nothing else claims the
 * URL, ask the content store" fallback — but explicit and non-greedy.
 *
 * Runs after LocalePrefix (so the path is locale-stripped and LANG is set) and before
 * the authorization plugin (which then gates PageController — public in acl.ini).
 * Public pages resolve at global scope for now; per-tenant public sites (host -> org)
 * are a later addition.
 *
 * @api
 */
class Tiger_Controller_Plugin_PageDispatch extends Zend_Controller_Plugin_Abstract
{
    public function routeShutdown(Zend_Controller_Request_Abstract $request)
    {
        if (!$request instanceof Zend_Controller_Request_Http) {
            return;
        }

        // Only step in when nothing real will handle this request.
        $front = Zend_Controller_Front::getInstance();
        if ($front->getDispatcher()->isDispatchable($request)) {
            return;
        }

        $slug = trim($request->getPathInfo(), '/');
        if ($slug === '') {
            return;   // the root belongs to IndexController (the landing)
        }
        $locale = defined('LANG') ? LANG : 'en';
        $orgId  = '';   // global public pages for now (host->org mapping is future)

        try {
            // Only real pages answer at the site root — articles/posts route under /blog.
            $page = (new Tiger_Model_Page())->resolveBySlug($slug, $locale, $orgId, Tiger_Model_Page::TYPE_PAGE);
            if ($page) {
                $request->setModuleName($front->getDispatcher()->getDefaultModule())
                        ->setControllerName('page')
                        ->setActionName('view')
                        ->setParam('cms_page_id', $page->page_id);
                return;
            }

            // A moved slug? 301 to its current location.
            $redirect = (new Tiger_Model_PageRedirect())->findFrom($slug, $locale, $orgId);
            if ($redirect) {
                Zend_Controller_Action_HelperBroker::getStaticHelper('redirector')
                    ->setCode((int) $redirect->code)
                    ->gotoUrlAndExit('/' . ltrim($redirect->to_slug, '/'));
            }
        } catch (Throwable $e) {
            // no DB / no page table yet — leave it to the 404 path
        }
        // no page, no redirect -> untouched -> ErrorHandler 404
    }
}
