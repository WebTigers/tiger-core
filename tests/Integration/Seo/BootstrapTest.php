<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Seo;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use ReflectionProperty;
use Seo_Bootstrap;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_Page;
use Tiger_Routing_Overrides;
use Tiger_Sitemap;
use Zend_Controller_Front;

/**
 * Seo_Bootstrap — the module's boot hooks: register the head plugin, declare the public sitemap/robots/
 * llms route overrides, and contribute the CMS pages provider to Tiger_Sitemap.
 *
 * The hooks touch only process-wide static registries (no $this state), so the tests invoke them on a
 * constructor-less instance via reflection and assert the registration effects — including running the
 * seeded `pages` provider closure against real page rows.
 */
#[CoversClass(Seo_Bootstrap::class)]
final class BootstrapTest extends IntegrationTestCase
{
    public static function setUpBeforeClass(): void
    {
        // The bare `Seo_Bootstrap` isn't a typed module class, so the harness module autoloader (which
        // only resolves controllers/services/forms/models/plugins) can't find it — require it directly.
        if (!class_exists(Seo_Bootstrap::class, false)) {
            require_once TIGER_CORE_PATH . '/modules/seo/Bootstrap.php';
        }
    }

    private function bootstrap(): Seo_Bootstrap
    {
        // Skip Zend_Application_Module_Bootstrap's ctor (needs a parent app); the hooks don't use $this.
        return (new \ReflectionClass(Seo_Bootstrap::class))->newInstanceWithoutConstructor();
    }

    private function invoke(string $method): void
    {
        $m = new ReflectionMethod(Seo_Bootstrap::class, $method);
        $m->setAccessible(true);
        $m->invoke($this->bootstrap());
    }

    private function resetProviders(): void
    {
        $p = new ReflectionProperty(Tiger_Sitemap::class, '_providers');
        $p->setAccessible(true);
        $p->setValue(null, []);
    }

    protected function tearDown(): void
    {
        $this->resetProviders();
        Tiger_Routing_Overrides::clear();
        Zend_Controller_Front::getInstance()->resetInstance();
        parent::tearDown();
    }

    #[Test]
    public function it_declares_the_three_public_route_overrides(): void
    {
        Tiger_Routing_Overrides::clear();
        $this->invoke('_initSeoRoutes');

        $all = Tiger_Routing_Overrides::all();
        $patterns = array_map(static fn ($o) => $o['pattern'] ?? '', $all);
        $this->assertContains('sitemap.xml', $patterns);
        $this->assertContains('robots.txt', $patterns);
        $this->assertContains('llms.txt', $patterns);
    }

    #[Test]
    public function it_registers_the_head_front_controller_plugin(): void
    {
        $front = Zend_Controller_Front::getInstance();
        $front->resetInstance();
        $this->invoke('_initSeoHead');
        $this->assertTrue($front->hasPlugin(\Seo_Plugin_Head::class), 'the head plugin is registered');
    }

    #[Test]
    public function it_registers_a_pages_sitemap_provider(): void
    {
        $this->resetProviders();
        $this->invoke('_initSeoSitemap');
        $this->assertArrayHasKey('pages', Tiger_Sitemap::providers());
    }

    #[Test]
    public function the_pages_provider_returns_published_cms_pages_with_titles_and_descriptions(): void
    {
        $this->loginAs('admin');
        $page = new Tiger_Model_Page();
        $page->insert([
            'org_id'       => '',
            'type'         => Tiger_Model_Page::TYPE_PAGE,
            'locale'       => 'en',
            'status'       => Tiger_Model_Page::STATUS_PUBLISHED,
            'title'        => 'About Us',
            'slug'         => 'about',
            'body'         => '<p>hi</p>',
            'meta'         => json_encode(['seo' => ['description' => 'All about us.']]),
            'published_at' => null,
        ]);
        // A homepage (empty slug) must be skipped by the provider — the controller adds '/' itself.
        $page->insert([
            'org_id' => '',
            'type'   => Tiger_Model_Page::TYPE_PAGE,
            'locale' => 'en',
            'status' => Tiger_Model_Page::STATUS_PUBLISHED,
            'title'  => 'Home',
            'slug'   => '',
            'body'   => '',
            'meta'   => '{}',
        ]);

        $this->resetProviders();
        $this->invoke('_initSeoSitemap');
        $provider = Tiger_Sitemap::providers()['pages'];
        $urls = $provider(['locale' => 'en', 'orgId' => '']);

        $byLoc = [];
        foreach ($urls as $u) { $byLoc[$u['loc']] = $u; }
        $this->assertArrayHasKey('/about', $byLoc, 'the published page is listed');
        $this->assertSame('About Us', $byLoc['/about']['title']);
        $this->assertSame('All about us.', $byLoc['/about']['desc'], 'desc pulled from meta.seo.description');
        $this->assertArrayNotHasKey('/', $byLoc, 'the empty-slug homepage is skipped');
    }
}
