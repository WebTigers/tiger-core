<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_AuthChallenge;
use Tiger_Model_Media;
use Tiger_Model_Table;
use Tiger_Uuid;

/**
 * Tiger_Model_Table — THE base table-gateway every Tiger domain model inherits, exercised against a
 * real schema. Its contract is load-bearing for the whole platform: it mints the UUID PK, stamps the
 * actor/org/timestamps, and enforces the soft-delete tenant-safety invariant (deleted rows are
 * invisible to the active finders yet physically retained). A bug here corrupts identity, provenance,
 * or tenant isolation everywhere at once, so it is tested the hardest.
 *
 * The concrete vehicle is `Tiger_Model_Media` — a standard-columns v7 table that also carries `org_id`
 * (so it exercises the tenant stamp too) — plus `Tiger_Model_AuthChallenge` for the v4/opaque PK path.
 * A media row needs only `storage_key`; every other column has a default or is stamped by the base.
 */
#[CoversClass(Tiger_Model_Table::class)]
final class TableTest extends IntegrationTestCase
{
    private Tiger_Model_Media $media;

    protected function setUp(): void
    {
        parent::setUp();
        $this->media = new Tiger_Model_Media();
    }

    /** Insert a minimal media row (only storage_key is required by the schema); return its id. */
    private function insertMedia(string $key = 'k'): string
    {
        return $this->media->insert(['storage_key' => $key . '-' . substr(Tiger_Uuid::v7(), 0, 8)]);
    }

    /** The RFC canonical version nibble sits at string index 14 (…-Vxxx-…). */
    private static function versionChar(string $uuid): string
    {
        return $uuid[14];
    }

    #[Test]
    public function insert_mints_a_valid_v7_uuid_primary_key(): void
    {
        $id = $this->insertMedia();

        $this->assertTrue(Tiger_Uuid::isValid($id), 'insert() must return a canonical UUID');
        $this->assertSame(36, strlen($id), 'canonical UUID is 36 chars');
        $this->assertSame('7', self::versionChar($id), 'the default PK is a v7 (time-ordered) UUID');

        // The returned id is the actual stored PK (not a meaningless lastInsertId()).
        $row = $this->db->fetchRow('SELECT * FROM media WHERE media_id = ?', [$id]);
        $this->assertNotEmpty($row, 'the minted id is the row that was written');
    }

    #[Test]
    public function v7_primary_keys_are_time_ordered_across_inserts(): void
    {
        // v7 embeds a ms timestamp in its leading 48 bits, so two ids minted in sequence are ordered
        // to the millisecond. (Tiger_Uuid documents that it does NOT guarantee strict intra-ms
        // monotonicity, so the honest invariant is >= on the embedded time, not a raw string compare.)
        $first  = $this->insertMedia('a');
        $second = $this->insertMedia('b');

        $this->assertGreaterThanOrEqual(
            Tiger_Uuid::timeOf($first),
            Tiger_Uuid::timeOf($second),
            'a later v7 insert carries an equal-or-greater embedded timestamp'
        );
        // The embedded time is a real, recent creation time (sanity: within a minute of now).
        $this->assertEqualsWithDelta(time(), Tiger_Uuid::timeOf($first), 60, 'v7 time reflects creation');
    }

    #[Test]
    public function a_model_declaring_uuid_version_4_mints_an_opaque_v4(): void
    {
        // Tiger_Model_AuthChallenge sets $_uuidVersion = 4 so its ids (which appear in reset/magic URLs)
        // never leak creation time. A direct insert avoids the Tiger_Security hashing path.
        $ac = new Tiger_Model_AuthChallenge();
        $id = $ac->insert([
            'type'       => 'email_verify',
            'code_hash'  => 'not-a-real-hash',
            'expires_at' => date('Y-m-d H:i:s', time() + 600),
        ]);

        $this->assertTrue(Tiger_Uuid::isValid($id));
        $this->assertSame('4', self::versionChar($id), 'a $_uuidVersion=4 model mints a v4 (opaque) PK');
    }

    #[Test]
    public function actor_stamp_credits_created_by_and_updated_by(): void
    {
        $actor = Tiger_Uuid::v7();
        Tiger_Model_Table::setActor($actor);

        $id  = $this->insertMedia();
        $row = $this->db->fetchRow('SELECT * FROM media WHERE media_id = ?', [$id]);

        $this->assertSame($actor, $row['created_by'], 'created_by is stamped from the current actor');
        $this->assertSame($actor, $row['updated_by'], 'updated_by is stamped on insert too');
    }

    #[Test]
    public function a_null_actor_leaves_the_stamps_null(): void
    {
        // The base resets the actor to null each test (system/CLI/genesis context).
        $this->assertNull(Tiger_Model_Table::actor(), 'harness starts each test with no actor');

        $id  = $this->insertMedia();
        $row = $this->db->fetchRow('SELECT * FROM media WHERE media_id = ?', [$id]);

        $this->assertNull($row['created_by'], 'no actor => created_by is NULL (system/genesis)');
        $this->assertNull($row['updated_by'], 'no actor => updated_by is NULL');
    }

    #[Test]
    public function org_stamp_credits_org_id_when_an_org_is_set(): void
    {
        $org = Tiger_Uuid::v7();
        Tiger_Model_Table::setOrg($org);

        $id  = $this->insertMedia();
        $row = $this->db->fetchRow('SELECT * FROM media WHERE media_id = ?', [$id]);

        $this->assertSame($org, $row['org_id'], 'org_id is stamped from the current org (tenant ownership)');
    }

    #[Test]
    public function no_org_leaves_org_id_at_the_global_default(): void
    {
        // Harness resets setOrg('') — an explicit '' (not null) means "don't stamp", so the column
        // keeps its '' default: the platform/global scope.
        $id  = $this->insertMedia();
        $row = $this->db->fetchRow('SELECT * FROM media WHERE media_id = ?', [$id]);

        $this->assertSame('', $row['org_id'], "no active org => org_id stays '' (global scope)");
    }

    #[Test]
    public function an_explicit_org_id_always_wins_over_the_current_org(): void
    {
        Tiger_Model_Table::setOrg(Tiger_Uuid::v7());
        $explicit = Tiger_Uuid::v7();

        $id  = $this->media->insert(['storage_key' => 'x-' . substr($explicit, 0, 8), 'org_id' => $explicit]);
        $row = $this->db->fetchRow('SELECT * FROM media WHERE media_id = ?', [$id]);

        $this->assertSame($explicit, $row['org_id'], 'a passed org_id overrides the ambient one');
    }

    #[Test]
    public function insert_sets_created_at_and_updated_at(): void
    {
        $id  = $this->insertMedia();
        $row = $this->db->fetchRow('SELECT * FROM media WHERE media_id = ?', [$id]);

        $this->assertNotNull($row['created_at'], 'created_at is stamped on insert');
        $this->assertNotNull($row['updated_at'], 'updated_at is stamped on insert');
        $this->assertSame(0, (int) $row['deleted'], 'a fresh row is not soft-deleted');
    }

    #[Test]
    public function update_refreshes_updated_at_and_updated_by_without_touching_created_by(): void
    {
        $creator = Tiger_Uuid::v7();
        Tiger_Model_Table::setActor($creator);
        $id = $this->insertMedia();

        // A DIFFERENT actor performs the update.
        $editor = Tiger_Uuid::v7();
        Tiger_Model_Table::setActor($editor);
        $this->media->update(['title' => 'edited'], $this->db->quoteInto('media_id = ?', $id));

        $row = $this->db->fetchRow('SELECT * FROM media WHERE media_id = ?', [$id]);
        $this->assertSame('edited', $row['title']);
        $this->assertSame($creator, $row['created_by'], 'created_by is immutable — the original author');
        $this->assertSame($editor, $row['updated_by'], 'updated_by tracks the last editor');
        $this->assertNotNull($row['updated_at']);
        // DATETIME is second-precision, so assert monotonic-non-decreasing, never strict >.
        $this->assertGreaterThanOrEqual($row['created_at'], $row['updated_at'], 'updated_at does not go backward');
    }

    #[Test]
    public function soft_delete_hides_the_row_from_active_finders_yet_keeps_it_physically(): void
    {
        $id = $this->insertMedia();

        $this->media->softDelete($this->db->quoteInto('media_id = ?', $id));

        // The tenant-safety invariant: gone from every default read...
        $this->assertNull($this->media->findById($id), 'findById() excludes a soft-deleted row');
        $active = $this->media->fetchAll($this->media->activeSelect()->where('media_id = ?', $id));
        $this->assertCount(0, $active, 'activeSelect() excludes a soft-deleted row');

        // ...but the row is still there (soft delete, not a physical delete), flag flipped.
        $raw = $this->db->fetchRow('SELECT * FROM media WHERE media_id = ?', [$id]);
        $this->assertNotEmpty($raw, 'the row is retained physically');
        $this->assertSame(1, (int) $raw['deleted'], 'deleted flag is set to 1');
        $this->assertNotNull($this->media->findById($id, true), 'includeDeleted=true still finds it');
    }

    #[Test]
    public function restore_clears_the_soft_delete(): void
    {
        $id = $this->insertMedia();
        $this->media->softDelete($this->db->quoteInto('media_id = ?', $id));
        $this->assertNull($this->media->findById($id), 'precondition: hidden while deleted');

        $this->media->restore($this->db->quoteInto('media_id = ?', $id));

        $this->assertNotNull($this->media->findById($id), 'restore() makes the row visible again');
        $raw = $this->db->fetchRow('SELECT deleted FROM media WHERE media_id = ?', [$id]);
        $this->assertSame(0, (int) $raw['deleted'], 'restore() clears the deleted flag');
    }
}
