<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Search_Service_Search — the public /api behind the ⌘K launcher and the /search results page.
 *
 * A thin guest-allowed wrapper over Tiger_Search::query(): it fans out across every registered
 * provider (pages, blog articles, and whatever other modules register), ACL-filtered to the caller,
 * and returns grouped + flat-ranked results. Providers self-limit to content the requester may see,
 * so a guest only ever gets published, in-scope hits.
 *
 * @api
 */
class Search_Service_Search extends Tiger_Service_Service
{
    /**
     * Run a query across all registered search providers.
     *
     * @param  array $params q (the query), limit (per provider), only[] (restrict to provider keys)
     * @return void
     */
    public function query(array $params): void
    {
        $term = trim((string) ($params['q'] ?? $params['term'] ?? ''));
        if ($term === '') {
            $this->_success(['term' => '', 'total' => 0, 'groups' => [], 'results' => []]);
            return;
        }
        $res = Tiger_Search::query($term, [
            'role'  => $this->_role(),
            'limit' => isset($params['limit']) ? (int) $params['limit'] : 6,
            'only'  => isset($params['only']) ? (array) $params['only'] : null,
        ]);
        $this->_success($res);
    }

    /** The current requester's role (guest when anonymous) for provider ACL gating. */
    protected function _role(): string
    {
        try {
            if (class_exists('Zend_Auth') && Zend_Auth::getInstance()->hasIdentity()) {
                $idn = Zend_Auth::getInstance()->getIdentity();
                if (is_object($idn) && !empty($idn->role)) { return (string) $idn->role; }
            }
        } catch (Throwable $e) {}
        return 'guest';
    }
}
