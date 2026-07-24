<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Seo;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Seo_LlmsController;
use Seo_RobotsController;
use Seo_SitemapController;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Sitemap;
use Zend_Config;
use Zend_Controller_Front;
use Zend_Controller_Request_Http;
use Zend_Controller_Response_Http;
use Zend_Layout;
use Zend_Registry;
use Zend_View;

/**
 * The three PUBLIC TigerSEO controllers — /sitemap.xml, /robots.txt, /llms.txt — served as ROUTES (not
 * docroot files). Each disables layout/render in init() and writes a plain-text/XML body.
 *
 * The tests stand up a minimal front-controller context (register the seo module's controller dir for
 * the `default` module + Zend_Layout::startMvc()) so the real init() runs (layout + viewRenderer action
 * helpers resolve), then call the action and read the response body. Sitemap/Llms read the shared
 * `Tiger_Sitemap` provider registry, which the tests seed directly and reset per test.
 */
#[CoversClass(Seo_RobotsController::class)]
#[CoversClass(Seo_SitemapController::class)]
#[CoversClass(Seo_LlmsController::class)]
final class ControllersTest extends IntegrationTestCase
{
    private bool $hadConfig = false;
    private $priorConfig = null;

    protected function setUp(): void
    {
        parent::setUp();

        $view = new Zend_View();
        $view->doctype('HTML5');
        Zend_Registry::set('Zend_View', $view);

        $front = Zend_Controller_Front::getInstance();
        $front->addControllerDirectory(TIGER_CORE_PATH . '/modules/seo/controllers', 'default');
        Zend_Layout::startMvc();

        $this->hadConfig   = Zend_Registry::isRegistered('Zend_Config');
        $this->priorConfig = $this->hadConfig ? Zend_Registry::get('Zend_Config') : null;

        $this->resetProviders();
        $this->resetSiteOrg();
    }

    protected function tearDown(): void
    {
        $this->resetProviders();
        $this->resetSiteOrg();

        Zend_Layout::resetMvcInstance();
        Zend_Controller_Front::getInstance()->resetInstance();

        $reg = Zend_Registry::getInstance();
        if ($reg->offsetExists('Zend_View')) { $reg->offsetUnset('Zend_View'); }
        if ($this->hadConfig) {
            Zend_Registry::set('Zend_Config', $this->priorConfig);
        } elseif ($reg->offsetExists('Zend_Config')) {
            $reg->offsetUnset('Zend_Config');
        }
        parent::tearDown();
    }

    private function resetProviders(): void
    {
        $p = new ReflectionProperty(Tiger_Sitemap::class, '_providers');
        $p->setAccessible(true);
        $p->setValue(null, []);
    }

    private function resetSiteOrg(): void
    {
        $p = new ReflectionProperty(\Tiger_Model_Org::class, '_siteOrgId');
        $p->setAccessible(true);
        $p->setValue(null, null);
    }

    private function config(array $tiger): void
    {
        Zend_Registry::set('Zend_Config', new Zend_Config(['tiger' => $tiger]));
    }

    private function request(string $uri = '/'): Zend_Controller_Request_Http
    {
        $_SERVER['HTTP_HOST'] = 'example.test';
        $_SERVER['HTTPS']     = 'on';
        $r = new Zend_Controller_Request_Http();
        $r->setRequestUri($uri);
        $r->setPathInfo($uri);
        $r->setModuleName('default');
        return $r;
    }

    private function dispatch(string $class, string $action): Zend_Controller_Response_Http
    {
        $res = new Zend_Controller_Response_Http();
        $ctrl = new $class($this->request(), $res);
        $ctrl->$action();
        return $res;
    }

    // ----- robots.txt ---------------------------------------------------------------------------

    #[Test]
    public function robots_emits_the_default_disallow_list_and_the_sitemap_pointer(): void
    {
        $body = $this->dispatch(Seo_RobotsController::class, 'txtAction')->getBody();
        $this->assertStringContainsString('User-agent: *', $body);
        $this->assertStringContainsString('Disallow: /admin', $body);
        $this->assertStringContainsString('Disallow: /api', $body);
        $this->assertStringContainsString('Sitemap: https://example.test/sitemap.xml', $body);
    }

    #[Test]
    public function robots_honors_a_configured_array_disallow_list(): void
    {
        $this->config(['seo' => ['robots' => ['disallow' => ['/private', '/secret']]]]);
        $body = $this->dispatch(Seo_RobotsController::class, 'txtAction')->getBody();
        $this->assertStringContainsString('Disallow: /private', $body);
        $this->assertStringContainsString('Disallow: /secret', $body);
        $this->assertStringNotContainsString('Disallow: /admin', $body, 'config overrides the defaults');
    }

    #[Test]
    public function robots_honors_a_scalar_disallow_value(): void
    {
        $this->config(['seo' => ['robots' => ['disallow' => '/nope']]]);
        $body = $this->dispatch(Seo_RobotsController::class, 'txtAction')->getBody();
        $this->assertStringContainsString('Disallow: /nope', $body);
    }

    // ----- sitemap.xml --------------------------------------------------------------------------

    #[Test]
    public function sitemap_always_includes_the_homepage(): void
    {
        $body = $this->dispatch(Seo_SitemapController::class, 'xmlAction')->getBody();
        $this->assertStringContainsString('<?xml version="1.0"', $body);
        $this->assertStringContainsString('<urlset', $body);
        $this->assertStringContainsString('<loc>https://example.test/</loc>', $body);
    }

    #[Test]
    public function sitemap_absolutizes_provider_urls_and_emits_lastmod_and_priority(): void
    {
        Tiger_Sitemap::register('pages', static function () {
            return [
                ['loc' => '/about', 'lastmod' => '2026-05-06 07:08:09', 'priority' => 0.8],
                ['loc' => '/contact', 'lastmod' => null, 'changefreq' => 'weekly'],
            ];
        });
        $body = $this->dispatch(Seo_SitemapController::class, 'xmlAction')->getBody();
        $this->assertStringContainsString('<loc>https://example.test/about</loc>', $body);
        $this->assertStringContainsString('<lastmod>2026-05-06', $body);
        $this->assertStringContainsString('<priority>0.8</priority>', $body);
        $this->assertStringContainsString('<changefreq>weekly</changefreq>', $body);
        $this->assertStringContainsString('<loc>https://example.test/contact</loc>', $body);
    }

    #[Test]
    public function sitemap_keeps_an_already_absolute_loc_as_is(): void
    {
        Tiger_Sitemap::register('ext', static function () {
            return [['loc' => 'https://cdn.example.test/page', 'lastmod' => null]];
        });
        $body = $this->dispatch(Seo_SitemapController::class, 'xmlAction')->getBody();
        $this->assertStringContainsString('<loc>https://cdn.example.test/page</loc>', $body);
    }

    // ----- llms.txt -----------------------------------------------------------------------------

    #[Test]
    public function llms_emits_a_markdown_map_with_a_section_per_provider(): void
    {
        $this->config(['site' => ['name' => 'Acme Books', 'tagline' => 'We publish.']]);
        Tiger_Sitemap::register('blog', static function () {
            return [
                ['loc' => '/blog/hello', 'title' => 'Hello World', 'desc' => 'A first post'],
                ['loc' => '/blog/again', 'title' => 'Again', 'desc' => ''],
            ];
        });
        $body = $this->dispatch(Seo_LlmsController::class, 'txtAction')->getBody();
        $this->assertStringContainsString('# Acme Books', $body);
        $this->assertStringContainsString('> We publish.', $body);
        $this->assertStringContainsString('## Blog', $body, 'provider key humanized into a section heading');
        $this->assertStringContainsString('[Hello World](https://example.test/blog/hello): A first post', $body);
        $this->assertStringContainsString('[Again](https://example.test/blog/again)', $body);
    }

    #[Test]
    public function llms_surfaces_a_configured_featured_doc(): void
    {
        $this->config([
            'site' => ['name' => 'Acme'],
            'seo'  => ['llms' => ['doc_url' => 'https://acme.test/why', 'doc_label' => 'Why Acme', 'doc_desc' => 'The pitch']],
        ]);
        $body = $this->dispatch(Seo_LlmsController::class, 'txtAction')->getBody();
        $this->assertStringContainsString('## For AI agents', $body);
        $this->assertStringContainsString('[Why Acme](https://acme.test/why): The pitch', $body);
    }

    #[Test]
    public function llms_returns_404_when_disabled(): void
    {
        $this->config(['seo' => ['llms' => ['enabled' => '0']]]);
        $this->expectException(\Zend_Controller_Action_Exception::class);
        $this->dispatch(Seo_LlmsController::class, 'txtAction');
    }
}
