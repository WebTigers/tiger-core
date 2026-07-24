<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Controller_Plugin_RouteOverride;
use Tiger_Routing_Overrides;
use Zend_Controller_Front;
use Zend_Controller_Request_Http;
use Zend_Controller_Request_Simple;

/**
 * Tiger_Controller_Plugin_RouteOverride — applies declared pretty-route overrides at routeShutdown.
 *
 * It rewrites an unmatched URL to a canonical `module/controller/action` target when a declared
 * override's prefix matches — but ONLY when no real controller already claims the URL (the
 * isDispatchable guard, which lets a `/docs` alias coexist with the module's own `/docs/admin/...`).
 * Overrides are walked in priority order (Tiger_Routing_Overrides::all(), DESC) and the FIRST match
 * wins; the remainder of the path becomes the `slug` param.
 *
 * No DB: the front controller here has no controller directories, so isDispatchable is always false
 * (the "nothing real claims it" branch), and the override registry is driven directly. The registry
 * and the front-controller singleton are process-global — both reset per test.
 */
#[CoversClass(Tiger_Controller_Plugin_RouteOverride::class)]
final class RouteOverridePluginTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tiger_Routing_Overrides::clear();
        Zend_Controller_Front::getInstance()->resetInstance();
    }

    protected function tearDown(): void
    {
        Tiger_Routing_Overrides::clear();
        Zend_Controller_Front::getInstance()->resetInstance();
        parent::tearDown();
    }

    private function http(string $pathInfo): Zend_Controller_Request_Http
    {
        $r = new Zend_Controller_Request_Http();
        $r->setPathInfo($pathInfo);
        return $r;
    }

    private function plugin(): Tiger_Controller_Plugin_RouteOverride
    {
        return new Tiger_Controller_Plugin_RouteOverride();
    }

    #[Test]
    public function a_non_http_request_is_left_untouched(): void
    {
        Tiger_Routing_Overrides::register('docs', ['pattern' => 'docs', 'target' => 'docs/index/docs']);
        $req = new Zend_Controller_Request_Simple();
        $req->setControllerName('foo');

        $this->plugin()->routeShutdown($req);
        $this->assertSame('foo', $req->getControllerName(), 'a Simple request has no pathInfo to rewrite');
    }

    #[Test]
    public function a_nested_path_under_the_prefix_is_rewritten_with_the_remainder_as_slug(): void
    {
        Tiger_Routing_Overrides::register('docs', ['pattern' => 'docs', 'target' => 'docs/index/docs']);
        $req = $this->http('/docs/getting-started/install');

        $this->plugin()->routeShutdown($req);

        $this->assertSame('docs', $req->getModuleName());
        $this->assertSame('index', $req->getControllerName());
        $this->assertSame('docs', $req->getActionName());
        $this->assertSame('getting-started/install', $req->getParam('slug'));
    }

    #[Test]
    public function an_exact_prefix_match_rewrites_with_no_slug(): void
    {
        Tiger_Routing_Overrides::register('docs', ['pattern' => 'docs', 'target' => 'docs/index/docs']);
        $req = $this->http('/docs');

        $this->plugin()->routeShutdown($req);

        $this->assertSame('docs', $req->getModuleName());
        $this->assertNull($req->getParam('slug'), 'a bare prefix carries no slug');
    }

    #[Test]
    public function a_path_matching_no_override_is_left_untouched(): void
    {
        Tiger_Routing_Overrides::register('docs', ['pattern' => 'docs', 'target' => 'docs/index/docs']);
        $req = $this->http('/pricing');

        $this->plugin()->routeShutdown($req);
        $this->assertNull($req->getModuleName(), 'no matching override => request not rewritten');
    }

    #[Test]
    public function an_empty_path_is_left_untouched(): void
    {
        Tiger_Routing_Overrides::register('docs', ['pattern' => 'docs', 'target' => 'docs/index/docs']);
        $req = $this->http('/');

        $this->plugin()->routeShutdown($req);
        $this->assertNull($req->getModuleName());
    }

    #[Test]
    public function overlapping_prefixes_resolve_to_the_highest_priority_first(): void
    {
        // Both prefixes match "/shop/cart/42"; the higher-priority, more-specific one wins.
        Tiger_Routing_Overrides::register('broad',  ['pattern' => 'shop',      'target' => 'store/home/index', 'priority' => 100]);
        Tiger_Routing_Overrides::register('narrow', ['pattern' => 'shop/cart', 'target' => 'store/cart/view',  'priority' => 200]);

        $req = $this->http('/shop/cart/42');
        $this->plugin()->routeShutdown($req);

        $this->assertSame('cart', $req->getControllerName(), 'the higher-priority override claimed it');
        $this->assertSame('view', $req->getActionName());
        $this->assertSame('42', $req->getParam('slug'));
    }

    #[Test]
    public function a_prefix_that_only_shares_a_leading_substring_does_not_match(): void
    {
        // "/documentation" must NOT match the "docs" prefix — only "docs" or "docs/…".
        Tiger_Routing_Overrides::register('docs', ['pattern' => 'docs', 'target' => 'docs/index/docs']);
        $req = $this->http('/documentation');

        $this->plugin()->routeShutdown($req);
        $this->assertNull($req->getModuleName());
    }
}
