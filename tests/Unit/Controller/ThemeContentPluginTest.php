<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Controller_Plugin_ThemeContent;
use Zend_Controller_Front;
use Zend_Controller_Request_Http;
use Zend_Controller_Request_Simple;
use Zend_Registry;

/**
 * Tiger_Controller_Plugin_ThemeContent — the LAST hop of the slug chain: real controller → CMS page
 * (PageDispatch) → theme `content/<slug>.phtml` (this) → 404. So a vendor theme can ship static pages
 * as body partials, rendered through the one theme layout, with no per-page DB rows.
 *
 * It only fires when nothing real claims the URL (isDispatchable — always false here, no controller
 * dirs) and a theme dir is registered. The slug is a strict, dot-free token (no traversal out of
 * `content/`), and the theme's stock ".html" links resolve (the suffix is stripped). No DB: the test
 * points Tiger_ThemeDir at a temp theme with a real content partial and drives the request.
 */
#[CoversClass(Tiger_Controller_Plugin_ThemeContent::class)]
final class ThemeContentPluginTest extends UnitTestCase
{
    private static string $themeDir;

    public static function setUpBeforeClass(): void
    {
        self::$themeDir = sys_get_temp_dir() . '/tiger-theme-test-' . getmypid();
        @mkdir(self::$themeDir . '/content', 0777, true);
        file_put_contents(self::$themeDir . '/content/about.phtml', '<!-- tiger:page --><h1>About</h1>');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Zend_Controller_Front::getInstance()->resetInstance();
    }

    protected function tearDown(): void
    {
        Zend_Controller_Front::getInstance()->resetInstance();
        parent::tearDown();
    }

    private function http(string $pathInfo): Zend_Controller_Request_Http
    {
        $r = new Zend_Controller_Request_Http();
        $r->setPathInfo($pathInfo);
        return $r;
    }

    private function plugin(): Tiger_Controller_Plugin_ThemeContent
    {
        return new Tiger_Controller_Plugin_ThemeContent();
    }

    #[Test]
    public function a_non_http_request_is_ignored(): void
    {
        Zend_Registry::set('Tiger_ThemeDir', self::$themeDir);
        $req = new Zend_Controller_Request_Simple();
        $req->setControllerName('foo');

        $this->plugin()->routeShutdown($req);
        $this->assertSame('foo', $req->getControllerName());
    }

    #[Test]
    public function with_no_theme_dir_registered_the_request_is_left_untouched(): void
    {
        $req = $this->http('/about');   // no Tiger_ThemeDir in the (per-test reset) registry
        $this->plugin()->routeShutdown($req);
        $this->assertNull($req->getModuleName());
    }

    #[Test]
    public function an_existing_content_partial_routes_to_the_theme_content_action(): void
    {
        Zend_Registry::set('Tiger_ThemeDir', self::$themeDir);
        $req = $this->http('/about');

        $this->plugin()->routeShutdown($req);

        $this->assertSame('page', $req->getControllerName());
        $this->assertSame('theme-content', $req->getActionName());
        $this->assertSame('about', $req->getParam('theme_content_slug'));
    }

    #[Test]
    public function a_stock_html_suffix_resolves_the_same_partial(): void
    {
        Zend_Registry::set('Tiger_ThemeDir', self::$themeDir);
        $req = $this->http('/about.html');   // the vendor theme's original ".html" link

        $this->plugin()->routeShutdown($req);

        $this->assertSame('theme-content', $req->getActionName());
        $this->assertSame('about', $req->getParam('theme_content_slug'), 'the .html suffix is stripped');
    }

    #[Test]
    public function a_slug_with_no_matching_partial_is_left_untouched(): void
    {
        Zend_Registry::set('Tiger_ThemeDir', self::$themeDir);
        $req = $this->http('/nonexistent');

        $this->plugin()->routeShutdown($req);
        $this->assertNull($req->getModuleName());
    }

    #[Test]
    public function a_dotted_traversal_slug_is_refused(): void
    {
        Zend_Registry::set('Tiger_ThemeDir', self::$themeDir);
        // A '.' in the slug fails the strict dot-free token guard, so it can never escape content/.
        $req = $this->http('/../about');

        $this->plugin()->routeShutdown($req);
        $this->assertNull($req->getModuleName());
    }

    #[Test]
    public function the_root_path_is_left_untouched(): void
    {
        Zend_Registry::set('Tiger_ThemeDir', self::$themeDir);
        $req = $this->http('/');

        $this->plugin()->routeShutdown($req);
        $this->assertNull($req->getModuleName());
    }
}
