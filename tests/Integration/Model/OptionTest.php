<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_Option;
use Tiger_Uuid;

/**
 * Tiger_Model_Option — the LAZY, on-demand per-scope key/value tier (migration 0031), the sibling of
 * config (which is eager). Never folded into the request-wide cascade; a row is read only when its
 * owner asks. Same (scope, scope_id, key) discipline as config, so the same isolation contract holds:
 * one user's option never bleeds into another's. Also proves set/get round-trip, upsert-in-place, the
 * JSON helpers, and forget() (soft-delete → the key reads back null).
 */
#[CoversClass(Tiger_Model_Option::class)]
final class OptionTest extends IntegrationTestCase
{
    private Tiger_Model_Option $option;

    protected function setUp(): void
    {
        parent::setUp();
        $this->option = new Tiger_Model_Option();
    }

    #[Test]
    public function set_then_get_round_trips_a_value(): void
    {
        $user = Tiger_Uuid::v7();
        $this->option->set(Tiger_Model_Option::SCOPE_USER, $user, 'ui.sidebar', 'collapsed');

        $this->assertSame('collapsed', $this->option->get(Tiger_Model_Option::SCOPE_USER, $user, 'ui.sidebar'));
    }

    #[Test]
    public function the_same_key_is_isolated_across_scope_ids(): void
    {
        $userA = Tiger_Uuid::v7();
        $userB = Tiger_Uuid::v7();

        $this->option->set(Tiger_Model_Option::SCOPE_USER, $userA, 'dash.layout', 'A-layout');
        $this->option->set(Tiger_Model_Option::SCOPE_USER, $userB, 'dash.layout', 'B-layout');

        $this->assertSame('A-layout', $this->option->get(Tiger_Model_Option::SCOPE_USER, $userA, 'dash.layout'));
        $this->assertSame('B-layout', $this->option->get(Tiger_Model_Option::SCOPE_USER, $userB, 'dash.layout'));
        // A user who never set it gets null, never a neighbour's layout.
        $this->assertNull($this->option->get(Tiger_Model_Option::SCOPE_USER, Tiger_Uuid::v7(), 'dash.layout'));
    }

    #[Test]
    public function the_same_key_is_isolated_across_scope_types(): void
    {
        $id = Tiger_Uuid::v7();   // deliberately reuse the id across two DIFFERENT scope TYPES
        $this->option->set(Tiger_Model_Option::SCOPE_USER, $id, 'pref', 'user-value');
        $this->option->set(Tiger_Model_Option::SCOPE_ORG, $id, 'pref', 'org-value');

        $this->assertSame('user-value', $this->option->get(Tiger_Model_Option::SCOPE_USER, $id, 'pref'));
        $this->assertSame('org-value', $this->option->get(Tiger_Model_Option::SCOPE_ORG, $id, 'pref'));
    }

    #[Test]
    public function set_upserts_in_place(): void
    {
        $user = Tiger_Uuid::v7();
        $first  = $this->option->set(Tiger_Model_Option::SCOPE_USER, $user, 'wizard.step', '1');
        $second = $this->option->set(Tiger_Model_Option::SCOPE_USER, $user, 'wizard.step', '4');

        $this->assertSame($first, $second, 'a second set() updates the same row');
        $this->assertSame('4', $this->option->get(Tiger_Model_Option::SCOPE_USER, $user, 'wizard.step'));
    }

    #[Test]
    public function json_helpers_round_trip_structured_values(): void
    {
        $user = Tiger_Uuid::v7();
        $layout = ['cols' => 3, 'widgets' => ['clock', 'stats']];

        $this->option->setJson(Tiger_Model_Option::SCOPE_USER, $user, 'dash', $layout);

        $this->assertSame($layout, $this->option->getJson(Tiger_Model_Option::SCOPE_USER, $user, 'dash'));
        // getJson returns the default for a missing key.
        $this->assertSame(['fallback'], $this->option->getJson(Tiger_Model_Option::SCOPE_USER, $user, 'missing', ['fallback']));
    }

    #[Test]
    public function forget_soft_deletes_so_the_key_reads_null_again(): void
    {
        $user = Tiger_Uuid::v7();
        $this->option->set(Tiger_Model_Option::SCOPE_USER, $user, 'notice.dismissed', '1');
        $this->assertSame('1', $this->option->get(Tiger_Model_Option::SCOPE_USER, $user, 'notice.dismissed'));

        $this->option->forget(Tiger_Model_Option::SCOPE_USER, $user, 'notice.dismissed');

        $this->assertNull(
            $this->option->get(Tiger_Model_Option::SCOPE_USER, $user, 'notice.dismissed'),
            'a forgotten option is excluded from the active read'
        );
    }
}
