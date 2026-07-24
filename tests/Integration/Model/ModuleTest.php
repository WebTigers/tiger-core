<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_Module;

/**
 * Tiger_Model_Module — the module lifecycle registry and, more importantly, THE boot gate.
 * `inactiveSlugs()` is what Tiger_Application_Resource_Modules calls to strip deactivated modules
 * from the controller-directory map before dispatch, so its contract is load-bearing and
 * exact-by-omission: it returns ONLY rows with `active = 0`. A module with no row at all is
 * "active by absence" and must NOT appear — a false positive there would silently unmount a live
 * module. The lifecycle writers (`setActive` upsert, `install`, `uninstall`) are the surfaces that
 * put rows into (and take them out of) that gate.
 */
#[CoversClass(Tiger_Model_Module::class)]
final class ModuleTest extends IntegrationTestCase
{
    private Tiger_Model_Module $module;

    protected function setUp(): void
    {
        parent::setUp();
        $this->module = new Tiger_Model_Module();
    }

    private function slugs(): array
    {
        $s = $this->module->inactiveSlugs();
        sort($s);
        return $s;
    }

    #[Test]
    public function inactive_slugs_returns_only_deactivated_rows_never_active_or_rowless(): void
    {
        // active row → NOT in the gate list; inactive row → IN it; and a module with NO row is
        // "active by absence" and equally absent from the list.
        $this->module->insert(['slug' => 'blog',   'active' => 1, 'source' => Tiger_Model_Module::SOURCE_DISCOVERED]);
        $this->module->insert(['slug' => 'forum',  'active' => 0, 'source' => Tiger_Model_Module::SOURCE_DISCOVERED]);
        $this->module->insert(['slug' => 'wiki',   'active' => 0, 'source' => Tiger_Model_Module::SOURCE_DISCOVERED]);
        // (no row for 'shop' — active by absence)

        $this->assertSame(
            ['forum', 'wiki'],
            $this->slugs(),
            'the gate returns exactly the active=0 slugs — not the active one, not the row-less one'
        );
    }

    #[Test]
    public function set_active_creates_a_discovered_row_the_first_time_then_upserts(): void
    {
        // First toggle of a never-seen module MINTS a row (discovered provenance) …
        $id = $this->module->setActive('gallery', false);
        $row = $this->module->bySlug('gallery');
        $this->assertNotNull($row, 'setActive creates a row for a discovered module');
        $this->assertSame($id, $row->module_id, 'setActive returns the row id it created');
        $this->assertSame(0, (int) $row->active);
        $this->assertSame('inactive', $row->status, 'status tracks the active flag');
        $this->assertSame(Tiger_Model_Module::SOURCE_DISCOVERED, $row->source);
        $this->assertContains('gallery', $this->module->inactiveSlugs(), 'a freshly-deactivated module is in the gate');

        // … a second toggle UPSERTS the SAME row (no duplicate) and flips the flags back.
        $id2 = $this->module->setActive('gallery', true);
        $this->assertSame($id, $id2, 'setActive upserts — same module_id, no new row');
        $row2 = $this->module->bySlug('gallery');
        $this->assertSame(1, (int) $row2->active);
        $this->assertSame('active', $row2->status);
        $this->assertNotContains('gallery', $this->module->inactiveSlugs(), 're-activated → out of the gate');
        $this->assertCount(1, $this->module->fetchAll($this->module->select()->where('slug = ?', 'gallery')), 'exactly one row survives the upsert');
    }

    #[Test]
    public function install_forces_active_and_records_provenance(): void
    {
        $id = $this->module->install('shop', [
            'name'       => 'Tiger Shop',
            'version'    => '0.1.0-beta',
            'repository' => 'WebTigers/TigerShop',
            'ref'        => 'v0.1.0-beta',
            'source'     => Tiger_Model_Module::SOURCE_URL,
        ]);

        $row = $this->module->bySlug('shop');
        $this->assertNotNull($row);
        $this->assertSame($id, $row->module_id);
        $this->assertSame(1, (int) $row->active, 'install always forces active=1');
        $this->assertSame('active', $row->status);
        $this->assertSame('Tiger Shop', $row->name);
        $this->assertSame('0.1.0-beta', $row->version);
        $this->assertSame('WebTigers/TigerShop', $row->repository);
        $this->assertSame('v0.1.0-beta', $row->ref);
        $this->assertSame(Tiger_Model_Module::SOURCE_URL, $row->source);
        $this->assertNotContains('shop', $this->module->inactiveSlugs(), 'an installed module is active, not gated');
    }

    #[Test]
    public function install_reactivates_and_updates_a_previously_deactivated_module(): void
    {
        // A module the admin had turned off, then (re)installs, must come back ACTIVE — install
        // forces active=1 on the existing row rather than minting a duplicate.
        $first = $this->module->setActive('forum', false);
        $this->assertContains('forum', $this->module->inactiveSlugs());

        $second = $this->module->install('forum', ['name' => 'Forum', 'version' => '1.2.3']);
        $this->assertSame($first, $second, 'install upserts the same row');
        $row = $this->module->bySlug('forum');
        $this->assertSame(1, (int) $row->active, 'install re-activates a deactivated module');
        $this->assertSame('1.2.3', $row->version, 'provenance is refreshed on the existing row');
        $this->assertNotContains('forum', $this->module->inactiveSlugs());
    }

    #[Test]
    public function uninstall_hard_deletes_the_row(): void
    {
        // The module table is NOT soft-deleted: uninstall removes the row entirely, so the module
        // reverts to "active by absence" (its files being gone is the installer's concern).
        $this->module->install('temp', ['name' => 'Temp']);
        $this->assertNotNull($this->module->bySlug('temp'));

        $deleted = $this->module->uninstall('temp');
        $this->assertSame(1, $deleted, 'uninstall reports one row removed');
        $this->assertNull($this->module->bySlug('temp'), 'the row is hard-deleted, not soft-deleted');
    }

    #[Test]
    public function uninstalling_a_deactivated_module_removes_it_from_the_gate(): void
    {
        // A subtle safety check: if uninstall only soft-deleted, an active=0 row would linger and
        // keep the (now-absent) module permanently gated. Hard-delete makes it vanish from the gate.
        $this->module->setActive('doomed', false);
        $this->assertContains('doomed', $this->module->inactiveSlugs());

        $this->module->uninstall('doomed');
        $this->assertNotContains('doomed', $this->module->inactiveSlugs(), 'an uninstalled module is not left gated');
    }
}
