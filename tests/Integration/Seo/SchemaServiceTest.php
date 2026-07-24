<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Seo;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Seo_Service_Schema;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_Menu;
use Zend_Config;
use Zend_Controller_Request_Http;
use Zend_Registry;
use Zend_View;

/**
 * Seo_Service_Schema — the JSON-LD (schema.org) contributor. Where Seo_Service_Head describes ONE page,
 * this describes the SITE as an entity (Organization + WebSite + SiteNavigationElement) plus per-page
 * BreadcrumbList and per-article BlogPosting nodes. It appends a `<script type="application/ld+json">`
 * to the process-wide `tigerJsonLd` placeholder.
 *
 * The tests read that placeholder back, extract the JSON from the <script>, decode the `@graph`, and
 * assert node shapes. The emit-once latch ($_emitted) is reset per test via reflection.
 */
#[CoversClass(Seo_Service_Schema::class)]
final class SchemaServiceTest extends IntegrationTestCase
{
    private ?Zend_View $view = null;
    private bool $hadConfig = false;
    private $priorConfig = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->view = new Zend_View();
        $this->view->doctype('HTML5');
        Zend_Registry::set('Zend_View', $this->view);

        // Placeholder containers are process-wide (shared across view instances) — clear the JSON-LD
        // container so a prior test's nodes don't bleed into this one.
        $this->view->placeholder('tigerJsonLd')->exchangeArray([]);

        $this->hadConfig   = Zend_Registry::isRegistered('Zend_Config');
        $this->priorConfig = $this->hadConfig ? Zend_Registry::get('Zend_Config') : null;

        $this->resetLatch();
    }

    protected function tearDown(): void
    {
        $this->resetLatch();
        $reg = Zend_Registry::getInstance();
        if ($reg->offsetExists('Zend_View')) { $reg->offsetUnset('Zend_View'); }
        if ($this->hadConfig) {
            Zend_Registry::set('Zend_Config', $this->priorConfig);
        } elseif ($reg->offsetExists('Zend_Config')) {
            $reg->offsetUnset('Zend_Config');
        }
        parent::tearDown();
    }

    private function resetLatch(): void
    {
        $p = new ReflectionProperty(Seo_Service_Schema::class, '_emitted');
        $p->setAccessible(true);
        $p->setValue(null, false);
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
        return $r;
    }

    /** All decoded @graph nodes appended so far (across every emitted <script>). */
    private function nodes(): array
    {
        $raw = (string) $this->view->placeholder('tigerJsonLd');
        $out = [];
        if (preg_match_all('#<script[^>]*>(.*?)</script>#s', $raw, $m)) {
            foreach ($m[1] as $json) {
                $data = json_decode($json, true);
                if (isset($data['@graph'])) {
                    foreach ($data['@graph'] as $n) { $out[] = $n; }
                }
            }
        }
        return $out;
    }

    private function nodeOfType(string $type): ?array
    {
        foreach ($this->nodes() as $n) {
            if (($n['@type'] ?? '') === $type) { return $n; }
        }
        return null;
    }

    // ----- emitSite -----------------------------------------------------------------------------

    #[Test]
    public function emit_site_builds_organization_and_website_nodes(): void
    {
        $this->config(['site' => ['name' => 'Acme Books', 'description' => 'We publish.']]);
        Seo_Service_Schema::emitSite($this->request('/'));

        $org = $this->nodeOfType('Organization');
        $this->assertNotNull($org, 'Organization node emitted');
        $this->assertSame('Acme Books', $org['name']);
        $this->assertSame('https://example.test/#organization', $org['@id']);
        $this->assertSame('We publish.', $org['description']);

        $site = $this->nodeOfType('WebSite');
        $this->assertNotNull($site, 'WebSite node emitted');
        $this->assertSame('https://example.test/#website', $site['@id']);
        $this->assertSame(['@id' => 'https://example.test/#organization'], $site['publisher']);
    }

    #[Test]
    public function emit_site_is_latched_to_once_per_request(): void
    {
        $this->config(['site' => ['name' => 'Acme']]);
        Seo_Service_Schema::emitSite($this->request('/'));
        Seo_Service_Schema::emitSite($this->request('/'));   // second call is a no-op

        $orgs = array_filter($this->nodes(), static fn ($n) => ($n['@type'] ?? '') === 'Organization');
        $this->assertCount(1, $orgs, 'the site graph renders a single time');
    }

    #[Test]
    public function organization_carries_sameas_social_links_from_config(): void
    {
        $this->config([
            'site' => ['name' => 'Acme'],
            'seo'  => ['social' => ['twitter' => 'https://x.com/acme', 'github' => 'https://github.com/acme']],
        ]);
        Seo_Service_Schema::emitSite($this->request('/'));
        $org = $this->nodeOfType('Organization');
        $this->assertContains('https://x.com/acme', $org['sameAs']);
        $this->assertContains('https://github.com/acme', $org['sameAs']);
    }

    #[Test]
    public function website_gets_a_search_action_when_a_search_url_is_configured(): void
    {
        $this->config([
            'site' => ['name' => 'Acme'],
            'seo'  => ['schema' => ['search_url' => '/search?q={search_term_string}']],
        ]);
        Seo_Service_Schema::emitSite($this->request('/'));
        $site = $this->nodeOfType('WebSite');
        $this->assertArrayHasKey('potentialAction', $site);
        $this->assertSame('SearchAction', $site['potentialAction']['@type']);
        $this->assertStringContainsString('https://example.test/search?q={search_term_string}', $site['potentialAction']['target']['urlTemplate']);
    }

    #[Test]
    public function organization_carries_a_logo_when_configured(): void
    {
        $this->config(['site' => ['name' => 'Acme', 'logo' => 'https://cdn.test/logo.png']]);
        Seo_Service_Schema::emitSite($this->request('/'));
        $org = $this->nodeOfType('Organization');
        $this->assertArrayHasKey('logo', $org);
        $this->assertSame('ImageObject', $org['logo']['@type']);
        $this->assertSame('https://cdn.test/logo.png', $org['logo']['url']);
    }

    #[Test]
    public function nav_skips_heading_and_placeholder_items(): void
    {
        $menu = new Tiger_Model_Menu();
        // A real link, a heading (no url), and a dead placeholder (#) — only the real link survives.
        $menu->insert(['org_id' => '', 'menu_key' => 'primary', 'parent_id' => null, 'sort_order' => 0, 'label' => 'Docs',    'url' => '/docs', 'status' => Tiger_Model_Menu::STATUS_PUBLISHED]);
        $menu->insert(['org_id' => '', 'menu_key' => 'primary', 'parent_id' => null, 'sort_order' => 1, 'label' => 'Heading', 'url' => '',      'status' => Tiger_Model_Menu::STATUS_PUBLISHED]);
        $menu->insert(['org_id' => '', 'menu_key' => 'primary', 'parent_id' => null, 'sort_order' => 2, 'label' => 'Dead',    'url' => '#',     'status' => Tiger_Model_Menu::STATUS_PUBLISHED]);

        $this->config(['site' => ['name' => 'Acme']]);
        Seo_Service_Schema::emitSite($this->request('/'));

        $nav = $this->nodeOfType('SiteNavigationElement');
        $this->assertSame(['Docs'], $nav['name'], 'only the real link is a nav element');
    }

    #[Test]
    public function emit_site_uses_the_configured_base_url_when_there_is_no_request(): void
    {
        $this->config(['site' => ['name' => 'Acme', 'url' => 'https://configured.test/']]);
        Seo_Service_Schema::emitSite(null);   // no request → base derives from tiger.site.url
        $org = $this->nodeOfType('Organization');
        $this->assertSame('https://configured.test/#organization', $org['@id']);
    }

    #[Test]
    public function emit_article_resolves_a_feature_image(): void
    {
        $this->config(['site' => ['name' => 'Acme']]);
        Seo_Service_Schema::emitArticle(
            (object) ['updated_at' => '2026-06-01 00:00:00'],
            [
                'title'        => 'Illustrated',
                'slug'         => 'illustrated',
                'excerpt'      => 'With a picture.',
                'published_at' => '2026-05-20 00:00:00',
                'feature'      => ['id' => 'https://cdn.test/feature.png'],
            ],
            $this->request('/blog/illustrated')
        );
        $post = $this->nodeOfType('BlogPosting');
        $this->assertArrayHasKey('image', $post);
        $this->assertSame('https://cdn.test/feature.png', $post['image']['url']);
    }

    #[Test]
    public function a_default_site_name_is_used_when_config_is_absent(): void
    {
        // No config registered → the neutral 'Tiger' fallback keeps the brand node from being nameless.
        $reg = Zend_Registry::getInstance();
        if ($reg->offsetExists('Zend_Config')) { $reg->offsetUnset('Zend_Config'); }
        Seo_Service_Schema::emitSite($this->request('/'));
        $this->assertSame('Tiger', $this->nodeOfType('Organization')['name']);
    }

    #[Test]
    public function the_primary_menu_becomes_a_site_navigation_element(): void
    {
        // Seed two global top-level nav items; the site-org fallback picks up global rows.
        $menu = new Tiger_Model_Menu();
        $menu->insert(['org_id' => '', 'menu_key' => 'primary', 'parent_id' => null, 'sort_order' => 0, 'label' => 'Home',  'url' => '/',      'status' => Tiger_Model_Menu::STATUS_PUBLISHED]);
        $menu->insert(['org_id' => '', 'menu_key' => 'primary', 'parent_id' => null, 'sort_order' => 1, 'label' => 'About', 'url' => '/about', 'status' => Tiger_Model_Menu::STATUS_PUBLISHED]);

        $this->config(['site' => ['name' => 'Acme']]);
        Seo_Service_Schema::emitSite($this->request('/'));

        $nav = $this->nodeOfType('SiteNavigationElement');
        $this->assertNotNull($nav, 'nav element emitted from the primary menu');
        $this->assertContains('Home', $nav['name']);
        $this->assertContains('About', $nav['name']);
        $this->assertContains('https://example.test/about', $nav['url']);
    }

    // ----- emitPageBreadcrumb -------------------------------------------------------------------

    #[Test]
    public function a_deep_page_emits_a_breadcrumb_list_with_the_leaf_title(): void
    {
        Seo_Service_Schema::emitPageBreadcrumb($this->request('/guides/getting-started'), 'Getting Started');
        $bc = $this->nodeOfType('BreadcrumbList');
        $this->assertNotNull($bc);
        $items = $bc['itemListElement'];
        $this->assertSame('Home', $items[0]['name']);
        $this->assertSame('Guides', $items[1]['name'], 'intermediate segment humanized');
        $this->assertSame('Getting Started', $items[2]['name'], 'leaf uses the real page title');
        $this->assertSame(1, $items[0]['position']);
        $this->assertSame(3, $items[2]['position']);
    }

    #[Test]
    public function the_homepage_emits_no_breadcrumb(): void
    {
        Seo_Service_Schema::emitPageBreadcrumb($this->request('/'), 'Home');
        $this->assertNull($this->nodeOfType('BreadcrumbList'), 'just Home → nothing worth emitting');
    }

    // ----- emitArticle --------------------------------------------------------------------------

    #[Test]
    public function emit_article_builds_a_blog_posting_and_its_breadcrumb(): void
    {
        $this->config(['site' => ['name' => 'Acme']]);
        $row     = (object) ['updated_at' => '2026-03-04 05:06:07'];
        $article = [
            'title'        => 'Hello World',
            'slug'         => 'hello-world',
            'excerpt'      => 'A first post.',
            'published_at' => '2026-03-01 00:00:00',
            'author'       => ['name' => 'Jane Author'],
        ];
        Seo_Service_Schema::emitArticle($row, $article, $this->request('/blog/hello-world'));

        $post = $this->nodeOfType('BlogPosting');
        $this->assertNotNull($post, 'BlogPosting node emitted');
        $this->assertSame('Hello World', $post['headline']);
        $this->assertSame('https://example.test/blog/hello-world', $post['url']);
        $this->assertSame('A first post.', $post['description']);
        $this->assertSame(['@id' => 'https://example.test/#website'], $post['isPartOf']);
        $this->assertSame(['@id' => 'https://example.test/#organization'], $post['publisher']);
        $this->assertSame('Person', $post['author']['@type']);
        $this->assertSame('Jane Author', $post['author']['name']);
        $this->assertArrayHasKey('datePublished', $post);
        $this->assertArrayHasKey('dateModified', $post);

        $bc = $this->nodeOfType('BreadcrumbList');
        $this->assertNotNull($bc, 'the article breadcrumb rides along');
    }

    #[Test]
    public function an_authorless_article_attributes_authorship_to_the_organization(): void
    {
        $this->config(['site' => ['name' => 'Acme']]);
        Seo_Service_Schema::emitArticle(
            (object) ['updated_at' => ''],
            ['title' => 'No Author', 'slug' => 'no-author', 'published_at' => '2026-04-01 00:00:00'],
            $this->request('/blog/no-author')
        );
        $post = $this->nodeOfType('BlogPosting');
        $this->assertSame(['@id' => 'https://example.test/#organization'], $post['author'], 'falls back to the Organization');
    }
}
