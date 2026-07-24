<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Agent;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Agent_Scout;

// The Scout reports an 'rw' permission for paths under the app-modules root. In isolation MODULES_PATH
// isn't defined; pin it to the SAME value Ally's integration test uses so the perm map is deterministic.
if (!defined('MODULES_PATH')) {
    define('MODULES_PATH', TIGER_CORE_PATH . '/modules');
}

/**
 * Tiger_Agent_Scout — the agent's read "eyes" (the read twin of the Forge), against the REAL ACL.
 * Reads auto-run (no approval) but are still tier-gated: `inventory` (the system map) at admin+, and
 * `tree`/`file`/`grep`/`guide` at superadmin+. The read surface is deliberately WIDER than the write
 * sandbox — the Scout can read into vendor/webtigers/tiger-core to learn house style — while secrets
 * (local.ini, *.key, storage/) are excluded by construction. Filesystem reads run against this repo.
 */
#[CoversClass(Tiger_Agent_Scout::class)]
final class ScoutTest extends IntegrationTestCase
{
    // ----- allowed (developer inherits superadmin + admin) ------------------

    #[Test]
    public function inventoryMapsTheSystemForAnAdmin(): void
    {
        $this->loginAs('admin');
        $entry = (new Tiger_Agent_Scout('admin'))->execute(['type' => 'read.inventory', 'reason' => 'r']);
        $this->assertSame('done', $entry['status']);
        $this->assertStringContainsString('MODULES:', $entry['feedback']);
        $this->assertStringContainsString('ROOTS', $entry['feedback']);
    }

    #[Test]
    public function treeWithNoPathMapsTheReadRootsWithPermissions(): void
    {
        $this->loginAs('developer');
        $entry = (new Tiger_Agent_Scout('developer'))->execute(['type' => 'read.tree', 'reason' => 'r']);
        $this->assertSame('done', $entry['status']);
        $this->assertStringContainsString('ROOT MAP', $entry['feedback']);
    }

    #[Test]
    public function treeListsEntriesUnderAScopedPath(): void
    {
        $this->loginAs('developer');
        $entry = (new Tiger_Agent_Scout('developer'))->execute(['type' => 'read.tree', 'path' => 'library/Tiger/Agent', 'reason' => 'r']);
        $this->assertSame('done', $entry['status']);
        $this->assertStringContainsString('Contract.php', $entry['feedback']);
        $this->assertStringContainsString('TREE', $entry['feedback']);
    }

    #[Test]
    public function fileReturnsABoundedFileBody(): void
    {
        $this->loginAs('superadmin');
        $entry = (new Tiger_Agent_Scout('superadmin'))->execute(['type' => 'read.file', 'path' => 'library/Tiger/Agent/Contract.php', 'reason' => 'r']);
        $this->assertSame('done', $entry['status']);
        $this->assertStringContainsString('class Tiger_Agent_Contract', $entry['feedback']);
    }

    #[Test]
    public function fileOutsideTheReadableSurfaceIsRefused(): void
    {
        $this->loginAs('developer');
        $entry = (new Tiger_Agent_Scout('developer'))->execute(['type' => 'read.file', 'path' => 'does/not/exist.php', 'reason' => 'r']);
        $this->assertSame('denied', $entry['status']);
    }

    #[Test]
    public function grepFindsAStringUnderAScopedPathAndReportsMisses(): void
    {
        $this->loginAs('developer');
        $scout = new Tiger_Agent_Scout('developer');

        $hit = $scout->execute(['type' => 'read.grep', 'query' => 'READ_INVENTORY', 'path' => 'library/Tiger/Agent', 'reason' => 'r']);
        $this->assertSame('done', $hit['status']);
        $this->assertStringContainsString('match', $hit['feedback']);

        $miss = $scout->execute(['type' => 'read.grep', 'query' => 'zzz_no_such_token_zzz', 'path' => 'library/Tiger/Agent', 'reason' => 'r']);
        $this->assertStringContainsString('no matches', $miss['feedback']);
    }

    #[Test]
    public function grepWithoutAQueryIsAnError(): void
    {
        $this->loginAs('developer');
        $entry = (new Tiger_Agent_Scout('developer'))->execute(['type' => 'read.grep', 'query' => '', 'reason' => 'r']);
        $this->assertSame('error', $entry['status']);
    }

    #[Test]
    public function guideReturnsThePlatformConventionsWithNoModule(): void
    {
        $this->loginAs('developer');
        $entry = (new Tiger_Agent_Scout('developer'))->execute(['type' => 'read.guide', 'module' => '', 'reason' => 'r']);
        $this->assertSame('done', $entry['status']);
        $this->assertStringContainsString('PLATFORM CONVENTIONS', $entry['feedback']);
    }

    #[Test]
    public function guideForAModuleThatShipsAnAgentsMdReturnsIt(): void
    {
        $this->loginAs('developer');
        // The cms module ships an AGENTS.md → the Scout returns that module guide directly.
        $entry = (new Tiger_Agent_Scout('developer'))->execute(['type' => 'read.guide', 'module' => 'cms', 'reason' => 'r']);
        $this->assertSame('done', $entry['status']);
        $this->assertStringContainsString('GUIDE for module "cms"', $entry['feedback']);
    }

    #[Test]
    public function treeOnAFilePathReportsItIsAFile(): void
    {
        $this->loginAs('developer');
        $entry = (new Tiger_Agent_Scout('developer'))->execute(['type' => 'read.tree', 'path' => 'library/Tiger/Agent/Contract.php', 'reason' => 'r']);
        $this->assertSame('done', $entry['status']);
        $this->assertStringContainsString('is a file', $entry['summary']);
    }

    #[Test]
    public function treeMarksTheModulesRootWritableByTheForge(): void
    {
        $this->loginAs('developer');
        // A path under the app-modules root (MODULES_PATH) reports the 'rw' permission the agent needs.
        $entry = (new Tiger_Agent_Scout('developer'))->execute(['type' => 'read.tree', 'path' => 'modules/agent', 'reason' => 'r']);
        $this->assertSame('done', $entry['status']);
        $this->assertStringContainsString('WRITABLE by the Forge', $entry['feedback']);
    }

    #[Test]
    public function guideForAModuleWithoutAnAgentsMdFallsBackToTheConventions(): void
    {
        $this->loginAs('developer');
        // The agent module ships no AGENTS.md, so the Scout returns the platform conventions with a note.
        $entry = (new Tiger_Agent_Scout('developer'))->execute(['type' => 'read.guide', 'module' => 'agent', 'reason' => 'r']);
        $this->assertSame('done', $entry['status']);
        $this->assertStringContainsString('platform conventions', $entry['feedback']);
    }

    #[Test]
    public function anUnknownReadActionIsAnError(): void
    {
        $this->loginAs('developer');
        $entry = (new Tiger_Agent_Scout('developer'))->execute(['type' => 'read.bogus', 'reason' => 'r']);
        $this->assertSame('error', $entry['status']);
    }

    // ----- denied (the tier gate) -------------------------------------------

    #[Test]
    public function aManagerIsDeniedEveryReadTierIncludingInventory(): void
    {
        $this->loginAs('manager');   // may chat, but has neither inventory nor read
        $scout = new Tiger_Agent_Scout('manager');
        foreach ([
            ['type' => 'read.inventory'],
            ['type' => 'read.tree'],
            ['type' => 'read.file', 'path' => 'library/Tiger/Agent/Contract.php'],
            ['type' => 'read.grep', 'query' => 'x'],
            ['type' => 'read.guide'],
        ] as $action) {
            $this->assertSame('denied', $scout->execute($action + ['reason' => 'r'])['status'], $action['type'] . ' should be denied');
        }
    }

    #[Test]
    public function anAdminGetsInventoryButIsDeniedTheDeeperReadTier(): void
    {
        $this->loginAs('admin');   // inventory yes, tree/file/grep no
        $scout = new Tiger_Agent_Scout('admin');
        $this->assertSame('done',   $scout->execute(['type' => 'read.inventory', 'reason' => 'r'])['status']);
        $this->assertSame('denied', $scout->execute(['type' => 'read.tree', 'reason' => 'r'])['status']);
        $this->assertSame('denied', $scout->execute(['type' => 'read.file', 'path' => 'x', 'reason' => 'r'])['status']);
    }
}
