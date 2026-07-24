<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\Module;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Module_Dependency;

/**
 * Tiger_Module_Dependency — the lazy inter-module dependency alerts (the PUBLIC surface, which
 * DependencyParseTest's _readRequirements probe doesn't reach): requires(), requirements(), missing(),
 * missingReport(), and dependents(). These are the alerts an admin sees on activate/deactivate — never
 * a hard block, so their job is to correctly classify a requirement as absent / out-of-version / present,
 * and to name the active modules that still depend on something.
 *
 * Driven against real fixture module dirs planted under APPLICATION_PATH's modules/ (the bootstrap points
 * APPLICATION_PATH at an otherwise-empty fixture app, so our fixtures are the only APP modules present),
 * exactly like DiscoveryTest. No DB is booted, so Tiger_Module_Dependency::_inactiveSlugs() catches its
 * failed query and degrades to "nothing inactive" — the documented fail-soft path — which these tests also
 * pin (an absent requirement, not a false "inactive").
 */
#[CoversClass(Tiger_Module_Dependency::class)]
final class DependencyTest extends UnitTestCase
{
    /** @var string[] fixture module dirs created under APPLICATION_PATH/modules (removed in tearDown). */
    private array $created = [];

    protected function tearDown(): void
    {
        foreach ($this->created as $dir) { $this->rrmdir($dir); }
        // Prune fixture scaffolding if now empty (never touches a real app dir).
        @rmdir(APPLICATION_PATH . '/modules');
        @rmdir(APPLICATION_PATH);
        @rmdir(dirname(APPLICATION_PATH));
        parent::tearDown();
    }

    /** Plant a fixture module dir under the app modules root and register it for cleanup. */
    private function plantAppModule(string $slug, array $files): string
    {
        $dir = APPLICATION_PATH . '/modules/' . $slug;
        @mkdir($dir, 0775, true);
        foreach ($files as $rel => $contents) {
            $full = $dir . '/' . $rel;
            @mkdir(dirname($full), 0775, true);
            file_put_contents($full, $contents);
        }
        $this->created[] = $dir;
        return $dir;
    }

    // ---- requires() / requirements() -------------------------------------------

    #[Test]
    public function requiresReturnsTheDeclaredSlugsAndRequirementsCarryConstraints(): void
    {
        $this->plantAppModule('depa', [
            'module.json'             => json_encode(['slug' => 'depa', 'name' => 'Dep A', 'version' => '1.0.0']),
            'configs/dependency.ini'  => "[requires]\nmodules[] = \"depb >=1.0.0\"\nmodules[] = \"depc\"\n",
        ]);

        $this->assertSame(['depb', 'depc'], Tiger_Module_Dependency::requires('depa'));

        $reqs = Tiger_Module_Dependency::requirements('depa');
        $this->assertSame([
            ['slug' => 'depb', 'constraint' => '>=1.0.0'],
            ['slug' => 'depc', 'constraint' => ''],
        ], $reqs);
    }

    #[Test]
    public function aModuleWithNoDependencyIniHasNoRequirements(): void
    {
        $this->plantAppModule('lonely', [
            'module.json' => json_encode(['slug' => 'lonely', 'name' => 'Lonely']),
        ]);
        $this->assertSame([], Tiger_Module_Dependency::requires('lonely'));
        $this->assertSame([], Tiger_Module_Dependency::requirements('lonely'));
    }

    #[Test]
    public function anUnknownSlugHasNoRequirements(): void
    {
        // No dir at all → _dir() is null → empty, never a fatal.
        $this->assertSame([], Tiger_Module_Dependency::requirements('nosuchmodule'));
        $this->assertSame([], Tiger_Module_Dependency::requires('nosuchmodule'));
    }

    // ---- missing() / missingReport() -------------------------------------------

    #[Test]
    public function missingReportClassifiesAbsentAndOutOfVersionRequirements(): void
    {
        // depa requires depb >=1.0.0 (present but too OLD) and depc (ABSENT).
        $this->plantAppModule('depa', [
            'module.json'            => json_encode(['slug' => 'depa', 'name' => 'Dep A', 'version' => '1.0.0']),
            'configs/dependency.ini' => "[requires]\nmodules[] = \"depb >=1.0.0\"\nmodules[] = \"depc\"\n",
        ]);
        $this->plantAppModule('depb', [
            'module.json' => json_encode(['slug' => 'depb', 'name' => 'Dep B', 'version' => '0.5.0']),
        ]);

        $report = Tiger_Module_Dependency::missingReport('depa');
        // Order follows the declared requirements: depb (version), then depc (absent).
        $this->assertCount(2, $report);

        $this->assertSame('depb', $report[0]['slug']);
        $this->assertSame('version', $report[0]['reason'], 'present but below the constraint → a version alert');
        $this->assertSame('>=1.0.0', $report[0]['need']);
        $this->assertSame('0.5.0', $report[0]['have']);

        $this->assertSame('depc', $report[1]['slug']);
        $this->assertSame('absent', $report[1]['reason']);
        $this->assertNull($report[1]['have']);

        // missing() is the flat slug list of the same set.
        $this->assertSame(['depb', 'depc'], Tiger_Module_Dependency::missing('depa'));
    }

    #[Test]
    public function aSatisfiedRequirementDoesNotAppearInTheReport(): void
    {
        // depb is present AND new enough → not flagged (only depc absent remains).
        $this->plantAppModule('depa', [
            'module.json'            => json_encode(['slug' => 'depa', 'name' => 'Dep A']),
            'configs/dependency.ini' => "[requires]\nmodules[] = \"depb >=1.0.0\"\nmodules[] = \"depc\"\n",
        ]);
        $this->plantAppModule('depb', [
            'module.json' => json_encode(['slug' => 'depb', 'name' => 'Dep B', 'version' => '2.0.0']),
        ]);

        $this->assertSame(['depc'], Tiger_Module_Dependency::missing('depa'));
    }

    #[Test]
    public function anUnknownInstalledVersionNeverTriggersAVersionAlarm(): void
    {
        // depb is present but declares NO version → advisory: an unknown version is not a version alarm.
        $this->plantAppModule('depa', [
            'module.json'            => json_encode(['slug' => 'depa', 'name' => 'Dep A']),
            'configs/dependency.ini' => "[requires]\nmodules[] = \"depb >=9.9.9\"\n",
        ]);
        $this->plantAppModule('depb', [
            'module.json' => json_encode(['slug' => 'depb', 'name' => 'Dep B']),   // no version
        ]);

        $this->assertSame([], Tiger_Module_Dependency::missing('depa'), 'no version known → no alarm');
    }

    // ---- dependents() ----------------------------------------------------------

    #[Test]
    public function dependentsNamesActiveModulesThatRequireTheSlug(): void
    {
        // depa and depd both require depb; depe requires something else. dependents(depb) = [depa, depd].
        $this->plantAppModule('depa', [
            'module.json'            => json_encode(['slug' => 'depa', 'name' => 'Dep A']),
            'configs/dependency.ini' => "[requires]\nmodules[] = \"depb\"\n",
        ]);
        $this->plantAppModule('depd', [
            'module.json'            => json_encode(['slug' => 'depd', 'name' => 'Dep D']),
            'configs/dependency.ini' => "[requires]\nmodules[] = \"depb >=1.0\"\n",
        ]);
        $this->plantAppModule('depe', [
            'module.json'            => json_encode(['slug' => 'depe', 'name' => 'Dep E']),
            'configs/dependency.ini' => "[requires]\nmodules[] = \"something-else\"\n",
        ]);
        $this->plantAppModule('depb', [
            'module.json' => json_encode(['slug' => 'depb', 'name' => 'Dep B', 'version' => '1.0.0']),
        ]);

        $dependents = Tiger_Module_Dependency::dependents('depb');
        sort($dependents);
        $this->assertSame(['depa', 'depd'], $dependents);
    }

    #[Test]
    public function dependentsOfSomethingNobodyNeedsIsEmpty(): void
    {
        $this->plantAppModule('solo', [
            'module.json' => json_encode(['slug' => 'solo', 'name' => 'Solo']),
        ]);
        $this->assertSame([], Tiger_Module_Dependency::dependents('solo'));
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) { return; }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') { continue; }
            $p = $dir . '/' . $item;
            (is_dir($p) && !is_link($p)) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
