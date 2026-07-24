<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\Module;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Module_Registry;

/**
 * Tiger_Module_Registry — the client for the open Vendor Registry. Driven with NO network by PRE-SEEDING
 * the fresh file cache the client reads before it ever calls GitHub: a primed `registry-index.json` +
 * `registry-sponsored.json` make index()/sponsored()/search() resolve offline. Covered: search filtering,
 * the three orderings, curated-sponsor merge (priority + badge), repo-relative media resolution (logo /
 * screenshots / YouTube+Vimeo+mp4 video), and the config-overridable index/sponsor URLs. The genuine HTTP
 * fetch + the offline-null fallback are live territory, left to integration.
 */
#[CoversClass(Tiger_Module_Registry::class)]
final class RegistryTest extends UnitTestCase
{
    private string $cacheDir = '';
    /** Cache files written by a test (removed in tearDown). */
    private array $wrote = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheDir = rtrim(APPLICATION_ROOT, '/') . '/storage/cache';
        @mkdir($this->cacheDir, 0775, true);
        // A consistent sponsor overlay for the whole class (Registry::sponsored() memoizes per-process).
        $this->primeSponsored(['Acme_Widget' => ['priority' => 50, 'label' => 'Sponsored']]);
    }

    protected function tearDown(): void
    {
        foreach ($this->wrote as $f) { @unlink($f); }
        parent::tearDown();
    }

    private function primeIndex(array $index): void
    {
        $file = $this->cacheDir . '/registry-index.json';
        file_put_contents($file, json_encode($index));
        $this->wrote[] = $file;
    }

    private function primeSponsored(array $listings): void
    {
        $file = $this->cacheDir . '/registry-sponsored.json';
        file_put_contents($file, json_encode(['listings' => $listings]));
        $this->wrote[] = $file;
    }

    /** A minimal two-module index: one sponsored (Acme/Widget), one not (Beta/Gadget). */
    private function primeTwoModuleIndex(): void
    {
        $this->primeIndex(['modules' => [
            [
                'module'      => 'Widget',
                'slug'        => 'widget',
                'description' => 'A handy widget',
                'keywords'    => ['ui', 'tool'],
                'vendor'      => 'Acme',
                'type'        => 'plugin',
                'repository'  => 'https://github.com/Acme/Widget',
                'review'      => ['reviewed_at' => '2026-01-01'],
            ],
            [
                'module'      => 'Gadget',
                'slug'        => 'gadget',
                'description' => 'A shiny gadget',
                'keywords'    => ['theme'],
                'vendor'      => 'Beta',
                'type'        => 'theme',
                'repository'  => 'https://github.com/Beta/Gadget',
                'review'      => ['reviewed_at' => '2026-06-01'],
            ],
        ]]);
    }

    // ---- availability / index ----------------------------------------------

    #[Test]
    public function available_is_true_when_the_index_cache_is_fresh(): void
    {
        $this->primeTwoModuleIndex();
        $this->assertTrue(Tiger_Module_Registry::available());
        $this->assertIsArray(Tiger_Module_Registry::index());
    }

    // ---- search filtering ---------------------------------------------------

    #[Test]
    public function search_with_no_query_returns_every_module(): void
    {
        $this->primeTwoModuleIndex();
        $this->assertCount(2, Tiger_Module_Registry::search(''));
    }

    #[Test]
    public function search_matches_across_name_slug_description_keywords_vendor_and_type(): void
    {
        $this->primeTwoModuleIndex();
        $this->assertCount(1, Tiger_Module_Registry::search('widget'), 'name/slug hit');
        $this->assertCount(1, Tiger_Module_Registry::search('shiny'), 'description hit');
        $this->assertCount(1, Tiger_Module_Registry::search('theme'), 'keyword/type hit — Gadget');
        $this->assertSame([], Tiger_Module_Registry::search('nonexistent-term'), 'a miss returns []');
    }

    // ---- sponsor merge + orderings -----------------------------------------

    #[Test]
    public function search_merges_the_curated_sponsor_placement(): void
    {
        $this->primeTwoModuleIndex();
        $rows = Tiger_Module_Registry::search('widget');

        $this->assertSame(50, $rows[0]['priority'], 'the sponsored priority is attached');
        $this->assertTrue($rows[0]['sponsored']);
        $this->assertSame('Sponsored', $rows[0]['sponsored_label']);
    }

    #[Test]
    public function an_unsponsored_module_gets_zero_priority_and_no_badge(): void
    {
        $this->primeTwoModuleIndex();
        $gadget = Tiger_Module_Registry::search('gadget')[0];
        $this->assertSame(0, $gadget['priority']);
        $this->assertArrayNotHasKey('sponsored', $gadget);
    }

    #[Test]
    public function featured_sort_floats_the_sponsored_module_to_the_top(): void
    {
        $this->primeTwoModuleIndex();
        $rows = Tiger_Module_Registry::search('', 'featured');
        $this->assertSame('widget', $rows[0]['slug'], 'sponsored (priority 50) leads featured');
    }

    #[Test]
    public function title_sort_orders_alphabetically(): void
    {
        $this->primeTwoModuleIndex();
        $rows = Tiger_Module_Registry::search('', 'title');
        $this->assertSame(['gadget', 'widget'], [$rows[0]['slug'], $rows[1]['slug']]);
    }

    #[Test]
    public function latest_sort_orders_by_newest_review(): void
    {
        $this->primeTwoModuleIndex();
        $rows = Tiger_Module_Registry::search('', 'latest');
        $this->assertSame('gadget', $rows[0]['slug'], 'Gadget was reviewed more recently');
    }

    #[Test]
    public function an_unknown_sort_falls_back_to_featured(): void
    {
        $this->primeTwoModuleIndex();
        $rows = Tiger_Module_Registry::search('', 'bogus-sort');
        $this->assertSame('widget', $rows[0]['slug']);
    }

    // ---- media resolution ---------------------------------------------------

    #[Test]
    public function repo_relative_media_resolves_to_raw_urls_and_video_providers_become_embeds(): void
    {
        $this->primeIndex(['modules' => [[
            'module'      => 'Media',
            'slug'        => 'media',
            'repository'  => 'https://github.com/Acme/Media',
            'ref'         => 'v2.0.0',
            'logo'        => 'assets/logo.png',
            'hero'        => 'https://cdn.example/hero.png',   // already absolute — passed through
            'screenshots' => ['assets/a.png', 'assets/b.png'],
            'video'       => ['src' => 'https://youtu.be/abc123', 'poster' => 'assets/poster.png'],
        ]]]);

        $m = Tiger_Module_Registry::search('media')[0];
        $this->assertSame('https://raw.githubusercontent.com/Acme/Media/v2.0.0/assets/logo.png', $m['logo']);
        $this->assertSame('https://cdn.example/hero.png', $m['hero'], 'an absolute URL is untouched');
        $this->assertSame('https://raw.githubusercontent.com/Acme/Media/v2.0.0/assets/a.png', $m['screenshots'][0]);
        $this->assertSame('https://www.youtube-nocookie.com/embed/abc123', $m['video']['src']);
        $this->assertSame('iframe', $m['video']['type']);
        $this->assertStringStartsWith('https://raw.githubusercontent.com/Acme/Media/v2.0.0/', $m['video']['poster']);
    }

    #[Test]
    public function a_vimeo_video_becomes_a_player_embed_and_a_self_hosted_mp4_resolves_to_raw(): void
    {
        $this->primeIndex(['modules' => [
            ['module' => 'Vim', 'slug' => 'vim', 'repository' => 'https://github.com/Acme/Vim',
             'video' => 'https://vimeo.com/76543'],
            ['module' => 'Mp4', 'slug' => 'mp4', 'repository' => 'https://github.com/Acme/Mp4',
             'video' => 'media/demo.mp4'],
        ]]);

        $vim = Tiger_Module_Registry::search('vim')[0];
        $this->assertSame('https://player.vimeo.com/video/76543', $vim['video']['src']);
        $this->assertSame('iframe', $vim['video']['type']);

        $mp4 = Tiger_Module_Registry::search('mp4')[0];
        $this->assertStringEndsWith('/media/demo.mp4', $mp4['video']['src']);
        $this->assertSame('video', $mp4['video']['type']);
    }

    // ---- config-overridable endpoints --------------------------------------

    #[Test]
    public function index_and_sponsor_urls_default_then_honor_a_config_override(): void
    {
        $this->assertSame(Tiger_Module_Registry::DEFAULT_INDEX, Tiger_Module_Registry::indexUrl());
        $this->assertSame(Tiger_Module_Registry::DEFAULT_SPONSORS, Tiger_Module_Registry::sponsoredUrl());

        $this->setConfig(['tiger' => ['modules' => [
            'registry' => 'https://example.test/index.json',
            'sponsors' => 'https://example.test/sponsors.json',
        ]]]);
        $this->assertSame('https://example.test/index.json', Tiger_Module_Registry::indexUrl());
        $this->assertSame('https://example.test/sponsors.json', Tiger_Module_Registry::sponsoredUrl());
    }
}
