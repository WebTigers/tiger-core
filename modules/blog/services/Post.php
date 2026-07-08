<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Blog_Service_Post — the /api service for article authoring (datatable / save /
 * delete / restore). Thin + admin-gated; the write defers to Blog_Model_Post (which
 * extends the transactional Tiger_Model_Page: save() snapshots a version and 301s a
 * changed slug), then syncs the post's taxonomy links.
 *
 * ACL resource = this class (Blog_Service_Post), granted admin+ in configs/acl.ini;
 * the gateway also privilege-checks the method name.
 */
class Blog_Service_Post extends Tiger_Service_Service
{
    /** DataTables source for the article list (type=article), rows as structured data. */
    public function datatable(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }

        $dt    = $this->_dtParams($params);
        $model = new Blog_Model_Post();
        $data  = $model->articleDatatable([
            'search'   => $dt['search'],
            'status'   => in_array(($params['status'] ?? ''), [Blog_Model_Post::STATUS_DRAFT, Blog_Model_Post::STATUS_PUBLISHED, Blog_Model_Post::STATUS_ARCHIVED], true) ? (string) $params['status'] : '',
            'orderCol' => isset($dt['order'][0]) ? $dt['order'][0]['column'] : -1,
            'orderDir' => isset($dt['order'][0]) ? $dt['order'][0]['dir'] : '',
            'offset'   => $dt['start'],
            'limit'    => $dt['length'],
        ]);

        $canEdit   = $this->_isAdmin(static::class, 'save');
        $canDelete = $this->_isAdmin(static::class, 'delete');

        $rows = [];
        foreach ($data['rows'] as $r) {
            $meta  = $model->unpackMeta($r['meta']);
            $stamp = (string) ($r['updated_at'] ?: $r['created_at']);
            $rows[] = [
                'page_id'    => $r['page_id'],
                'title'      => ($r['title'] !== null && $r['title'] !== '') ? $r['title'] : '(untitled)',
                'handle'     => ($r['slug'] !== null && $r['slug'] !== '') ? '/blog/' . $r['slug'] : '',
                'kicker'     => (string) $meta['kicker'],
                'locale'     => $r['locale'],
                'status'     => $r['status'],
                'scheduled'  => ($r['status'] === 'published' && $r['published_at'] && strtotime((string) $r['published_at']) > time()),
                'reading'    => (int) $meta['reading_time'],
                'updated'    => substr($stamp, 0, 16),
                'can_edit'   => $canEdit,
                'can_delete' => $canDelete,
            ];
        }

        $this->_dtResponse($dt['draw'], $data['total'], $data['filtered'], $rows);
    }

    /** Create or update an article (insert when post_id is empty). */
    public function save(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }

        $form = new Blog_Form_Post();
        if (!$form->isValid($params)) { $this->_formErrors($form); return; }
        $v = $form->getValues();

        $postId = !empty($params['post_id']) ? (string) $params['post_id'] : null;
        $locale = (string) $v['locale'];
        $orgId  = $this->_orgId();

        // Slug + stable key from the slug, else the title.
        $slugIn = trim((string) $v['slug']);
        $slug   = $this->_slugify($slugIn !== '' ? $slugIn : (string) $v['title']);
        if ($slug === '') { $this->_error('blog.error.slug'); return; }
        // These paths are routed to the admin/archives/feed under /blog, so an article can't
        // own them (it would be unreachable). Reject with a clear message.
        if (in_array($slug, ['post', 'category', 'tag', 'feed', 'index'], true)) {
            $this->_error('blog.error.slug_reserved'); return;
        }

        $fields = [
            'page_key'         => $slug,
            'slug'             => $slug,
            'locale'           => $locale,
            'title'            => $v['title'],
            'body'             => (string) $v['body'],
            'status'           => $v['status'],
            'published_at'     => trim((string) $v['published_at']),
            'kicker'           => $v['kicker'],
            'subtitle'         => $v['subtitle'],
            'preamble'         => $v['preamble'],
            'excerpt'          => $v['excerpt'],
            'feature_media_id' => $v['feature_media_id'],
            'author_id'        => trim((string) $v['author_id']) !== '' ? $v['author_id'] : $this->_userId(),
            'allow_comments'   => !empty($v['allow_comments']),
            'seo_title'        => $v['seo_title'],
            'seo_description'  => $v['seo_description'],
            'og_image_id'      => $v['og_image_id'],
            'canonical'        => $v['canonical'],
        ];

        try {
            $model = new Blog_Model_Post();
            $id    = $model->saveArticle($fields, $postId);

            // Taxonomy: resolve comma-typed category/tag names to term ids (creating any
            // new ones), then rewrite the post's links. A follow-on write to the page
            // save — the join has no version history.
            $tax    = new Blog_Model_Taxonomy();
            $termIds = array_merge(
                $this->_resolveTerms($tax, Blog_Model_Taxonomy::VOCAB_CATEGORY, $v['categories'], $locale, $orgId),
                $this->_resolveTerms($tax, Blog_Model_Taxonomy::VOCAB_TAG, $v['tags'], $locale, $orgId)
            );
            $tax->syncPage($id, $termIds);

            $this->_success(['page_id' => $id], 'blog.post.saved', '/blog/post');
        } catch (Throwable $e) {
            $this->_error(APPLICATION_ENV !== 'production' ? $e->getMessage() : 'core.api.error.general');
        }
    }

    /** Soft-delete an article (recoverable). */
    public function delete(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }
        $id = !empty($params['post_id']) ? (string) $params['post_id'] : '';
        if ($id === '') { $this->_error('core.api.error.general'); return; }

        try {
            (new Blog_Model_Post())->softDelete(['page_id = ?' => $id]);
            $this->_success(['page_id' => $id], 'blog.post.deleted', '/blog/post');
        } catch (Throwable $e) {
            $this->_error(APPLICATION_ENV !== 'production' ? $e->getMessage() : 'core.api.error.general');
        }
    }

    /** Restore an article to a prior version (current content snapshotted first). */
    public function restore(array $params): void
    {
        if (!$this->_isAdmin()) { $this->_error('core.api.error.not_allowed'); return; }
        $id      = !empty($params['post_id']) ? (string) $params['post_id'] : '';
        $version = isset($params['version']) ? (int) $params['version'] : 0;
        if ($id === '' || $version < 1) { $this->_error('core.api.error.general'); return; }

        try {
            (new Blog_Model_Post())->restoreVersion($id, $version);
            $this->_success(['page_id' => $id], 'blog.post.restored', '/blog/post/edit/id/' . $id);
        } catch (Throwable $e) {
            $this->_error(APPLICATION_ENV !== 'production' ? $e->getMessage() : 'core.api.error.general');
        }
    }

    /** Split a comma-separated term string and resolve each to a taxonomy_id. */
    protected function _resolveTerms(Blog_Model_Taxonomy $tax, $vocabulary, $csv, $locale, $orgId): array
    {
        $ids = [];
        foreach (explode(',', (string) $csv) as $name) {
            $tid = $tax->findOrCreate($vocabulary, $name, $locale, $orgId);
            if ($tid) { $ids[] = $tid; }
        }
        return $ids;
    }

    protected function _orgId(): string
    {
        $idn = Zend_Auth::getInstance()->getIdentity();
        return ($idn && !empty($idn->org_id)) ? (string) $idn->org_id : '';
    }

    protected function _userId(): string
    {
        $idn = Zend_Auth::getInstance()->getIdentity();
        return ($idn && !empty($idn->user_id)) ? (string) $idn->user_id : '';
    }

    protected function _slugify($text): string
    {
        $text = strtolower(trim((string) $text));
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        return trim((string) $text, '-');
    }
}
