<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\Profile;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Profile_Tabs;

/**
 * Tiger_Profile_Tabs — the open tab registry behind the user/org self-service profile screens. A pure
 * static registry: modules `register()` a tab into a context and the screen reads `all()` (sorted).
 * Tests cover registration + defaults, ordering, key-override (last wins), the guards (unknown context,
 * keyless tab), and context isolation. `reset()` (the documented test seam) keeps runs independent.
 */
#[CoversClass(Tiger_Profile_Tabs::class)]
final class TabsTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tiger_Profile_Tabs::reset();
    }

    protected function tearDown(): void
    {
        Tiger_Profile_Tabs::reset();
        parent::tearDown();
    }

    #[Test]
    public function a_registered_tab_is_returned_with_defaults_filled(): void
    {
        Tiger_Profile_Tabs::register(Tiger_Profile_Tabs::CONTEXT_USER, ['key' => 'basic', 'view' => 'index/_basic']);

        $tabs = Tiger_Profile_Tabs::all(Tiger_Profile_Tabs::CONTEXT_USER);
        $this->assertCount(1, $tabs);
        $tab = $tabs[0];
        $this->assertSame('basic', $tab['key']);
        $this->assertSame('basic', $tab['label'], 'label defaults to the key');
        $this->assertSame('fa-circle', $tab['icon'], 'a default icon is filled');
        $this->assertSame(100, $tab['order'], 'a default order is filled');
        $this->assertSame('index/_basic', $tab['view']);
    }

    #[Test]
    public function tabs_are_ordered_by_order_then_label(): void
    {
        Tiger_Profile_Tabs::register(Tiger_Profile_Tabs::CONTEXT_USER, ['key' => 'z', 'label' => 'Zed', 'order' => 10]);
        Tiger_Profile_Tabs::register(Tiger_Profile_Tabs::CONTEXT_USER, ['key' => 'a', 'label' => 'Alpha', 'order' => 50]);
        Tiger_Profile_Tabs::register(Tiger_Profile_Tabs::CONTEXT_USER, ['key' => 'b', 'label' => 'Beta', 'order' => 10]);

        $keys = array_column(Tiger_Profile_Tabs::all(Tiger_Profile_Tabs::CONTEXT_USER), 'key');
        // order 10 first (Beta before Zed alphabetically), then order 50.
        $this->assertSame(['b', 'z', 'a'], $keys);
    }

    #[Test]
    public function re_registering_a_key_overrides_the_previous_tab(): void
    {
        Tiger_Profile_Tabs::register(Tiger_Profile_Tabs::CONTEXT_USER, ['key' => 'basic', 'label' => 'Original']);
        Tiger_Profile_Tabs::register(Tiger_Profile_Tabs::CONTEXT_USER, ['key' => 'basic', 'label' => 'Replaced']);

        $tabs = Tiger_Profile_Tabs::all(Tiger_Profile_Tabs::CONTEXT_USER);
        $this->assertCount(1, $tabs, 'same key does not duplicate');
        $this->assertSame('Replaced', $tabs[0]['label']);
    }

    #[Test]
    public function contexts_are_isolated(): void
    {
        Tiger_Profile_Tabs::register(Tiger_Profile_Tabs::CONTEXT_USER, ['key' => 'u']);
        Tiger_Profile_Tabs::register(Tiger_Profile_Tabs::CONTEXT_ORG,  ['key' => 'o']);

        $this->assertSame(['u'], array_column(Tiger_Profile_Tabs::all(Tiger_Profile_Tabs::CONTEXT_USER), 'key'));
        $this->assertSame(['o'], array_column(Tiger_Profile_Tabs::all(Tiger_Profile_Tabs::CONTEXT_ORG), 'key'));
    }

    #[Test]
    public function an_unknown_context_is_a_no_op_and_reads_empty(): void
    {
        Tiger_Profile_Tabs::register('nonsense', ['key' => 'x']);
        $this->assertSame([], Tiger_Profile_Tabs::all('nonsense'), 'unknown context reads empty');
        // registering into it did not leak into a real context
        $this->assertSame([], Tiger_Profile_Tabs::all(Tiger_Profile_Tabs::CONTEXT_USER));
    }

    #[Test]
    public function a_keyless_tab_is_ignored(): void
    {
        Tiger_Profile_Tabs::register(Tiger_Profile_Tabs::CONTEXT_USER, ['label' => 'No Key']);
        $this->assertSame([], Tiger_Profile_Tabs::all(Tiger_Profile_Tabs::CONTEXT_USER));
    }
}
