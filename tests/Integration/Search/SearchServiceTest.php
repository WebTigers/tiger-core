<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Search;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Search_Service_Search;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_Page;
use Tiger_Search;

/**
 * Search_Service_Search — the public /api behind the ⌘K launcher + /search, a thin guest-allowed
 * wrapper over Tiger_Search::query() that fans out across registered providers.
 *
 * This also drives the two reference providers END TO END: it registers the built-in "pages"
 * provider (Search_Bootstrap) and the blog "articles" provider (Blog_Bootstrap) through their REAL
 * bootstrap methods, so the provider closures — each calling Tiger_Model_Page::search() — run for
 * real against seeded content. Coverage spans: the empty-term short-circuit, grouped + flat-ranked
 * fan-out, the `only[]` provider restriction, per-provider limit, that a guest only sees published
 * in-scope content (drafts excluded), and the LIKE fallback for a short term FULLTEXT can't index.
 */
#[CoversClass(Search_Service_Search::class)]
final class SearchServiceTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tiger_Search::reset();
        $this->registerRealProviders();
    }

    protected function tearDown(): void
    {
        Tiger_Search::reset();
        parent::tearDown();
    }

    /** Invoke the shipped bootstrap methods that register the pages + articles providers. */
    private function registerRealProviders(): void
    {
        // The harness module autoloader resolves *_Service/*_Model/controllers, not *_Bootstrap — load them by path.
        require_once TIGER_CORE_PATH . '/modules/search/Bootstrap.php';
        require_once TIGER_CORE_PATH . '/modules/blog/Bootstrap.php';

        foreach ([['Search_Bootstrap', '_initSearchProviders'], ['Blog_Bootstrap', '_initSearchProvider']] as [$class, $method]) {
            $ref  = new \ReflectionClass($class);
            $inst = $ref->newInstanceWithoutConstructor();
            (new ReflectionMethod($class, $method))->invoke($inst);   // protected is invokable directly on PHP 8.1+
        }
    }

    /** Dispatch the service and return the response object. */
    private function call(array $params): object
    {
        return (new Search_Service_Search(['action' => 'query'] + $params))->getResponse();
    }

    private function seedPage(string $type, array $overrides): string
    {
        return (new Tiger_Model_Page())->insert(array_merge([
            'type'         => $type,
            'org_id'       => '',
            'locale'       => 'en',
            'title'        => 'Untitled',
            'body'         => '',
            'format'       => Tiger_Model_Page::FORMAT_HTML,
            'status'       => Tiger_Model_Page::STATUS_PUBLISHED,
            'published_at' => '2023-01-01 00:00:00',
        ], $overrides));
    }

    // ----- the service ---------------------------------------------------------------------------

    #[Test]
    public function an_empty_term_short_circuits_to_zero_results(): void
    {
        $res = $this->call(['q' => '   ']);
        $this->assertSame(1, (int) $res->result, 'guest-allowed, always a success envelope');
        $this->assertSame(0, $res->data['total']);
        $this->assertSame([], $res->data['groups']);
        $this->assertSame([], $res->data['results']);
    }

    #[Test]
    public function the_service_is_guest_allowed(): void
    {
        // No login → guest. A public search must still run (never a not_allowed refusal).
        $res = $this->call(['q' => 'anything']);
        $this->assertSame(1, (int) $res->result);
        $this->assertStringNotContainsString('not_allowed', json_encode($res->messages));
    }

    #[Test]
    public function query_fans_out_across_pages_and_articles_grouped_and_flat(): void
    {
        $this->seedPage('page', ['title' => 'Encyclopedia Britannica', 'slug' => 'enc', 'page_key' => 'enc', 'body' => 'a reference work about encyclopedia topics']);
        $this->seedPage('article', ['title' => 'My Encyclopedia Journey', 'slug' => 'journey', 'page_key' => 'journey', 'body' => 'writing an encyclopedia by hand']);

        $res = $this->call(['q' => 'encyclopedia', 'limit' => 6]);
        $data = $res->data;

        $this->assertGreaterThanOrEqual(2, $data['total'], 'both a page and an article match');
        $sources = array_column($data['groups'], 'key');
        $this->assertContains('pages', $sources, 'the pages provider contributed');
        $this->assertContains('articles', $sources, 'the articles provider contributed');

        // flat results carry provider metadata + a blog url form for the article hit.
        $urls = array_column($data['results'], 'url');
        $this->assertContains('/blog/journey', $urls, 'article urls are /blog/<slug>');
        $this->assertContains('/enc', $urls, 'page urls are /<slug>');
    }

    #[Test]
    public function the_only_filter_restricts_to_a_single_provider(): void
    {
        $this->seedPage('page', ['title' => 'Widget Guide', 'slug' => 'wg', 'page_key' => 'wg', 'body' => 'all about the widget']);
        $this->seedPage('article', ['title' => 'Widget News', 'slug' => 'wn', 'page_key' => 'wn', 'body' => 'the latest widget updates']);

        $res = $this->call(['q' => 'widget', 'only' => ['articles']]);
        $sources = array_column($res->data['groups'], 'key');
        $this->assertSame(['articles'], $sources, 'only the articles provider ran');
    }

    #[Test]
    public function a_guest_never_sees_a_draft(): void
    {
        $this->seedPage('article', ['title' => 'Secret Draft Memo', 'slug' => 'secret', 'page_key' => 'secret', 'body' => 'confidential memo text', 'status' => 'draft']);
        $this->seedPage('article', ['title' => 'Public Memo', 'slug' => 'public', 'page_key' => 'public', 'body' => 'a published memo everyone can read']);

        $titles = array_column($this->call(['q' => 'memo'])->data['results'], 'title');
        $this->assertContains('Public Memo', $titles);
        $this->assertNotContains('Secret Draft Memo', $titles, 'drafts are not surfaced to a guest');
    }

    #[Test]
    public function a_short_term_uses_the_like_fallback(): void
    {
        // "cat" is below innodb_ft_min_token_size (4) so FULLTEXT indexes nothing → the LIKE branch runs.
        $this->seedPage('page', ['title' => 'cat', 'slug' => 'cat', 'page_key' => 'cat', 'body' => 'a short word page']);

        $titles = array_column($this->call(['q' => 'cat'])->data['results'], 'title');
        $this->assertContains('cat', $titles, 'the LIKE fallback finds a short-term match FULLTEXT misses');
    }

    #[Test]
    public function an_authenticated_requester_role_flows_to_the_providers(): void
    {
        // Signing in exercises _role()'s identity branch (vs the guest fallback). Content is public,
        // so a user sees it too — the assertion is that the authenticated path runs without error.
        $this->login('reader-1', 'org-test', 'user');
        $this->seedPage('page', ['title' => 'Members Handbook', 'slug' => 'handbook', 'page_key' => 'handbook', 'body' => 'the members handbook content']);

        $res = $this->call(['q' => 'handbook']);
        $this->assertSame(1, (int) $res->result);
        $this->assertGreaterThanOrEqual(1, $res->data['total'], 'the query ran under the authenticated role');
    }

    #[Test]
    public function the_term_alias_field_is_accepted(): void
    {
        $this->seedPage('page', ['title' => 'Alias Term Page', 'slug' => 'alias', 'page_key' => 'alias', 'body' => 'searchable aliasterm content']);
        // The service accepts `term` as an alias for `q`.
        $res = $this->call(['term' => 'aliasterm']);
        $this->assertGreaterThanOrEqual(1, $res->data['total'], 'the `term` alias drives the query');
    }
}
