<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Blog;

use Blog_IndexController;
use Blog_Model_Post;
use Blog_PostController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\ModuleControllerTestCase;
use Tiger_Model_Page;
use Zend_Controller_Action_Exception;

/**
 * The two Blog controllers:
 *   - Index (public) — the blog front-end (listing, single article, category/tag archives, RSS feed).
 *     Thin: it resolves data from Blog_Model_Post/Taxonomy and hands it to the view; a missing
 *     article/term 404s.
 *   - Post (admin)   — the authoring surface: the DataTables list shell + the editor (create/edit).
 *
 * The harness dispatches each action rendering-off and asserts the view model / response.
 */
#[CoversClass(Blog_IndexController::class)]
#[CoversClass(Blog_PostController::class)]
final class ControllersTest extends ModuleControllerTestCase
{
    /** Seed a published article row (page-backed). Returns the page_id. */
    private function seedArticle(array $overrides = []): string
    {
        return (new Tiger_Model_Page())->insert(array_merge([
            'type'         => Blog_Model_Post::TYPE_ARTICLE,
            'org_id'       => 'org-test',
            'locale'       => 'en',
            'title'        => 'Hello World',
            'slug'         => 'hello-world',
            'body'         => 'Body copy.',
            'format'       => Tiger_Model_Page::FORMAT_HTML,
            'status'       => Blog_Model_Post::STATUS_PUBLISHED,
            'published_at' => '2026-01-01 00:00:00',
            'meta'         => json_encode(Blog_Model_Post::META_DEFAULTS),
        ], $overrides));
    }

    // ----- public front-end -----------------------------------------------------------------------

    #[Test]
    public function public_index_lists_articles(): void
    {
        $this->dispatchAction(Blog_IndexController::class, 'index', [], 'GET');

        $view = $this->controller()->view;
        $this->assertSame('Blog', (string) $view->title);
        $this->assertSame('Latest', (string) $view->heading);
        $this->assertIsArray($view->posts);
    }

    #[Test]
    public function public_view_404s_an_unknown_article(): void
    {
        $this->expectException(Zend_Controller_Action_Exception::class);
        $this->dispatchAction(Blog_IndexController::class, 'view', ['slug' => 'no-such-article'], 'GET');
    }

    #[Test]
    public function public_category_404s_an_unknown_term(): void
    {
        $this->expectException(Zend_Controller_Action_Exception::class);
        $this->dispatchAction(Blog_IndexController::class, 'category', ['slug' => 'ghost'], 'GET');
    }

    #[Test]
    public function public_feed_sets_the_rss_content_type(): void
    {
        $res = $this->dispatchAction(Blog_IndexController::class, 'feed', [], 'GET');

        $ct = '';
        foreach ($res->getHeaders() as $h) {
            if (strcasecmp($h['name'], 'Content-Type') === 0) { $ct = (string) $h['value']; }
        }
        $this->assertStringContainsString('application/rss+xml', $ct);
        $this->assertIsArray($this->controller()->view->posts);
    }

    // ----- admin authoring ------------------------------------------------------------------------

    #[Test]
    public function admin_index_renders_the_datatables_shell(): void
    {
        $this->loginAs('admin');
        $this->dispatchAction(Blog_PostController::class, 'index', [], 'GET');

        $view = $this->controller()->view;
        $this->assertStringContainsString('Articles', (string) $view->title);
        $this->assertTrue((bool) $view->useDataTables);
    }

    #[Test]
    public function admin_edit_renders_a_blank_editor_for_a_new_article(): void
    {
        $this->loginAs('admin');
        $this->dispatchAction(Blog_PostController::class, 'edit', [], 'GET');

        $view = $this->controller()->view;
        $this->assertStringContainsString('New Article', (string) $view->title);
        $this->assertInstanceOf(\Blog_Form_Post::class, $view->form);
        $this->assertNull($view->post);
    }

    #[Test]
    public function admin_edit_prefills_the_editor_for_an_existing_article(): void
    {
        $id = $this->seedArticle(['title' => 'My Draft', 'slug' => 'my-draft']);
        $this->loginAs('admin');
        $this->dispatchAction(Blog_PostController::class, 'edit', ['id' => $id], 'GET');

        $view = $this->controller()->view;
        $this->assertStringContainsString('Edit Article', (string) $view->title);
        $this->assertNotNull($view->post);
        // recentForPage() returns a Zend_Db_Table_Rowset (Traversable), not a plain array.
        $this->assertInstanceOf(\Traversable::class, $view->versions);
        $this->assertSame('My Draft', $view->form->getValues()['title']);
    }
}
