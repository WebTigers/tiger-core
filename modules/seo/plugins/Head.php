<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Seo_Plugin_Head — populates the head registry for CMS pages, which dispatch through PageDispatch
 * (it resolves the slug → a `page` row and sets `cms_page_id`). Runs at preDispatch (after routing), so
 * the head containers are filled before the view + layout render. Fail-open: SEO never breaks a request.
 *
 * Blog articles render via their own controller (no `cms_page_id`) and call Seo_Service_Head directly.
 */
class Seo_Plugin_Head extends Zend_Controller_Plugin_Abstract
{
    /**
     * @param  Zend_Controller_Request_Abstract $request
     * @return void
     */
    public function preDispatch(Zend_Controller_Request_Abstract $request)
    {
        $pageId = (string) $request->getParam('cms_page_id', '');
        if ($pageId === '') {
            return;   // not a CMS page dispatch
        }
        try {
            $page = (new Tiger_Model_Page())->findById($pageId);
            if ($page) {
                Seo_Service_Head::forRow($page, $request);
            }
        } catch (Throwable $e) {
            // fail-open — a broken SEO lookup must never take down a page render
        }
    }
}
