<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Blog;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use ReflectionProperty;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_Page;
use Tiger_Search;
use Tiger_Sitemap;
use Zend_Controller_Front;

/**
 * Blog_Bootstrap — the module's wiring: public /blog routes, the "articles" search-provider tap-in
 * (the reference Tiger_Search demo), and the sitemap/llms provider.
 *
 * Each _init* is invoked directly (the harness doesn't boot module bootstraps) and its effect is
 * asserted against the real registries: the router has the blog routes, Tiger_Search has the
 * articles provider whose closure resolves seeded articles, and Tiger_Sitemap::collect() runs the
 * blog provider closure over published articles (excerpt/desc unpacked from page.meta).
 */
#[CoversClass(\Blog_Bootstrap::class)]
final class BlogBootstrapTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once TIGER_CORE_PATH . '/modules/blog/Bootstrap.php';
        $this->login('blogger', 'org-test', 'admin');
    }

    protected function tearDown(): void
    {
        // Clear the static registries so a leaked provider can't touch a later test.
        Tiger_Search::reset();
        (new ReflectionProperty(Tiger_Sitemap::class, '_providers'))->setValue(null, []);
        parent::tearDown();
    }

    private function invoke(string $method): void
    {
        $inst = (new \ReflectionClass('Blog_Bootstrap'))->newInstanceWithoutConstructor();
        (new ReflectionMethod('Blog_Bootstrap', $method))->invoke($inst);
    }

    private function seedArticle(array $overrides): string
    {
        return (new Tiger_Model_Page())->insert(array_merge([
            'type'         => 'article',
            'org_id'       => 'org-test',
            'locale'       => 'en',
            'title'        => 'Sitemap Post',
            'body'         => '<p>body</p>',
            'format'       => 'html',
            'status'       => 'published',
            'published_at' => '2023-01-01 00:00:00',
            'meta'         => json_encode(['excerpt' => 'The excerpt line']),
        ], $overrides));
    }

    #[Test]
    public function it_registers_the_public_blog_routes(): void
    {
        $this->invoke('_initBlogRoutes');
        $router = Zend_Controller_Front::getInstance()->getRouter();

        foreach (['blog_single', 'blog_category', 'blog_tag', 'blog_feed', 'blog_admin'] as $name) {
            $this->assertTrue($router->hasRoute($name), "route $name registered");
        }
    }

    #[Test]
    public function it_registers_the_articles_search_provider_that_resolves_articles(): void
    {
        Tiger_Search::reset();
        $this->invoke('_initSearchProvider');

        $provider = Tiger_Search::get('articles');
        $this->assertNotNull($provider, 'the articles provider is registered');
        $this->assertSame('Articles', $provider['label']);

        $this->seedArticle(['title' => 'Searchable Encyclopedia Article', 'slug' => 'enc-art', 'page_key' => 'enc-art', 'body' => 'encyclopedia entry text']);
        $res = Tiger_Search::query('encyclopedia', ['role' => 'guest', 'orgId' => 'org-test', 'locale' => 'en', 'only' => ['articles']]);

        $urls = array_column($res['results'], 'url');
        $this->assertContains('/blog/enc-art', $urls, 'the provider closure resolved the article to a /blog URL');
    }

    #[Test]
    public function it_registers_the_sitemap_provider_over_published_articles(): void
    {
        (new ReflectionProperty(Tiger_Sitemap::class, '_providers'))->setValue(null, []);
        $this->invoke('_initBlogSitemap');

        $this->seedArticle(['title' => 'In The Map', 'slug' => 'in-the-map', 'page_key' => 'in-the-map']);
        $urls = Tiger_Sitemap::collect(['locale' => 'en', 'orgId' => 'org-test']);

        $locs = array_column($urls, 'loc');
        $this->assertContains('/blog/in-the-map', $locs, 'the published article is contributed to the sitemap');
    }
}
