<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\Routing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Routing_Overrides;

/**
 * Tiger_Routing_Overrides — the pretty-route registry that turns a public prefix into a canonical
 * `module/controller/action` target. A pure static registry with a config-override tier, so these
 * tests exercise it directly (no DB, no dispatch).
 *
 * The load-bearing behaviors: register() + all() (only ENABLED, VALID entries, sorted priority DESC
 * with a name tie-break), the mca/prefix parse, the config tier winning over the module default, and
 * — the security spine — the reserved-prefix guard that means a module can NEVER hijack the kernel
 * surfaces (`/api`, `/auth`, `/admin`), no matter how high a priority it declares.
 *
 * The registry is process-global; clear() resets the declared tier between tests, and each test that
 * needs the config tier passes an explicit Zend_Config (never leaning on registry order).
 */
#[CoversClass(Tiger_Routing_Overrides::class)]
final class OverridesTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tiger_Routing_Overrides::clear();
    }

    protected function tearDown(): void
    {
        Tiger_Routing_Overrides::clear();   // don't leak a declaration into the next test
        parent::tearDown();
    }

    #[Test]
    public function a_registered_override_surfaces_in_all_with_a_parsed_prefix_and_target(): void
    {
        Tiger_Routing_Overrides::register('docs', [
            'pattern'  => 'docs',
            'target'   => 'docs/index/docs',
            'priority' => 100,
        ]);

        $all = Tiger_Routing_Overrides::all();
        $this->assertCount(1, $all);
        $this->assertSame('docs', $all[0]['name']);
        $this->assertSame('docs', $all[0]['prefix']);
        $this->assertSame('docs/index/docs', $all[0]['target']);
        $this->assertSame(['docs', 'index', 'docs'], $all[0]['mca']);
        $this->assertTrue($all[0]['enabled']);
    }

    #[Test]
    public function all_is_sorted_by_priority_desc_then_name_asc(): void
    {
        // deliberately register low-priority first, and give two the SAME priority to prove the
        // name tie-break (ASC) kicks in.
        Tiger_Routing_Overrides::register('low',   ['pattern' => 'low',   'target' => 'low/i/i',   'priority' => 10]);
        Tiger_Routing_Overrides::register('zebra', ['pattern' => 'zebra', 'target' => 'zebra/i/i', 'priority' => 100]);
        Tiger_Routing_Overrides::register('alpha', ['pattern' => 'alpha', 'target' => 'alpha/i/i', 'priority' => 100]);

        $order = array_map(static fn ($s) => $s['name'], Tiger_Routing_Overrides::all());
        // 100:alpha, 100:zebra (name ASC within a priority), then 10:low
        $this->assertSame(['alpha', 'zebra', 'low'], $order);
    }

    #[Test]
    public function a_reserved_prefix_can_never_be_claimed_even_at_max_priority(): void
    {
        // The whole security point: a module aiming an override at a kernel surface is dropped from
        // all(), so it can never shadow /api, /auth, or /admin — priority is irrelevant.
        foreach (['api', 'auth', 'admin'] as $reserved) {
            Tiger_Routing_Overrides::clear();
            Tiger_Routing_Overrides::register('evil', [
                'pattern'  => $reserved,
                'target'   => 'evil/index/index',
                'priority' => 999999,
            ]);
            $this->assertSame([], Tiger_Routing_Overrides::all(), "prefix `$reserved` must be refused");
        }
    }

    #[Test]
    public function a_reserved_prefix_is_refused_by_first_path_segment_not_the_whole_string(): void
    {
        // `admin/anything` still heads into the reserved `admin` surface — the guard looks at the
        // first segment, so a nested path can't sneak past it.
        Tiger_Routing_Overrides::register('sneaky', [
            'pattern' => 'admin/reports',
            'target'  => 'sneaky/index/index',
        ]);
        $this->assertSame([], Tiger_Routing_Overrides::all());
    }

    #[Test]
    public function invalid_declarations_missing_a_prefix_or_target_are_dropped(): void
    {
        Tiger_Routing_Overrides::register('nopattern', ['pattern' => '',      'target' => 'x/y/z']);
        Tiger_Routing_Overrides::register('notarget',  ['pattern' => 'thing', 'target' => '']);
        $this->assertSame([], Tiger_Routing_Overrides::all());
    }

    #[Test]
    public function a_disabled_override_is_excluded_from_all_but_still_resolvable_via_get(): void
    {
        Tiger_Routing_Overrides::register('docs', [
            'pattern' => 'docs',
            'target'  => 'docs/index/docs',
            'enabled' => false,
        ]);

        $this->assertSame([], Tiger_Routing_Overrides::all(), 'disabled => not walked');

        // get() reports the resolved spec REGARDLESS of enabled (the admin settings screen needs it).
        $spec = Tiger_Routing_Overrides::get('docs');
        $this->assertNotNull($spec);
        $this->assertFalse($spec['enabled']);
        $this->assertSame('docs', $spec['prefix']);
    }

    #[Test]
    public function the_prefix_is_derived_by_stripping_trailing_route_vars(): void
    {
        Tiger_Routing_Overrides::register('a', ['pattern' => 'docs/:slug', 'target' => 'docs/index/docs']);
        Tiger_Routing_Overrides::register('b', ['pattern' => 'help/*',     'target' => 'docs/index/docs']);

        $this->assertSame('docs', Tiger_Routing_Overrides::get('a')['prefix']);
        $this->assertSame('help', Tiger_Routing_Overrides::get('b')['prefix']);
    }

    #[Test]
    public function the_mca_parse_pads_a_short_target_with_index(): void
    {
        Tiger_Routing_Overrides::register('bare', ['pattern' => 'bare', 'target' => 'bare']);
        Tiger_Routing_Overrides::register('two',  ['pattern' => 'two',  'target' => 'shop/cart']);

        $this->assertSame(['bare', 'index', 'index'], Tiger_Routing_Overrides::get('bare')['mca']);
        $this->assertSame(['shop', 'cart', 'index'],  Tiger_Routing_Overrides::get('two')['mca']);
    }

    #[Test]
    public function the_override_name_is_normalized_to_a_safe_key(): void
    {
        // register() strips non-alnum/underscore and lowercases — so `My-Doc!` is stored as `mydoc`.
        Tiger_Routing_Overrides::register('My-Doc!', ['pattern' => 'md', 'target' => 'md/i/i']);
        $this->assertNotNull(Tiger_Routing_Overrides::get('mydoc'));
        $this->assertSame('mydoc', Tiger_Routing_Overrides::all()[0]['name']);
    }

    #[Test]
    public function get_returns_null_when_neither_a_declaration_nor_config_exists(): void
    {
        $this->assertNull(Tiger_Routing_Overrides::get('ghost'));
    }

    #[Test]
    public function the_config_tier_retargets_the_pattern_over_the_module_default(): void
    {
        Tiger_Routing_Overrides::register('docs', ['pattern' => 'docs', 'target' => 'docs/index/docs']);

        // admin retargets /docs -> /help via the config override tier (no deploy).
        $config = $this->setConfig(['tiger' => ['routing' => ['override' => [
            'docs' => ['pattern' => 'help'],
        ]]]]);

        $spec = Tiger_Routing_Overrides::get('docs', $config);
        $this->assertSame('help', $spec['prefix'], 'config pattern wins');
        $this->assertSame('docs/index/docs', $spec['target'], 'untouched fields keep the default');

        // and all() serves it at the new prefix.
        $all = Tiger_Routing_Overrides::all($config);
        $this->assertSame('help', $all[0]['prefix']);
    }

    #[Test]
    public function the_config_tier_can_disable_a_declared_override(): void
    {
        Tiger_Routing_Overrides::register('docs', ['pattern' => 'docs', 'target' => 'docs/index/docs']);

        // enabled comes off config as `(bool)(int)` — the string "0" must switch it OFF.
        $config = $this->setConfig(['tiger' => ['routing' => ['override' => [
            'docs' => ['enabled' => '0'],
        ]]]]);

        $this->assertFalse(Tiger_Routing_Overrides::get('docs', $config)['enabled']);
        $this->assertSame([], Tiger_Routing_Overrides::all($config));
    }

    #[Test]
    public function the_config_tier_can_reprioritize_against_another_override(): void
    {
        Tiger_Routing_Overrides::register('docs', ['pattern' => 'docs', 'target' => 'docs/i/i', 'priority' => 100]);
        Tiger_Routing_Overrides::register('shop', ['pattern' => 'shop', 'target' => 'shop/i/i', 'priority' => 200]);

        // config bumps docs above shop.
        $config = $this->setConfig(['tiger' => ['routing' => ['override' => [
            'docs' => ['priority' => '250'],
        ]]]]);

        $order = array_map(static fn ($s) => $s['name'], Tiger_Routing_Overrides::all($config));
        $this->assertSame(['docs', 'shop'], $order);
    }

    #[Test]
    public function even_a_config_only_override_still_obeys_the_reserved_guard(): void
    {
        // No declaration at all — the entry exists purely in config, and it aims at /auth. It must
        // still be refused: the guard is not a property of "being declared in code".
        $config = $this->setConfig(['tiger' => ['routing' => ['override' => [
            'takeover' => ['pattern' => 'auth', 'target' => 'takeover/index/index'],
        ]]]]);

        $this->assertSame([], Tiger_Routing_Overrides::all($config));
    }

    #[Test]
    public function clear_resets_the_declared_registry(): void
    {
        Tiger_Routing_Overrides::register('docs', ['pattern' => 'docs', 'target' => 'docs/i/i']);
        $this->assertCount(1, Tiger_Routing_Overrides::all());

        Tiger_Routing_Overrides::clear();
        $this->assertSame([], Tiger_Routing_Overrides::all());
    }
}
