<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_CodeVersion;
use Tiger_Model_PageVersion;
use Tiger_Uuid;

/**
 * Tiger_Model_PageVersion + Tiger_Model_CodeVersion — the append-only snapshot-versioning primitive both
 * the CMS and the Code Area build on. The contract:
 *   - nextVersion() = MAX(version)+1 for the OWNING row; the first version is 1 (empty history → 0+1).
 *   - snapshot() writes that number and returns it, monotonically per owner.
 *   - version streams are INDEPENDENT per owner (page A's counter can't be advanced by page B).
 *   - get()/recentFor*() read a specific version / newest-first history.
 *
 * These two models are the same design copied for two owners, so they're proven together.
 */
#[CoversClass(Tiger_Model_PageVersion::class)]
#[CoversClass(Tiger_Model_CodeVersion::class)]
final class VersioningTest extends IntegrationTestCase
{
    private Tiger_Model_PageVersion $pv;
    private Tiger_Model_CodeVersion $cv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pv = new Tiger_Model_PageVersion();
        $this->cv = new Tiger_Model_CodeVersion();
    }

    #[Test]
    public function page_first_version_is_one_and_increments_per_snapshot(): void
    {
        $pageId = Tiger_Uuid::v7();

        $this->assertSame(1, $this->pv->nextVersion($pageId), 'an unversioned page starts at 1');

        $this->assertSame(1, $this->pv->snapshot($pageId, ['title' => 'v1', 'body' => 'one', 'status' => 'draft']));
        $this->assertSame(2, $this->pv->snapshot($pageId, ['title' => 'v2', 'body' => 'two', 'status' => 'published']));
        $this->assertSame(3, $this->pv->snapshot($pageId, ['title' => 'v3', 'body' => 'three', 'status' => 'published']));

        $this->assertSame(4, $this->pv->nextVersion($pageId), 'nextVersion tracks MAX(version)+1');
    }

    #[Test]
    public function page_version_streams_are_independent_per_owner(): void
    {
        $pageA = Tiger_Uuid::v7();
        $pageB = Tiger_Uuid::v7();

        $this->pv->snapshot($pageA, ['title' => 'A1']);
        $this->pv->snapshot($pageA, ['title' => 'A2']);
        // Page B's history is untouched by A's two snapshots.
        $this->assertSame(1, $this->pv->nextVersion($pageB), 'B is unaffected by A');
        $this->assertSame(1, $this->pv->snapshot($pageB, ['title' => 'B1']), 'B starts its own count at 1');
        $this->assertSame(3, $this->pv->nextVersion($pageA), 'A continues its own count independently');
    }

    #[Test]
    public function page_version_get_and_history_read_back_the_snapshot(): void
    {
        $pageId = Tiger_Uuid::v7();
        $this->pv->snapshot($pageId, ['title' => 'First',  'body' => 'b1', 'format' => 'markdown', 'status' => 'draft']);
        $this->pv->snapshot($pageId, ['title' => 'Second', 'body' => 'b2', 'format' => 'html',     'status' => 'published']);

        $v1 = $this->pv->get($pageId, 1);
        $this->assertNotNull($v1);
        $this->assertSame('First', $v1->title);
        $this->assertSame('markdown', $v1->format, 'the snapshotted format is stored intact');
        $this->assertNull($this->pv->get($pageId, 99), 'a missing version is null');

        // recentForPage is newest-first.
        $recent = $this->pv->recentForPage($pageId);
        $this->assertSame(2, (int) $recent->current()->version, 'history is newest-first');
    }

    #[Test]
    public function code_first_version_is_one_and_increments_independently(): void
    {
        $codeA = Tiger_Uuid::v7();
        $codeB = Tiger_Uuid::v7();

        $this->assertSame(1, $this->cv->nextVersion($codeA), 'an unversioned code row starts at 1');

        $this->assertSame(1, $this->cv->snapshot($codeA, ['name' => 'slug', 'language' => 'php', 'code' => '<?php // v1', 'status' => 'active']));
        $this->assertSame(2, $this->cv->snapshot($codeA, ['name' => 'slug', 'language' => 'php', 'code' => '<?php // v2', 'status' => 'active']));

        // A different code row keeps its own independent stream.
        $this->assertSame(1, $this->cv->snapshot($codeB, ['name' => 'other', 'language' => 'js', 'code' => '// b1']));
        $this->assertSame(3, $this->cv->nextVersion($codeA), 'code A keeps counting from its own MAX');
        $this->assertSame(2, $this->cv->nextVersion($codeB));
    }

    #[Test]
    public function code_version_get_reads_the_snapshotted_fields(): void
    {
        $codeId = Tiger_Uuid::v7();
        $this->cv->snapshot($codeId, ['name' => 'helper', 'language' => 'php', 'code' => '<?php function h(){}', 'run_location' => 'global', 'priority' => 50, 'active' => 1, 'status' => 'active']);

        $v1 = $this->cv->get($codeId, 1);
        $this->assertNotNull($v1);
        $this->assertSame('helper', $v1->name);
        $this->assertSame('php', $v1->language);
        $this->assertSame('<?php function h(){}', $v1->code, 'the exact code body is snapshotted');
        $this->assertSame(1, (int) $v1->active);
        $this->assertNull($this->cv->get($codeId, 2), 'a missing version is null');
    }
}
