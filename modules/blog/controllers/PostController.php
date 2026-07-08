<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Blog_PostController — the article authoring surface, in the PUMA admin shell.
 *
 * Two thin screens: a DataTables list of articles (index) and the Medium-style editor
 * (edit). Per the thin-controller rule this only READS and RENDERS — every mutation
 * goes through the /api service Blog_Service_Post (save/delete/restore). ACL-gated to
 * admin+ (modules/blog/configs/acl.ini) by the Authorization plugin.
 */
class Blog_PostController extends Tiger_Controller_Action
{
    /** @var Blog_Model_Post */
    protected $_posts;

    public function init()
    {
        parent::init();
        $this->_helper->layout()->setLayout('admin');
        $this->_posts = new Blog_Model_Post();
    }

    /** The article list — shell only; rows load over AJAX from Blog_Service_Post::datatable. */
    public function indexAction()
    {
        $this->view->title         = 'Articles — Tiger Admin';
        $this->view->useDataTables = true;
    }

    /** Create (no id) or edit (id) — renders the editor; saving is an /api call. */
    public function editAction()
    {
        $id   = (string) $this->getParam('id', '');
        $post = $id !== '' ? $this->_posts->findById($id) : null;

        $form = new Blog_Form_Post();
        if ($post) {
            $form->populate($this->_editValues($post));
        }

        $this->view->title    = ($post ? 'Edit' : 'New') . ' Article — Tiger Admin';
        $this->view->form     = $form;
        $this->view->post     = $post;
        $this->view->versions = $post ? (new Tiger_Model_PageVersion())->recentForPage($id) : [];
    }

    /** Map a post (page row) to editor form values — unpacking page.meta + its terms. */
    protected function _editValues($post)
    {
        $meta = $this->_posts->unpackMeta($post->meta);
        $tax  = new Blog_Model_Taxonomy();

        $names = function ($rows) { return implode(', ', array_map(function ($r) { return $r['name']; }, $rows)); };

        return [
            'post_id'          => $post->page_id,
            'title'            => $post->title,
            'slug'             => $post->slug,
            'status'           => $post->status,
            'locale'           => $post->locale,
            'published_at'     => $post->published_at,
            'body'             => $post->body,
            'kicker'           => $meta['kicker'],
            'subtitle'         => $meta['subtitle'],
            'preamble'         => $meta['preamble'],
            'excerpt'          => $meta['excerpt'],
            'feature_media_id' => $meta['feature_media_id'],
            'author_id'        => $meta['author_id'],
            'allow_comments'   => !empty($meta['allow_comments']),
            'seo_title'        => $meta['seo']['title'],
            'seo_description'  => $meta['seo']['description'],
            'og_image_id'      => $meta['seo']['og_image_id'],
            'canonical'        => $meta['seo']['canonical'],
            'categories'       => $names($tax->forPage($post->page_id, Blog_Model_Taxonomy::VOCAB_CATEGORY)),
            'tags'             => $names($tax->forPage($post->page_id, Blog_Model_Taxonomy::VOCAB_TAG)),
        ];
    }
}
