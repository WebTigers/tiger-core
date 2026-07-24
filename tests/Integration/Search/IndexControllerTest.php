<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Search;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Search_IndexController;
use Tiger\Tests\Support\ModuleControllerTestCase;

/**
 * Search_IndexController — the public /search results page (server-rendered so search works without JS
 * and results are crawlable). An empty term renders the bare page (no query); a term runs Tiger_Search
 * scoped to the caller's role. The harness dispatches index rendering-off and asserts the view model.
 */
#[CoversClass(Search_IndexController::class)]
final class IndexControllerTest extends ModuleControllerTestCase
{
    #[Test]
    public function index_with_no_term_renders_the_bare_search_page(): void
    {
        $this->dispatchAction(Search_IndexController::class, 'index', [], 'GET');

        $view = $this->controller()->view;
        $this->assertSame('', (string) $view->term);
        $this->assertNull($view->results, 'no query means no results object');
        $this->assertSame('Search', (string) $view->title);
    }

    #[Test]
    public function index_with_a_term_runs_a_query_as_guest(): void
    {
        $this->dispatchAction(Search_IndexController::class, 'index', ['q' => 'tiger'], 'GET');

        $view = $this->controller()->view;
        $this->assertSame('tiger', (string) $view->term);
        $this->assertNotNull($view->results, 'a term produces a results object');
        $this->assertStringContainsString('tiger', (string) $view->title);
    }

    #[Test]
    public function index_uses_the_signed_in_role_for_the_query(): void
    {
        // An authenticated caller's role flows into Tiger_Search::query (ACL-scoped results).
        $this->loginAs('admin');
        $this->dispatchAction(Search_IndexController::class, 'index', ['q' => 'report'], 'GET');

        $this->assertSame('report', (string) $this->controller()->view->term);
        $this->assertNotNull($this->controller()->view->results);
    }
}
