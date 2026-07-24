<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Controller_Plugin_PageDispatch;
use Tiger_Model_Org;
use Tiger_Model_Page;
use Zend_Controller_Front;
use Zend_Controller_Request_Http;
use Zend_Controller_Request_Simple;

/**
 * Tiger_Controller_Plugin_PageDispatch — lets published CMS content own the site's public URLs at
 * routeShutdown. If a published `page` row claims the requested slug it hands off to
 * PageController::viewAction (even over a shipped route, so an admin can REPLACE a built-in landing
 * with editable CMS content). A reserved-slug blacklist (module namespaces + core system controllers)
 * keeps a page from ever shadowing real application routes; the root `/` is left to IndexController.
 *
 * DB-backed (it resolves against `page`), so this seeds published rows at the global scope. The
 * plugin's locale comes from the process LANG constant, so pages are seeded at that exact locale to
 * stay order-independent. The 301 `page_redirect` branch calls gotoUrlAndExit (it exits the process),
 * so it is deliberately not driven here — see WAVE5-FINDINGS-ctrl.md.
 */
#[CoversClass(Tiger_Controller_Plugin_PageDispatch::class)]
final class PageDispatchPluginTest extends IntegrationTestCase
{
    private string $locale;

    protected function setUp(): void
    {
        parent::setUp();
        $this->locale = defined('LANG') ? LANG : 'en';
        Tiger_Model_Org::setSiteOrgId('');            // resolve public content at global scope ('')
        Zend_Controller_Front::getInstance()->resetInstance();
    }

    protected function tearDown(): void
    {
        // Don't leak the forced site-org into other integration tests — reset the memo to null.
        (new ReflectionProperty(Tiger_Model_Org::class, '_siteOrgId'))->setValue(null, null);
        Zend_Controller_Front::getInstance()->resetInstance();
        parent::tearDown();
    }

    private function plugin(): Tiger_Controller_Plugin_PageDispatch
    {
        return new Tiger_Controller_Plugin_PageDispatch();
    }

    private function http(string $pathInfo): Zend_Controller_Request_Http
    {
        $r = new Zend_Controller_Request_Http();
        $r->setPathInfo($pathInfo);
        return $r;
    }

    private function seedPage(string $slug): string
    {
        return (new Tiger_Model_Page())->insert([
            'org_id' => '',
            'type'   => Tiger_Model_Page::TYPE_PAGE,
            'slug'   => $slug,
            'locale' => $this->locale,
            'title'  => 'Seeded ' . $slug,
            'body'   => '<p>hi</p>',
            'format' => 'html',
            'status' => Tiger_Model_Page::STATUS_PUBLISHED,
        ]);
    }

    #[Test]
    public function a_non_http_request_is_ignored(): void
    {
        $req = new Zend_Controller_Request_Simple();
        $req->setControllerName('foo');
        $this->plugin()->routeShutdown($req);
        $this->assertSame('foo', $req->getControllerName());
    }

    #[Test]
    public function the_root_path_is_left_to_the_index_controller(): void
    {
        $this->seedPage('anything');
        $req = $this->http('/');
        $this->plugin()->routeShutdown($req);
        $this->assertNull($req->getControllerName(), 'the root belongs to IndexController');
    }

    #[Test]
    public function a_published_page_claims_its_slug_and_routes_to_page_view(): void
    {
        $pageId = $this->seedPage('about-us');
        $req    = $this->http('/about-us');

        $this->plugin()->routeShutdown($req);

        $this->assertSame('default', $req->getModuleName());
        $this->assertSame('page', $req->getControllerName());
        $this->assertSame('view', $req->getActionName());
        $this->assertSame($pageId, $req->getParam('cms_page_id'));
    }

    #[Test]
    public function a_reserved_first_segment_is_never_claimed_by_a_page(): void
    {
        // Even if a page somehow held the slug, a core system-controller prefix is off-limits.
        $this->seedPage('admin');
        $req = $this->http('/admin/settings');

        $this->plugin()->routeShutdown($req);
        $this->assertNotSame('page', $req->getControllerName(), '/admin is reserved');
    }

    #[Test]
    public function an_unclaimed_slug_with_no_redirect_is_left_untouched(): void
    {
        // No page, no page_redirect, nothing dispatchable => the request is left for a clean 404.
        $req = $this->http('/no-such-page');
        $this->plugin()->routeShutdown($req);
        $this->assertNotSame('page', $req->getControllerName());
    }

    #[Test]
    public function a_draft_page_does_not_claim_the_slug(): void
    {
        (new Tiger_Model_Page())->insert([
            'org_id' => '', 'type' => Tiger_Model_Page::TYPE_PAGE, 'slug' => 'draft-page',
            'locale' => $this->locale, 'title' => 'Draft', 'body' => 'x', 'format' => 'html',
            'status' => 'draft',
        ]);
        $req = $this->http('/draft-page');

        $this->plugin()->routeShutdown($req);
        $this->assertNotSame('page', $req->getControllerName(), 'only published pages answer');
    }
}
