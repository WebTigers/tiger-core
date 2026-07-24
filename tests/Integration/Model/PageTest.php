<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_Page;
use Tiger_Uuid;

/**
 * Tiger_Model_Page — the CMS content store and its slug resolver (FEATURES §Content, ARCHITECTURE §3a).
 *
 * resolveBySlug() is the public-dispatch gate, and every assertion here is a real
 * security/tenancy invariant that a leak would violate:
 *   - the ORG CASCADE (a tenant row wins over global '' for the same slug; a *different* tenant, with
 *     no own row, falls back to global) — the "sees its own, not another tenant's" boundary,
 *   - the PUBLISH+SCHEDULE gate (draft / future-`published_at` / archived / soft-deleted rows are never
 *     served; a published, past/NULL-scheduled row is),
 *   - the three `type` primitives (page/layout/partial) and the body `format` field storing intact
 *     (html/markdown/phtml — the phtml body is NEVER executed here; we assert byte-for-byte round-trip).
 *
 * Org ids are literal v7 UUIDs (the column is a plain VARCHAR scope tag) so the tests never depend on
 * Tiger_Model_Org::siteOrgId() — resolveBySlug only consults it for a blank org, which we never pass.
 */
#[CoversClass(Tiger_Model_Page::class)]
final class PageTest extends IntegrationTestCase
{
    private Tiger_Model_Page $page;

    protected function setUp(): void
    {
        parent::setUp();
        $this->page = new Tiger_Model_Page();
    }

    /** A DATETIME string offset from now by a day-scale delta (safe past/future well beyond any tz skew). */
    private function at(string $modify): string
    {
        return date('Y-m-d H:i:s', strtotime($modify));
    }

    private function insertPage(array $overrides): string
    {
        return $this->page->insert(array_merge([
            'org_id' => '',
            'type'   => Tiger_Model_Page::TYPE_PAGE,
            'locale' => 'en',
            'format' => Tiger_Model_Page::FORMAT_HTML,
            'status' => Tiger_Model_Page::STATUS_PUBLISHED,
        ], $overrides));
    }

    #[Test]
    public function a_tenant_page_overrides_the_global_page_for_the_same_slug(): void
    {
        $orgA = Tiger_Uuid::v7();
        $orgB = Tiger_Uuid::v7();

        $globalId = $this->insertPage(['org_id' => '',    'slug' => 'about', 'title' => 'Global About']);
        $tenantId = $this->insertPage(['org_id' => $orgA, 'slug' => 'about', 'title' => 'Acme About']);

        // The owning tenant sees ITS row (org wins over the global '' via ORDER BY org_id DESC).
        $seenByA = $this->page->resolveBySlug('about', 'en', $orgA);
        $this->assertNotNull($seenByA);
        $this->assertSame($tenantId, $seenByA->page_id, 'the tenant gets its own override');
        $this->assertSame($orgA, $seenByA->org_id);

        // A DIFFERENT tenant has no own row → it falls back to the shared global page, never Acme's.
        $seenByB = $this->page->resolveBySlug('about', 'en', $orgB);
        $this->assertNotNull($seenByB);
        $this->assertSame($globalId, $seenByB->page_id, 'a foreign tenant never sees another tenant\'s page');
        $this->assertSame('', $seenByB->org_id);
    }

    #[Test]
    public function an_unpublished_or_future_scheduled_page_is_not_served(): void
    {
        $org = Tiger_Uuid::v7();

        $draft = $this->insertPage(['org_id' => $org, 'slug' => 'draft', 'status' => Tiger_Model_Page::STATUS_DRAFT]);
        $this->assertNull($this->page->resolveBySlug('draft', 'en', $org), 'a draft is never served');

        // Published but scheduled for the future → not yet live.
        $future = $this->insertPage(['org_id' => $org, 'slug' => 'future', 'published_at' => $this->at('+1 day')]);
        $this->assertNull($this->page->resolveBySlug('future', 'en', $org), 'a future published_at is scheduled, not live');

        // Published, schedule already arrived → live.
        $past = $this->insertPage(['org_id' => $org, 'slug' => 'past', 'published_at' => $this->at('-1 day')]);
        $livePast = $this->page->resolveBySlug('past', 'en', $org);
        $this->assertNotNull($livePast, 'a past published_at is live');
        $this->assertSame($past, $livePast->page_id);

        // Published with NULL schedule → live immediately.
        $now = $this->insertPage(['org_id' => $org, 'slug' => 'now', 'published_at' => null]);
        $liveNow = $this->page->resolveBySlug('now', 'en', $org);
        $this->assertNotNull($liveNow, 'a NULL published_at is immediately live');
        $this->assertSame($now, $liveNow->page_id);
    }

    #[Test]
    public function archived_and_soft_deleted_pages_are_excluded_from_the_resolver(): void
    {
        $org = Tiger_Uuid::v7();

        $archived = $this->insertPage(['org_id' => $org, 'slug' => 'archived', 'status' => Tiger_Model_Page::STATUS_ARCHIVED]);
        $this->assertNull($this->page->resolveBySlug('archived', 'en', $org), 'an archived page is not served');

        $live = $this->insertPage(['org_id' => $org, 'slug' => 'gone', 'title' => 'Live']);
        $this->assertNotNull($this->page->resolveBySlug('gone', 'en', $org), 'sanity: it resolves while live');

        $this->page->softDelete(['page_id = ?' => $live]);
        $this->assertNull($this->page->resolveBySlug('gone', 'en', $org), 'a soft-deleted page drops out of the resolver');
    }

    #[Test]
    public function the_type_filter_distinguishes_page_layout_and_partial(): void
    {
        $org = Tiger_Uuid::v7();

        // Three rows sharing one slug but each a different rendering primitive.
        $pageId    = $this->insertPage(['org_id' => $org, 'slug' => 'shared', 'type' => Tiger_Model_Page::TYPE_PAGE, 'page_key' => null]);
        $layoutId  = $this->insertPage(['org_id' => $org, 'slug' => null, 'page_key' => 'shared-layout',  'type' => Tiger_Model_Page::TYPE_LAYOUT]);
        $partialId = $this->insertPage(['org_id' => $org, 'slug' => null, 'page_key' => 'shared-partial', 'type' => Tiger_Model_Page::TYPE_PARTIAL]);

        // Root slug dispatch asks for a real page and must get the page, not a layout/partial.
        $resolved = $this->page->resolveBySlug('shared', 'en', $org, Tiger_Model_Page::TYPE_PAGE);
        $this->assertNotNull($resolved);
        $this->assertSame($pageId, $resolved->page_id);
        $this->assertSame(Tiger_Model_Page::TYPE_PAGE, $resolved->type);

        // Restricting to the wrong type finds nothing at that slug.
        $this->assertNull($this->page->resolveBySlug('shared', 'en', $org, Tiger_Model_Page::TYPE_LAYOUT));

        // The layout/partial are fetchable by their stable handle (not publish-gated infrastructure).
        $layout = $this->page->fetchByKey('shared-layout', 'en', $org, Tiger_Model_Page::TYPE_LAYOUT);
        $this->assertNotNull($layout);
        $this->assertSame($layoutId, $layout->page_id);
        $partial = $this->page->fetchByKey('shared-partial', 'en', $org, Tiger_Model_Page::TYPE_PARTIAL);
        $this->assertSame($partialId, $partial->page_id);
    }

    #[Test]
    public function the_body_and_format_round_trip_intact_without_executing_phtml(): void
    {
        $org = Tiger_Uuid::v7();

        // A phtml body that WOULD do damage if the store ever evaluated it. It must not — this is a
        // data gateway; we assert exact byte-for-byte storage, proving no rendering happens here.
        $phtmlBody = '<?php echo "EXECUTED-" . (1+1); /* must be stored, never run */ ?>Hello';
        $mdBody    = "# Heading\n\nSome **markdown** with a [shortcode].";
        $htmlBody  = '<p>Plain <em>html</em> body &amp; entity.</p>';

        $phtmlId = $this->insertPage(['org_id' => $org, 'slug' => 'code', 'format' => Tiger_Model_Page::FORMAT_PHTML, 'body' => $phtmlBody]);
        $mdId    = $this->insertPage(['org_id' => $org, 'slug' => 'md',   'format' => Tiger_Model_Page::FORMAT_MARKDOWN, 'body' => $mdBody]);
        $htmlId  = $this->insertPage(['org_id' => $org, 'slug' => 'html', 'format' => Tiger_Model_Page::FORMAT_HTML, 'body' => $htmlBody]);

        $phtml = $this->page->findById($phtmlId);
        $this->assertSame(Tiger_Model_Page::FORMAT_PHTML, $phtml->format);
        $this->assertSame($phtmlBody, $phtml->body, 'phtml source is stored verbatim, never executed');
        $this->assertStringNotContainsString('EXECUTED-2', $phtml->body, 'the code must not have run');

        $md = $this->page->findById($mdId);
        $this->assertSame(Tiger_Model_Page::FORMAT_MARKDOWN, $md->format);
        $this->assertSame($mdBody, $md->body);

        $html = $this->page->findById($htmlId);
        $this->assertSame(Tiger_Model_Page::FORMAT_HTML, $html->format);
        $this->assertSame($htmlBody, $html->body);
    }

    #[Test]
    public function the_resolver_matches_on_locale(): void
    {
        $org = Tiger_Uuid::v7();
        // Same logical page, one row per language, sharing a page_key.
        $en = $this->insertPage(['org_id' => $org, 'slug' => 'welcome', 'locale' => 'en', 'page_key' => 'welcome']);
        $es = $this->insertPage(['org_id' => $org, 'slug' => 'welcome', 'locale' => 'es', 'page_key' => 'welcome']);

        $this->assertSame($en, $this->page->resolveBySlug('welcome', 'en', $org)->page_id);
        $this->assertSame($es, $this->page->resolveBySlug('welcome', 'es', $org)->page_id);
        $this->assertNull($this->page->resolveBySlug('welcome', 'fr', $org), 'an unmatched locale resolves nothing');
    }
}
