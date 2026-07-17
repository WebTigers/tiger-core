<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Blog_Model_Taxonomy — categories, tags, and the post↔term join (`page_taxonomy`).
 *
 * Terms are org-scoped ('' = global; a tenant term overrides) and language-only, just
 * like `page`. `vocabulary` discriminates category | tag. The join table has no model
 * of its own — it's a plain link this model maintains via forPage()/syncPage().
 *
 * @api
 */
class Blog_Model_Taxonomy extends Tiger_Model_Table
{
    protected $_name    = 'taxonomy';
    protected $_primary = 'taxonomy_id';

    const VOCAB_CATEGORY = 'category';
    const VOCAB_TAG      = 'tag';

    const JOIN = 'page_taxonomy';

    /**
     * Active terms in a vocabulary (for pickers + archives), tenant then global.
     *
     * @param  string $vocabulary the vocabulary (category | tag)
     * @param  string $locale     the content locale
     * @param  string $orgId      the tenant org id ('' = global)
     * @return iterable the matching term rows
     */
    public function listVocabulary($vocabulary, $locale = 'en', $orgId = '')
    {
        return $this->fetchAll(
            $this->activeSelect()
                ->where('vocabulary = ?', (string) $vocabulary)
                ->where('locale = ?', (string) $locale)
                ->where('org_id IN (?)', $this->_orgScope($orgId))
                ->order(['sort_order ASC', 'name ASC'])
        );
    }

    /**
     * Resolve a term by name within a vocabulary, creating it if absent. Returns the
     * taxonomy_id. Lets the editor accept free-typed categories/tags and mint terms on
     * the fly. Matches on the derived slug so "Web Dev" and "web-dev" are the same term.
     *
     * @param  string $vocabulary the vocabulary (category | tag)
     * @param  string $name       the free-typed term name
     * @param  string $locale     the content locale
     * @param  string $orgId      the tenant org id ('' = global)
     * @return string|null the taxonomy_id, or null when the name is empty
     */
    public function findOrCreate($vocabulary, $name, $locale = 'en', $orgId = '')
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }
        $slug = $this->slugify($name);

        $existing = $this->fetchRow(
            $this->activeSelect()
                ->where('vocabulary = ?', (string) $vocabulary)
                ->where('slug = ?', $slug)
                ->where('locale = ?', (string) $locale)
                ->where('org_id = ?', (string) $orgId)
                ->limit(1)
        );
        if ($existing) {
            return $existing->taxonomy_id;
        }

        return $this->insert([
            'org_id'     => (string) $orgId,
            'vocabulary' => (string) $vocabulary,
            'term_key'   => $slug,
            'locale'     => (string) $locale,
            'name'       => $name,
            'slug'       => $slug,
            'status'     => 'active',
        ]);
    }

    /**
     * A single term by slug within a vocabulary (tenant over global) — for archive pages.
     *
     * @param  string $vocabulary the vocabulary (category | tag)
     * @param  string $slug       the term slug
     * @param  string $locale     the content locale
     * @param  string $orgId      the tenant org id ('' = global)
     * @return Zend_Db_Table_Row_Abstract|null the term row, or null if none
     */
    public function resolveTermBySlug($vocabulary, $slug, $locale = 'en', $orgId = '')
    {
        return $this->fetchRow(
            $this->activeSelect()
                ->where('vocabulary = ?', (string) $vocabulary)
                ->where('slug = ?', (string) $slug)
                ->where('locale = ?', (string) $locale)
                ->where('org_id IN (?)', $this->_orgScope($orgId))
                ->order('org_id DESC')
                ->limit(1)
        );
    }

    /**
     * The page_ids linked to a term (its posts), for the archive listing.
     *
     * @param  string $taxonomyId the term's taxonomy_id
     * @return array the linked page_ids, in author order
     */
    public function pageIdsForTerm($taxonomyId)
    {
        $db = $this->getAdapter();
        return $db->fetchCol(
            $db->select()->from(self::JOIN, ['page_id'])
                ->where('taxonomy_id = ?', (string) $taxonomyId)
                ->order('sort_order ASC')
        );
    }

    /**
     * The terms linked to a page (optionally one vocabulary), in author order.
     *
     * @param  string      $pageId     the page's page_id
     * @param  string|null $vocabulary limit to this vocabulary, or null for all
     * @return array the linked term rows
     */
    public function forPage($pageId, $vocabulary = null)
    {
        $db  = $this->getAdapter();
        $sel = $db->select()
            ->from(['t' => $this->_name])
            ->join(['pt' => self::JOIN], 't.taxonomy_id = pt.taxonomy_id', ['sort_order'])
            ->where('pt.page_id = ?', (string) $pageId)
            ->where('t.deleted = 0')
            ->order('pt.sort_order ASC');
        if ($vocabulary !== null) {
            $sel->where('t.vocabulary = ?', (string) $vocabulary);
        }
        return $db->fetchAll($sel);
    }

    /**
     * Replace a page's term links with $taxonomyIds (order preserved). Deletes the old
     * links and inserts the new set — the join carries no history, so a full rewrite is
     * the simplest correct sync. Ignores empty ids.
     *
     * @param  string $pageId      the page's page_id
     * @param  array  $taxonomyIds the term ids to link (order preserved)
     * @return void
     */
    public function syncPage($pageId, array $taxonomyIds)
    {
        $db = $this->getAdapter();
        $db->delete(self::JOIN, $db->quoteInto('page_id = ?', (string) $pageId));

        $now = date('Y-m-d H:i:s');
        $i   = 0;
        foreach ($taxonomyIds as $tid) {
            $tid = trim((string) $tid);
            if ($tid === '') { continue; }
            $db->insert(self::JOIN, [
                'page_id'     => (string) $pageId,
                'taxonomy_id' => $tid,
                'sort_order'  => $i++,
                'created_at'  => $now,
            ]);
        }
    }

    /**
     * Post counts per term in a vocabulary (published only) — for archives/clouds.
     *
     * @param  string $vocabulary the vocabulary (category | tag)
     * @param  string $locale     the content locale
     * @param  string $orgId      the tenant org id ('' = global)
     * @return array the term rows with a published-post count
     */
    public function counts($vocabulary, $locale = 'en', $orgId = '')
    {
        $db = $this->getAdapter();
        $sel = $db->select()
            ->from(['t' => $this->_name], ['taxonomy_id', 'name', 'slug', 'n' => new Zend_Db_Expr('COUNT(pt.page_id)')])
            ->joinLeft(['pt' => self::JOIN], 't.taxonomy_id = pt.taxonomy_id', [])
            ->joinLeft(['p' => 'page'], "pt.page_id = p.page_id AND p.deleted = 0 AND p.status = 'published'", [])
            ->where('t.vocabulary = ?', (string) $vocabulary)
            ->where('t.locale = ?', (string) $locale)
            ->where('t.org_id IN (?)', $this->_orgScope($orgId))
            ->where('t.deleted = 0')
            ->group('t.taxonomy_id')
            ->order(['n DESC', 't.name ASC']);
        return $db->fetchAll($sel);
    }

    /**
     * lowercase, hyphen-joined ascii slug.
     *
     * @param  string $text the source text
     * @return string the slugified value
     */
    public function slugify($text)
    {
        $text = strtolower(trim((string) $text));
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        return trim((string) $text, '-');
    }

    /** org scope: tenant row overrides shared (''); a blank org means "the current site". Mirrors Tiger_Model_Page. */
    protected function _orgScope($orgId)
    {
        $orgId = (string) $orgId;
        if ($orgId === '') {
            $orgId = Tiger_Model_Org::siteOrgId();
        }
        return $orgId !== '' ? [$orgId, ''] : [''];
    }
}
