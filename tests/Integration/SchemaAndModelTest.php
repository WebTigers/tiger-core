<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Db_Migrator;
use Tiger_Model_Org;
use Tiger_Model_Table;
use Tiger_Uuid;

/**
 * Proves the integration harness end-to-end AND the load-bearing Tiger_Model_Table contract that
 * every model inherits: migrations build the schema, insert() mints a v7 UUID + stamps the actor/org,
 * and soft-delete hides a row from the default reads while restore() brings it back.
 *
 * This is the canonical first DB test (COVERAGE-PLAN §8 step 4) — the base class it exercises is the
 * one whose bugs would corrupt tenant isolation across the whole platform.
 */
#[CoversClass(Tiger_Db_Migrator::class)]
#[CoversClass(Tiger_Model_Table::class)]
final class SchemaAndModelTest extends IntegrationTestCase
{
    #[Test]
    public function migrations_build_the_core_schema(): void
    {
        foreach (['tiger_migration', 'org', 'user', 'org_user', 'acl_rule', 'config', 'page'] as $table) {
            $this->assertTrue($this->tableExists($table), "expected core table `$table` after migrate");
        }
    }

    #[Test]
    public function insert_mints_a_v7_uuid_and_stamps_actor_and_org(): void
    {
        $actor = Tiger_Uuid::v7();
        $orgId = Tiger_Uuid::v7();
        Tiger_Model_Table::setActor($actor);
        Tiger_Model_Table::setOrg($orgId);

        $org = new Tiger_Model_Org();
        $id = $org->insert(['name' => 'Acme', 'slug' => 'acme-' . substr($actor, 0, 8)]);

        $this->assertTrue(Tiger_Uuid::isValid($id), 'insert must return a valid UUID PK');
        $row = $this->db->fetchRow('SELECT * FROM org WHERE org_id = ?', [$id]);
        $this->assertNotEmpty($row);
        $this->assertSame($actor, $row['created_by'], 'created_by must be stamped from the actor');
        $this->assertSame('acme-' . substr($actor, 0, 8), $row['slug']);
        $this->assertNotNull($row['created_at'], 'created_at must be stamped');
        $this->assertSame(0, (int) $row['deleted']);
    }

    #[Test]
    public function findById_excludes_soft_deleted_rows_by_default(): void
    {
        $org = new Tiger_Model_Org();
        $id = $org->insert(['name' => 'Temp', 'slug' => 'temp-' . substr(Tiger_Uuid::v7(), 0, 8)]);

        $this->assertNotEmpty($org->findById($id), 'a live row must be found');

        $org->softDelete(['org_id = ?' => $id]);
        $this->assertEmpty($org->findById($id), 'soft-deleted row must be hidden from the default read');
        $this->assertNotEmpty($org->findById($id, true), 'but visible when deleted rows are included');

        $org->restore(['org_id = ?' => $id]);
        $this->assertNotEmpty($org->findById($id), 'restore must bring it back');
    }

    #[Test]
    public function migrate_is_idempotent(): void
    {
        // The harness already migrated in setUp; a second run must apply nothing.
        $migrator = new Tiger_Db_Migrator($this->db, [dirname(__DIR__, 2) . '/migrations']);
        $this->assertSame([], $migrator->migrate(), 'a second migrate() must be a no-op');
    }
}
