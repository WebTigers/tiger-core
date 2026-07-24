<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Search;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_Page;
use Tiger_Search;

/**
 * Search_Bootstrap — registers the built-in "pages" search provider (CMS pages, so search works out
 * of the box). Invoked directly (the harness doesn't boot module bootstraps); the provider closure
 * is then run end-to-end through Tiger_Search::query() against a seeded published page.
 */
#[CoversClass(\Search_Bootstrap::class)]
final class SearchBootstrapTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once TIGER_CORE_PATH . '/modules/search/Bootstrap.php';
        Tiger_Search::reset();
    }

    protected function tearDown(): void
    {
        Tiger_Search::reset();
        parent::tearDown();
    }

    #[Test]
    public function it_registers_the_pages_provider_that_resolves_published_pages(): void
    {
        $inst = (new \ReflectionClass('Search_Bootstrap'))->newInstanceWithoutConstructor();
        (new ReflectionMethod('Search_Bootstrap', '_initSearchProviders'))->invoke($inst);

        $provider = Tiger_Search::get('pages');
        $this->assertNotNull($provider, 'the pages provider is registered');
        $this->assertSame('Pages', $provider['label']);

        (new Tiger_Model_Page())->insert([
            'type' => 'page', 'org_id' => '', 'locale' => 'en', 'title' => 'Encyclopedia Home',
            'slug' => 'enc-home', 'page_key' => 'enc-home', 'body' => 'welcome to the encyclopedia',
            'format' => 'html', 'status' => 'published', 'published_at' => '2023-01-01 00:00:00',
        ]);

        $res  = Tiger_Search::query('encyclopedia', ['role' => 'guest', 'orgId' => '', 'locale' => 'en', 'only' => ['pages']]);
        $urls = array_column($res['results'], 'url');
        $this->assertContains('/enc-home', $urls, 'the pages provider closure resolved the page to a /<slug> URL');
    }
}
