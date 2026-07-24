<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Seo;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Seo_Service_Head;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_Media;
use Zend_Config;
use Zend_Controller_Request_Http;
use Zend_Registry;
use Zend_View;

/**
 * Seo_Service_Head — the resolver that maps a page row's `meta.seo` onto the shared head registry
 * (TigerZF's headTitle/headMeta/headLink placeholders the layout renders).
 *
 * It never renders markup — it appends typed entries to the process-wide Zend_View. So the tests seed
 * a Zend_View (HTML5 doctype, so `og:` property meta is valid — the same doctype the live layout sets)
 * into the registry, call forRow(page, request, overrides), then read the head helpers back as strings
 * and assert the emitted tags. Coverage: title override, description, robots (index/follow → noindex/
 * nofollow), canonical (explicit + self-referencing), Open Graph + Twitter, og:image by absolute URL
 * and by media id (real dimensions), article times, and the overrides-fill-blanks-only rule.
 */
#[CoversClass(Seo_Service_Head::class)]
final class HeadServiceTest extends IntegrationTestCase
{
    private ?Zend_View $view = null;
    private bool $hadConfig = false;
    private $priorConfig = null;

    protected function setUp(): void
    {
        parent::setUp();

        // A fresh view with an HTML5 doctype (og:* property meta is invalid otherwise — the live layout
        // sets HTML5). Any Zend_View instance shares the process-wide placeholder registry, so this IS
        // the container the service writes into.
        $this->view = new Zend_View();
        $this->view->doctype('HTML5');
        Zend_Registry::set('Zend_View', $this->view);

        // Head/placeholder containers are process-wide (shared across view instances) — clear them so a
        // prior test's tags don't bleed into this one.
        $this->view->headTitle()->getContainer()->exchangeArray([]);
        $this->view->headMeta()->getContainer()->exchangeArray([]);
        $this->view->headLink()->getContainer()->exchangeArray([]);

        $this->hadConfig   = Zend_Registry::isRegistered('Zend_Config');
        $this->priorConfig = $this->hadConfig ? Zend_Registry::get('Zend_Config') : null;
    }

    protected function tearDown(): void
    {
        $reg = Zend_Registry::getInstance();
        if ($reg->offsetExists('Zend_View'))   { $reg->offsetUnset('Zend_View'); }
        if ($this->hadConfig) {
            Zend_Registry::set('Zend_Config', $this->priorConfig);
        } elseif ($reg->offsetExists('Zend_Config')) {
            $reg->offsetUnset('Zend_Config');
        }
        parent::tearDown();
    }

    /** Register the runtime config the service reads (tiger.*). */
    private function config(array $tiger): void
    {
        Zend_Registry::set('Zend_Config', new Zend_Config(['tiger' => $tiger]));
    }

    /** A page row as a plain object carrying a JSON `meta` (what a Zend_Db_Table_Row looks like to the service). */
    private function page(array $seo, array $extra = []): object
    {
        $meta = $seo ? ['seo' => $seo] : [];
        return (object) array_merge(['meta' => json_encode($meta), 'title' => 'Row Title', 'type' => 'page'], $extra);
    }

    /** A request whose scheme/host/uri drive the self-referencing canonical + og:url. */
    private function request(string $uri = '/hello'): Zend_Controller_Request_Http
    {
        $_SERVER['HTTP_HOST'] = 'example.test';
        $_SERVER['HTTPS']     = 'on';
        $r = new Zend_Controller_Request_Http();
        $r->setRequestUri($uri);
        return $r;
    }

    private function head(): string
    {
        return $this->view->headTitle()->toString() . "\n"
             . $this->view->headMeta()->toString() . "\n"
             . $this->view->headLink()->toString();
    }

    // ----- null / empty guards ------------------------------------------------------------------

    #[Test]
    public function a_null_page_emits_nothing(): void
    {
        Seo_Service_Head::forRow(null);
        $this->assertSame('', trim($this->view->headMeta()->toString()));
    }

    #[Test]
    public function a_page_with_no_seo_still_emits_og_type_and_twitter_card(): void
    {
        $this->config(['site' => ['name' => 'Acme']]);
        Seo_Service_Head::forRow($this->page([]), $this->request());
        $head = $this->head();
        $this->assertStringContainsString('og:type', $head);
        $this->assertStringContainsString('website', $head);
        $this->assertStringContainsString('twitter:card', $head);
        $this->assertStringContainsString('summary', $head, 'no image → plain summary card');
        $this->assertStringContainsString('og:site_name', $head, 'site name from config');
        $this->assertStringContainsString('Acme', $head);
    }

    // ----- title / description / robots ---------------------------------------------------------

    #[Test]
    public function an_seo_title_overrides_the_page_title(): void
    {
        Seo_Service_Head::forRow($this->page(['title' => 'Custom SEO Title']), $this->request());
        $this->assertStringContainsString('Custom SEO Title', $this->view->headTitle()->toString());
        $this->assertStringContainsString('og:title', $this->view->headMeta()->toString());
    }

    #[Test]
    public function a_description_emits_meta_description_and_og_description(): void
    {
        Seo_Service_Head::forRow($this->page(['description' => 'A fine page.']), $this->request());
        $meta = $this->view->headMeta()->toString();
        $this->assertStringContainsString('name="description"', $meta);
        $this->assertStringContainsString('A fine page.', $meta);
        $this->assertStringContainsString('og:description', $meta);
    }

    #[Test]
    public function robots_directive_is_emitted_only_when_restricted(): void
    {
        // index+follow (the default) → NO robots tag.
        Seo_Service_Head::forRow($this->page(['robots' => ['index' => true, 'follow' => true]]), $this->request());
        $this->assertStringNotContainsString('name="robots"', $this->view->headMeta()->toString());
    }

    #[Test]
    public function noindex_nofollow_emit_a_robots_tag(): void
    {
        Seo_Service_Head::forRow($this->page(['robots' => ['index' => false, 'follow' => false]]), $this->request());
        $meta = $this->view->headMeta()->toString();
        $this->assertStringContainsString('name="robots"', $meta);
        $this->assertStringContainsString('noindex', $meta);
        $this->assertStringContainsString('nofollow', $meta);
    }

    // ----- canonical ----------------------------------------------------------------------------

    #[Test]
    public function an_explicit_canonical_wins(): void
    {
        Seo_Service_Head::forRow($this->page(['canonical' => 'https://canonical.test/page']), $this->request('/other'));
        $link = $this->view->headLink()->toString();
        $this->assertStringContainsString('rel="canonical"', $link);
        $this->assertStringContainsString('https://canonical.test/page', $link);
    }

    #[Test]
    public function a_missing_canonical_self_references_the_request_path(): void
    {
        Seo_Service_Head::forRow($this->page([]), $this->request('/blog/hello?utm=x'));
        $link = $this->view->headLink()->toString();
        $this->assertStringContainsString('https://example.test/blog/hello', $link, 'path only, query dropped');
        $this->assertStringNotContainsString('utm=x', $link);
    }

    // ----- Open Graph + article -----------------------------------------------------------------

    #[Test]
    public function an_article_page_emits_og_type_article_and_times(): void
    {
        $page = $this->page(
            ['title' => 'My Article'],
            ['type' => 'article', 'published_at' => '2026-01-02 03:04:05', 'updated_at' => '2026-02-03 04:05:06']
        );
        Seo_Service_Head::forRow($page, $this->request('/blog/my-article'));
        $meta = $this->view->headMeta()->toString();
        $this->assertStringContainsString('article', $meta);
        $this->assertStringContainsString('article:published_time', $meta);
        $this->assertStringContainsString('article:modified_time', $meta);
        $this->assertStringContainsString('2026', $meta);
    }

    // ----- og:image -----------------------------------------------------------------------------

    #[Test]
    public function an_absolute_og_image_url_is_used_and_earns_the_large_card(): void
    {
        Seo_Service_Head::forRow(
            $this->page(['og_image_id' => 'https://cdn.test/share.png']),
            $this->request()
        );
        $meta = $this->view->headMeta()->toString();
        $this->assertStringContainsString('og:image', $meta);
        $this->assertStringContainsString('https://cdn.test/share.png', $meta);
        $this->assertStringContainsString('summary_large_image', $meta, 'a resolved image → large card');
    }

    #[Test]
    public function the_site_wide_fallback_og_image_is_used_when_the_page_has_none(): void
    {
        $this->config(['seo' => ['og_image' => 'https://cdn.test/default.png']]);
        Seo_Service_Head::forRow($this->page([]), $this->request());
        $this->assertStringContainsString('https://cdn.test/default.png', $this->view->headMeta()->toString());
    }

    #[Test]
    public function an_og_image_by_media_id_resolves_dimensions_and_mime(): void
    {
        $this->loginAs('admin');   // so the media insert carries an actor/org
        $mediaId = (new Tiger_Model_Media())->insert([
            'org_id'      => 'org-test',
            'filename'    => 'hero.jpg',
            'mime_type'   => 'image/jpeg',
            'storage_key' => 'seo/hero.jpg',
            'disk'        => 'local',
            'kind'        => 'image',
            'width'       => 1200,
            'height'      => 630,
            'alt_text'    => 'A hero image',
        ]);

        Seo_Service_Head::forRow($this->page(['og_image_id' => $mediaId]), $this->request());
        $meta = $this->view->headMeta()->toString();
        $this->assertStringContainsString('og:image', $meta);
        $this->assertStringContainsString('og:image:width', $meta);
        $this->assertStringContainsString('1200', $meta);
        $this->assertStringContainsString('og:image:height', $meta);
        $this->assertStringContainsString('630', $meta);
        $this->assertStringContainsString('og:image:type', $meta);
        $this->assertStringContainsString('image/jpeg', $meta);
        $this->assertStringContainsString('A hero image', $meta, 'alt from the media row');
        // A relative storage URL is absolutized against the request host.
        $this->assertStringContainsString('example.test', $meta);
    }

    #[Test]
    public function an_unresolvable_media_og_image_is_omitted(): void
    {
        // A media id that doesn't resolve → no og:image, and the plain (not large) twitter card.
        Seo_Service_Head::forRow($this->page(['og_image_id' => 'no-such-media-id']), $this->request());
        $meta = $this->view->headMeta()->toString();
        $this->assertStringNotContainsString('og:image', $meta);
        $this->assertStringContainsString('summary', $meta);
        $this->assertStringNotContainsString('summary_large_image', $meta);
    }

    #[Test]
    public function without_a_request_no_canonical_or_og_url_is_emitted(): void
    {
        Seo_Service_Head::forRow($this->page(['title' => 'No Request']));
        $this->assertStringNotContainsString('rel="canonical"', $this->view->headLink()->toString());
        $this->assertStringNotContainsString('og:url', $this->view->headMeta()->toString());
        $this->assertStringContainsString('og:title', $this->view->headMeta()->toString(), 'the rest still emits');
    }

    // ----- overrides fill blanks only -----------------------------------------------------------

    #[Test]
    public function overrides_fill_only_blank_seo_fields(): void
    {
        // Author set a description; the caller override (an excerpt) must NOT replace it.
        Seo_Service_Head::forRow(
            $this->page(['description' => 'Author description']),
            $this->request(),
            ['description' => 'Excerpt fallback', 'title' => 'Fallback Title']
        );
        $meta  = $this->view->headMeta()->toString();
        $title = $this->view->headTitle()->toString();
        $this->assertStringContainsString('Author description', $meta, 'author value kept');
        $this->assertStringNotContainsString('Excerpt fallback', $meta, 'override did not overwrite a set value');
        $this->assertStringContainsString('Fallback Title', $title, 'override filled the blank title');
    }
}
