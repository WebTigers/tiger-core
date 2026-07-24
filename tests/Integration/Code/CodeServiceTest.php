<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Code;

use Code_Service_Code;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Code_Runtime;
use Tiger_Model_Code;
use Zend_Registry;

// Code_Service_Code is a first-party module class resolved by the harness's module autoloader.

/**
 * Code_Service_Code — the /api service for authoring server-executed PHP: the single most
 * privileged surface in the platform. The crown-jewel assertion here is the ACL gate: authoring
 * PHP is `superadmin`+ ONLY (the `code.execute` gate, modules/code/configs/acl.ini), so a guest
 * AND a plain `admin` are both DENIED — only superadmin/developer clear it. The rest characterizes
 * the safety rails: save LINTS PHP before storing (a parse error is refused), toggles guard bad
 * ids, and module snippets are read-only (source read live from the file, never a DB row).
 *
 * Forms carry CSRF; tests flip the shipped `tiger.auth.stateless` seam so a service-level form
 * validates without a rendered token — exactly the stateless-auth path the base form honors.
 */
final class CodeServiceTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Zend_Registry::set('tiger.auth.stateless', true);   // disable CSRF on Tiger_Form for header-less dispatch
    }

    protected function tearDown(): void
    {
        Zend_Registry::set('tiger.auth.stateless', false);
        // A happy-path save/toggle rebuilds the real `global` bundle — clean any artifacts it wrote.
        $dir = APPLICATION_ROOT . '/storage/cache/code';
        foreach (glob($dir . '/global.*.php') ?: [] as $f) { @unlink($f); }
        foreach (glob($dir . '/inject.global.*.php') ?: [] as $f) { @unlink($f); }
        foreach (glob(APPLICATION_ROOT . '/public/_code/global.*') ?: [] as $f) { @unlink($f); }
        parent::tearDown();
    }

    private function messages(object $res): string
    {
        return json_encode($res->messages ?? []);
    }

    // ---- the crown jewel: ACL deny-by-default, superadmin-gated -----------------------------------

    #[Test]
    public function the_shipped_acl_gates_the_code_service_to_superadmin_and_up(): void
    {
        $this->loginAs('superadmin');
        $acl = Zend_Registry::get('Zend_Acl');

        $this->assertTrue($acl->has('Code_Service_Code'), 'the code module acl.ini resource loaded');
        $this->assertTrue($acl->isAllowed('superadmin', 'Code_Service_Code'), 'superadmin holds code.execute');
        $this->assertTrue($acl->isAllowed('developer', 'Code_Service_Code'), 'the god developer role inherits it');
        $this->assertFalse($acl->isAllowed('admin', 'Code_Service_Code'), 'a PLAIN admin is denied — this is NOT an admin surface');
        $this->assertFalse($acl->isAllowed('manager', 'Code_Service_Code'), 'a manager is denied');
        $this->assertFalse($acl->isAllowed('guest', 'Code_Service_Code'), 'a guest is denied');
    }

    #[Test]
    public function a_guest_is_denied_dispatching_the_code_service(): void
    {
        $this->login('anon', 'o-1', 'guest');
        $res = (new Code_Service_Code(['action' => 'datatable']))->getResponse();

        $this->assertSame(0, (int) $res->result, 'guest denied');
        $this->assertStringContainsString('not_allowed', $this->messages($res), 'the ACL denial fired');
    }

    #[Test]
    public function a_plain_admin_is_denied_dispatching_the_code_service(): void
    {
        // The key distinction from every other admin service: authoring PHP is superadmin+, so an
        // admin who can manage users/content still cannot touch the Code Area.
        $this->loginAs('admin');
        $res = (new Code_Service_Code(['action' => 'datatable']))->getResponse();

        $this->assertSame(0, (int) $res->result, 'a plain admin is denied the code service');
        $this->assertStringContainsString('not_allowed', $this->messages($res), 'the ACL denial fired for admin');
    }

    #[Test]
    public function a_superadmin_clears_the_gate(): void
    {
        $this->loginAs('superadmin');
        $res = (new Code_Service_Code(['action' => 'datatable', 'draw' => 1, 'start' => 0, 'length' => 10]))->getResponse();

        $this->assertStringNotContainsString('not_allowed', $this->messages($res), 'superadmin cleared the ACL gate');
        $this->assertSame(1, (int) $res->result, 'the datatable action ran');
    }

    #[Test]
    public function the_developer_god_role_clears_the_gate(): void
    {
        $this->loginAs('developer');
        $res = (new Code_Service_Code(['action' => 'datatable', 'draw' => 1, 'start' => 0, 'length' => 10]))->getResponse();

        $this->assertStringNotContainsString('not_allowed', $this->messages($res), 'developer cleared the ACL gate');
        $this->assertSame(1, (int) $res->result);
    }

    // ---- the safety rails ------------------------------------------------------------------------

    #[Test]
    public function save_refuses_syntactically_invalid_php_before_storing(): void
    {
        $this->loginAs('superadmin');
        $res = (new Code_Service_Code([
            'action'   => 'save',
            'name'     => 'Broken helper',
            'language' => 'php',
            'code'     => 'function broken( {',   // form-valid (name present) but a parse error
            'active'   => '1',
            'priority' => '100',
        ]))->getResponse();

        $this->assertSame(0, (int) $res->result, 'a parse error is refused');
        $this->assertStringContainsString('Not saved', $this->messages($res), 'the lint rejection message, not a form error');

        // Nothing was stored.
        $this->assertNull((new Tiger_Model_Code())->fetchRow(['name = ?' => 'Broken helper']), 'no row was written');
    }

    // NOTE: the save()/restore() happy paths cannot be exercised through this harness — the base's
    // per-test isolation transaction collides with Tiger_Model_Code::save()'s OWN beginTransaction
    // (nested begins throw "already an active transaction"). That's a harness×product interaction, not
    // a product bug (a real /api request has no outer transaction). See WAVE3-FINDINGS-rce.md. The
    // lint-reject path above IS the security-relevant assertion for save; the compile/rebuild happy
    // path is covered directly in RuntimeTest.

    #[Test]
    public function toggling_an_unknown_local_id_is_a_clean_error_not_a_crash(): void
    {
        $this->loginAs('superadmin');
        $res = (new Code_Service_Code(['action' => 'activate', 'code_id' => 'no-such-id']))->getResponse();

        $this->assertSame(0, (int) $res->result, 'a missing snippet fails cleanly');
        $this->assertStringNotContainsString('not_allowed', $this->messages($res), 'it failed on the lookup, not the ACL');
    }

    #[Test]
    public function module_source_for_an_unknown_key_is_a_clean_error(): void
    {
        // Module snippets are read-only and read LIVE from a file; an unresolvable key errors, never
        // touches the DB. (No `code` module snippet is guaranteed present in the harness, so we assert
        // the negative path — the one that doesn't depend on a fixture snippet existing.)
        $this->loginAs('superadmin');
        $res = (new Code_Service_Code(['action' => 'moduleSource', 'code_id' => 'module:does/not-exist']))->getResponse();

        $this->assertSame(0, (int) $res->result, 'an unknown module snippet key errors cleanly');
    }

    #[Test]
    public function delete_soft_deletes_a_local_snippet_and_rebuilds(): void
    {
        $this->loginAs('superadmin');
        $id = (new Tiger_Model_Code())->insert([
            'org_id'       => '',
            'name'         => 'Doomed',
            'language'     => Tiger_Model_Code::LANG_PHP,
            'code'         => "if (!function_exists('tiger_svc_doomed')) { function tiger_svc_doomed() {} }",
            'run_location' => Tiger_Model_Code::LOC_GLOBAL,
            'active'       => 0,
            'status'       => Tiger_Model_Code::STATUS_DRAFT,
        ]);

        $res = (new Code_Service_Code(['action' => 'delete', 'code_id' => $id]))->getResponse();

        $this->assertSame(1, (int) $res->result, 'delete succeeds');
        $this->assertNull((new Tiger_Model_Code())->findById($id), 'the row is hidden by the soft-delete finder');
    }
}
