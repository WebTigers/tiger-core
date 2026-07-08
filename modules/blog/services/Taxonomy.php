<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Blog_Service_Taxonomy — /api reads for the category/tag pickers + archives.
 *
 * Read-only helper the article editor calls to populate its category/tag autocompletes
 * (and the front-end uses for term clouds). Terms are created lazily on post save
 * (Blog_Model_Taxonomy::findOrCreate), so there's no write path here yet — a dedicated
 * term-admin can come later. Admin-gated like the rest of the module.
 */
class Blog_Service_Taxonomy extends Tiger_Service_Service
{
    /** Terms in a vocabulary ({id, name, slug}) for a Select2-style picker. */
    public function listTerms(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }

        $vocab = ($params['vocabulary'] ?? '') === Blog_Model_Taxonomy::VOCAB_CATEGORY
            ? Blog_Model_Taxonomy::VOCAB_CATEGORY : Blog_Model_Taxonomy::VOCAB_TAG;
        $locale = (string) ($params['locale'] ?? 'en');
        $orgId  = $this->_orgId();

        $terms = [];
        foreach ((new Blog_Model_Taxonomy())->listVocabulary($vocab, $locale, $orgId) as $t) {
            $terms[] = ['id' => $t->taxonomy_id, 'name' => $t->name, 'slug' => $t->slug];
        }
        $this->_success(['terms' => $terms]);
    }

    protected function _orgId(): string
    {
        $idn = Zend_Auth::getInstance()->getIdentity();
        return ($idn && !empty($idn->org_id)) ? (string) $idn->org_id : '';
    }
}
