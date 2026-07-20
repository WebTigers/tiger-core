<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Search_IndexController — the public /search results page (server-rendered, so search works without
 * JS and results are crawlable). The ⌘K launcher hits Search_Service_Search over /api for the live
 * dropdown; hitting Enter (or arriving without JS) lands here. Renders in the active theme's public
 * layout via Tiger_Controller_Action. Guest-allowed (see configs/acl.ini).
 */
class Search_IndexController extends Tiger_Controller_Action
{
    public function indexAction()
    {
        // Accept /search/q/<term> (path-style, per Tiger convention) or ?q=<term>.
        $term = trim((string) $this->getParam('q', ''));

        $role = 'guest';
        try {
            if (Zend_Auth::getInstance()->hasIdentity()) {
                $idn = Zend_Auth::getInstance()->getIdentity();
                if (is_object($idn) && !empty($idn->role)) { $role = (string) $idn->role; }
            }
        } catch (Throwable $e) {}

        $results = $term !== '' ? Tiger_Search::query($term, ['role' => $role, 'limit' => 20]) : null;

        $this->view->term    = $term;
        $this->view->results = $results;
        $this->view->title   = ($term !== '' ? 'Search: ' . $term : 'Search');
    }
}
