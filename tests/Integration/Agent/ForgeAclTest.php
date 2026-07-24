<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Agent;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Agent_Forge;
use Tiger_Log;
use Zend_Config;
use Zend_Registry;

// The module-scaffold "already exists" guard checks the app-modules root. In isolation MODULES_PATH
// isn't defined; pin it to the SAME value Ally's integration test uses so the check is deterministic.
if (!defined('MODULES_PATH')) {
    define('MODULES_PATH', TIGER_CORE_PATH . '/modules');
}

/** A Forge whose write sandbox is redirected to a temp file, so the approved-write happy path can
 *  run without touching the real application/modules tree. Everything else is the real Forge. */
final class TempForge extends Tiger_Agent_Forge
{
    public string $target = '';
    protected function _resolveModulePath($path): ?string { return $this->target !== '' ? $this->target : null; }
}

/**
 * Tiger_Agent_Forge — the permission-gated "hands" of the agent, exercised against the REAL shipped
 * ACL (core + every module's acl.ini, via the integration login). It verifies the twin gate: the ACL
 * (deny-by-default, the same rule a human hits) and the human-in-the-loop rule (writes wait for an
 * explicit approval). The tiers fall straight out of the agent acl.ini — code/file at superadmin,
 * module at developer — so a role that lacks the privilege is refused, and an allowed-but-unapproved
 * write is deferred, never run. The live /api dispatch of an approved read/write is the documented
 * boundary (it would run a real downstream service), so it isn't driven here.
 */
#[CoversClass(Tiger_Agent_Forge::class)]
final class ForgeAclTest extends IntegrationTestCase
{
    #[Test]
    public function anUnknownActionTypeIsACleanError(): void
    {
        $this->loginAs('developer');
        $entry = (new Tiger_Agent_Forge('developer'))->execute(['type' => 'nope', 'reason' => 'x']);
        $this->assertSame('error', $entry['status']);
        $this->assertStringContainsString('Unknown action type', $entry['summary']);
    }

    // ----- api tier ---------------------------------------------------------

    #[Test]
    public function aGuestIsDeniedAnApiWrite(): void
    {
        $this->loginAs('guest');
        $entry = (new Tiger_Agent_Forge('guest'))->execute([
            'type' => 'api', 'module' => 'access', 'service' => 'user', 'method' => 'create', 'params' => [], 'reason' => 'r',
        ]);
        $this->assertSame('denied', $entry['status']);
        $this->assertArrayHasKey('action', $entry);   // the proposal is preserved for the ledger
    }

    #[Test]
    public function anAllowedButUnapprovedApiWriteIsDeferredNotDispatched(): void
    {
        $this->loginAs('admin');   // admin may call Cms_Service_Page (the real cms acl.ini)
        $entry = (new Tiger_Agent_Forge('admin'))->execute([
            'type' => 'api', 'module' => 'cms', 'service' => 'page', 'method' => 'save',
            'params' => ['title' => 'FAQ'], 'reason' => 'create the page',
        ]);
        $this->assertSame('proposed', $entry['status']);
        $this->assertStringContainsString('cms/page/save', $entry['summary']);
    }

    #[Test]
    public function anApprovedApiWriteDispatchesTheRealServiceAndReportsItsResult(): void
    {
        $this->loginAs('admin');
        // Approved → the Forge dispatches Cms_Service_Page for real. Empty params fail the page form,
        // so nothing persists, but the dispatch + response-message flattening + ledger entry all run.
        $entry = (new Tiger_Agent_Forge('admin'))->execute([
            'type' => 'api', 'module' => 'cms', 'service' => 'page', 'method' => 'save',
            'params' => [], 'reason' => 'create a page', 'approved' => true,
        ]);
        $this->assertContains($entry['status'], ['done', 'error']);   // it dispatched, whatever the verdict
        $this->assertStringContainsString('cms/page/save', $entry['summary']);
        $this->assertArrayHasKey('result', $entry['detail']);
    }

    // ----- code tier (superadmin+) ------------------------------------------

    #[Test]
    public function writingExecutableCodeIsDeniedBelowSuperadmin(): void
    {
        $this->loginAs('manager');
        $entry = (new Tiger_Agent_Forge('manager'))->execute([
            'type' => 'code', 'name' => 's', 'language' => 'php', 'code' => '<?php 1;', 'reason' => 'r',
        ]);
        $this->assertSame('denied', $entry['status']);
    }

    #[Test]
    public function anAllowedButUnapprovedCodeWriteIsDeferredNotRun(): void
    {
        $this->loginAs('superadmin');
        $entry = (new Tiger_Agent_Forge('superadmin'))->execute([
            'type' => 'code', 'name' => 'helper', 'language' => 'php', 'code' => '<?php 1;', 'reason' => 'r',
        ]);
        $this->assertSame('proposed', $entry['status']);
        $this->assertStringContainsString('helper', $entry['summary']);
    }

    // ----- file tier (superadmin+) ------------------------------------------

    #[Test]
    public function writingAModuleFileIsDeniedBelowSuperadmin(): void
    {
        $this->loginAs('admin');   // admin has inventory, but NOT the sharp file tier
        $entry = (new Tiger_Agent_Forge('admin'))->execute([
            'type' => 'file', 'path' => 'demo/x.phtml', 'contents' => '<h1>x</h1>', 'reason' => 'r',
        ]);
        $this->assertSame('denied', $entry['status']);
    }

    #[Test]
    public function anAllowedButUnapprovedFileWriteIsDeferred(): void
    {
        $this->loginAs('superadmin');
        $entry = (new Tiger_Agent_Forge('superadmin'))->execute([
            'type' => 'file', 'path' => 'demo/x.phtml', 'contents' => '<h1>x</h1>', 'reason' => 'r',
        ]);
        $this->assertSame('proposed', $entry['status']);
        $this->assertStringContainsString('demo/x.phtml', $entry['summary']);
    }

    #[Test]
    public function anApprovedPhpFileThatWouldNotParseIsRefusedBeforeItHitsDisk(): void
    {
        $this->loginAs('superadmin');
        $tmp = sys_get_temp_dir() . '/tiger-forge-' . uniqid() . '/broken.php';

        $forge = new TempForge('superadmin');
        $forge->target = $tmp;
        $entry = $forge->execute([
            'type' => 'file', 'path' => 'demo/broken.php', 'contents' => '<?php function (( {{ not php',
            'reason' => 'r', 'approved' => true,
        ]);

        $this->assertSame('error', $entry['status']);
        $this->assertStringContainsString('would not parse', $entry['summary']);
        $this->assertFileDoesNotExist($tmp);   // the lint gate refuses it before any write
    }

    #[Test]
    public function anApprovedFileWriteLandsOnDiskInsideTheSandbox(): void
    {
        $this->loginAs('superadmin');
        $tmp = sys_get_temp_dir() . '/tiger-forge-' . uniqid() . '/nested/view.phtml';

        $forge = new TempForge('superadmin');
        $forge->target = $tmp;

        // A successful write emits a Tiger_Log line via the ErrorLog writer (→ stderr), which the
        // strict "no unexpected output" rule flags risky. Point the logger at the Null writer for the
        // duration of the write, then restore the registry EXACTLY as it was.
        $had  = Zend_Registry::isRegistered('Zend_Config');
        $prev = $had ? Zend_Registry::get('Zend_Config') : null;
        Zend_Registry::set('Zend_Config', new Zend_Config(['tiger' => ['log' => ['writer' => 'null']]]));
        Tiger_Log::reset();
        try {
            $entry = $forge->execute([
                'type' => 'file', 'path' => 'demo/nested/view.phtml', 'contents' => '<h1>hello</h1>',
                'reason' => 'r', 'approved' => true,
            ]);
        } finally {
            if ($had) { Zend_Registry::set('Zend_Config', $prev); }
            else { Zend_Registry::getInstance()->offsetUnset('Zend_Config'); }
            Tiger_Log::reset();
        }

        $this->assertSame('done', $entry['status']);
        $this->assertFileExists($tmp);
        $this->assertSame('<h1>hello</h1>', file_get_contents($tmp));

        @unlink($tmp);
        @rmdir(dirname($tmp));
        @rmdir(dirname($tmp, 2));
    }

    // ----- module tier (developer only) -------------------------------------

    #[Test]
    public function scaffoldingAModuleIsDeniedBelowDeveloper(): void
    {
        $this->loginAs('superadmin');   // superadmin has file, but module is developer-only
        $entry = (new Tiger_Agent_Forge('superadmin'))->execute([
            'type' => 'module', 'name' => 'widgets', 'reason' => 'r',
        ]);
        $this->assertSame('denied', $entry['status']);
    }

    #[Test]
    public function anAllowedButUnapprovedModuleScaffoldIsDeferred(): void
    {
        $this->loginAs('developer');
        $entry = (new Tiger_Agent_Forge('developer'))->execute([
            'type' => 'module', 'name' => 'widgets', 'reason' => 'r',
        ]);
        $this->assertSame('proposed', $entry['status']);
        $this->assertStringContainsString('widgets', $entry['summary']);
    }

    #[Test]
    public function anApprovedModuleScaffoldWithAnEmptyNameIsAnError(): void
    {
        $this->loginAs('developer');
        $entry = (new Tiger_Agent_Forge('developer'))->execute([
            'type' => 'module', 'name' => '', 'reason' => 'r', 'approved' => true,
        ]);
        $this->assertSame('error', $entry['status']);
        $this->assertStringContainsString('valid module name', $entry['summary']);
    }

    #[Test]
    public function anApprovedModuleScaffoldRefusesAnExistingModuleName(): void
    {
        $this->loginAs('developer');
        // 'agent' already exists under the modules root → the Forge refuses to clobber it.
        $entry = (new Tiger_Agent_Forge('developer'))->execute([
            'type' => 'module', 'name' => 'agent', 'reason' => 'r', 'approved' => true,
        ]);
        $this->assertSame('error', $entry['status']);
        $this->assertStringContainsString('already exists', $entry['summary']);
    }
}
