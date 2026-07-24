<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_Config;
use Tiger_Uuid;

/**
 * Tiger_Model_Config — the top tier of the config cascade (the DB override layer, migration 0009).
 *
 * The load-bearing property is SCOPE ISOLATION: the same dot-notation key resolves independently per
 * (scope, scope_id), so a `global` value and an `org`-scoped value for that key never collide — this
 * is the exact mechanism that makes per-org theming work (an org's `tiger.skin` row reskins only that
 * org). Also proves upsert-in-place (set() overwrites, doesn't duplicate) and the unknown-key null.
 */
#[CoversClass(Tiger_Model_Config::class)]
final class ConfigTest extends IntegrationTestCase
{
    private Tiger_Model_Config $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = new Tiger_Model_Config();
    }

    #[Test]
    public function a_global_and_an_org_value_for_the_same_key_do_not_collide(): void
    {
        $orgId = Tiger_Uuid::v7();
        $key   = 'tiger.skin';

        $this->config->set(Tiger_Model_Config::SCOPE_GLOBAL, '', $key, 'jaguar');
        $this->config->set(Tiger_Model_Config::SCOPE_ORG, $orgId, $key, 'cheetah');

        // Each scope resolves to its OWN value — the per-org theming guarantee.
        $this->assertSame('jaguar', $this->config->get(Tiger_Model_Config::SCOPE_GLOBAL, '', $key));
        $this->assertSame('cheetah', $this->config->get(Tiger_Model_Config::SCOPE_ORG, $orgId, $key));
    }

    #[Test]
    public function two_orgs_with_the_same_key_are_isolated_from_each_other(): void
    {
        $orgA = Tiger_Uuid::v7();
        $orgB = Tiger_Uuid::v7();

        $this->config->set(Tiger_Model_Config::SCOPE_ORG, $orgA, 'site.name', 'Acme');
        $this->config->set(Tiger_Model_Config::SCOPE_ORG, $orgB, 'site.name', 'Beta');

        $this->assertSame('Acme', $this->config->get(Tiger_Model_Config::SCOPE_ORG, $orgA, 'site.name'));
        $this->assertSame('Beta', $this->config->get(Tiger_Model_Config::SCOPE_ORG, $orgB, 'site.name'));
        // A tenant with no row of its own gets null, never a neighbour's value.
        $this->assertNull($this->config->get(Tiger_Model_Config::SCOPE_ORG, Tiger_Uuid::v7(), 'site.name'));
    }

    #[Test]
    public function set_upserts_in_place_rather_than_inserting_a_duplicate(): void
    {
        $key = 'tiger.session.ttl';

        $firstId  = $this->config->set(Tiger_Model_Config::SCOPE_GLOBAL, '', $key, '3600');
        $secondId = $this->config->set(Tiger_Model_Config::SCOPE_GLOBAL, '', $key, '7200');

        $this->assertSame($firstId, $secondId, 'a second set() updates the SAME row (upsert)');
        $this->assertSame('7200', $this->config->get(Tiger_Model_Config::SCOPE_GLOBAL, '', $key), 'last write wins');

        // Exactly one active row for the (scope, scope_id, key) triple.
        $rows = $this->config->fetchAll(
            $this->config->activeSelect()
                ->where('scope = ?', Tiger_Model_Config::SCOPE_GLOBAL)
                ->where('scope_id = ?', '')
                ->where('config_key = ?', $key)
        );
        $this->assertCount(1, $rows, 'no duplicate row is created');
    }

    #[Test]
    public function an_unknown_key_resolves_to_null(): void
    {
        $this->assertNull(
            $this->config->get(Tiger_Model_Config::SCOPE_GLOBAL, '', 'tiger.nonexistent.' . Tiger_Uuid::v4()),
            'an unset key returns null'
        );
    }

    #[Test]
    public function getForScope_returns_only_that_scopes_rows(): void
    {
        $orgId = Tiger_Uuid::v7();
        $this->config->set(Tiger_Model_Config::SCOPE_GLOBAL, '', 'g.one', '1');
        $this->config->set(Tiger_Model_Config::SCOPE_ORG, $orgId, 'o.one', '1');
        $this->config->set(Tiger_Model_Config::SCOPE_ORG, $orgId, 'o.two', '2');

        $orgRows = $this->config->getForScope(Tiger_Model_Config::SCOPE_ORG, $orgId);
        $keys = [];
        foreach ($orgRows as $r) { $keys[] = $r->config_key; }
        sort($keys);
        $this->assertSame(['o.one', 'o.two'], $keys, 'only this org scope\'s rows, not the global one');
    }
}
