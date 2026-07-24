<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\Agent;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Agent_Forge;

// The Forge sandboxes file writes to the app-modules root. In isolation MODULES_PATH isn't defined;
// point it at the SAME value Ally's integration test uses (TIGER_CORE_PATH/modules) so the constant
// stays consistent across the suite and this test never writes — it only resolves paths.
if (!defined('MODULES_PATH')) {
    define('MODULES_PATH', TIGER_CORE_PATH . '/modules');
}

/** Exposes the Forge's protected path-sandbox resolver + minimal scaffolder for direct assertion. */
final class ExposedForge extends Tiger_Agent_Forge
{
    public function resolve(string $p): ?string { return $this->_resolveModulePath($p); }
    public function minimalScaffold(string $base, string $name): void { $this->_minimalScaffold($base, $name); }
}

/**
 * Tiger_Agent_Forge — the mode/tier ranking that raises the approval threshold without raising the
 * ACL ceiling, and the write sandbox resolver that keeps a proposed file path inside
 * application/modules (so core, the framework, and the agent itself are unreachable by construction).
 * Both are pure; the ACL-gated execute() paths are covered by the integration test.
 */
#[CoversClass(Tiger_Agent_Forge::class)]
final class ForgeUnitTest extends UnitTestCase
{
    // ----- autoRank: the tier dial ------------------------------------------

    #[Test]
    public function autoRankMarksApiReadsAlwaysAutoAndApiWritesAutoTier(): void
    {
        $this->assertSame(-1, Tiger_Agent_Forge::autoRank(['type' => 'api', 'method' => 'list']));
        $this->assertSame(-1, Tiger_Agent_Forge::autoRank(['type' => 'api', 'method' => 'DataTable']));   // case-insensitive
        $this->assertSame(0, Tiger_Agent_Forge::autoRank(['type' => 'api', 'method' => 'save']));
        $this->assertSame(0, Tiger_Agent_Forge::autoRank(['type' => 'api', 'method' => 'delete']));
    }

    #[Test]
    public function autoRankPutsTheSharpTiersAtYoloOnly(): void
    {
        $this->assertSame(1, Tiger_Agent_Forge::autoRank(['type' => 'code']));
        $this->assertSame(1, Tiger_Agent_Forge::autoRank(['type' => 'file']));
        $this->assertSame(1, Tiger_Agent_Forge::autoRank(['type' => 'module']));
        $this->assertSame(-1, Tiger_Agent_Forge::autoRank(['type' => 'read.file']));   // unknown/other → always
    }

    // ----- _resolveModulePath: the write sandbox ----------------------------

    #[Test]
    public function resolveKeepsAValidPathInsideTheModulesRoot(): void
    {
        $forge = new ExposedForge('superadmin');
        $abs   = $forge->resolve('agent/views/scripts/index/x.phtml');
        $this->assertNotNull($abs);
        $this->assertStringStartsWith(realpath(MODULES_PATH), $abs);
    }

    #[Test]
    public function resolveToleratesALeadingModulesOrApplicationModulesPrefix(): void
    {
        $forge = new ExposedForge('superadmin');
        $this->assertNotNull($forge->resolve('modules/agent/x.phtml'));
        $this->assertNotNull($forge->resolve('application/modules/agent/x.phtml'));
    }

    #[Test]
    public function resolveRefusesTraversalEmptyAndNullByte(): void
    {
        $forge = new ExposedForge('superadmin');
        $this->assertNull($forge->resolve('../../etc/passwd'));
        $this->assertNull($forge->resolve('agent/../../escape.php'));
        $this->assertNull($forge->resolve(''));
        $this->assertNull($forge->resolve("agent/x\0.php"));
    }

    // ----- _minimalScaffold: the no-generator fallback skeleton --------------

    #[Test]
    public function minimalScaffoldLaysDownAnActivatableModuleSkeleton(): void
    {
        $base = sys_get_temp_dir() . '/tiger-scaffold-' . uniqid();
        (new ExposedForge('developer'))->minimalScaffold($base, 'bookshop');

        $this->assertFileExists($base . '/Bootstrap.php');
        $this->assertFileExists($base . '/controllers/IndexController.php');
        $this->assertFileExists($base . '/views/scripts/index/index.phtml');
        $this->assertFileExists($base . '/configs/acl.ini');
        $this->assertStringContainsString('Bookshop_IndexController', file_get_contents($base . '/controllers/IndexController.php'));
        $this->assertStringContainsString('Bookshop_Bootstrap', file_get_contents($base . '/Bootstrap.php'));

        // clean up the temp tree
        foreach (['configs/acl.ini', 'views/scripts/index/index.phtml', 'controllers/IndexController.php', 'Bootstrap.php'] as $f) { @unlink($base . '/' . $f); }
        foreach (['views/scripts/index', 'views/scripts', 'views', 'controllers', 'configs', ''] as $d) { @rmdir($base . '/' . $d); }
    }
}
