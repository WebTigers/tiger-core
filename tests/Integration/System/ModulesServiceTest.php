<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\System;

use PHPUnit\Framework\Attributes\Test;
use System_Service_Modules;
use Tiger\Tests\Support\IntegrationTestCase;
use Zend_Registry;

// System_Service_Modules is a first-party module class resolved by the harness module autoloader.

/**
 * System_Service_Modules — the Module Manager's /api service (activate / deactivate / inspect /
 * install / upload / search). Turning platform features on and off — and installing code from a
 * URL or an upload — is `superadmin`+ ONLY (modules/system/configs/acl.ini), so a guest and a
 * plain admin are both denied. Beyond the gate, the tests characterize the guard rails that make
 * the surface safe WITHOUT any network: the PROTECTED set can never be deactivated (you can't lock
 * yourself out of the manager), an unknown slug is refused before any migration runs, and the
 * install/upload/inspect paths reject bad input before touching a repo.
 */
final class ModulesServiceTest extends IntegrationTestCase
{
    private function dispatch(array $msg): object
    {
        return (new System_Service_Modules($msg))->getResponse();
    }

    private function messages(object $res): string
    {
        return json_encode($res->messages ?? []);
    }

    // ---- ACL: deny-by-default, superadmin-gated --------------------------------------------------

    #[Test]
    public function the_shipped_acl_gates_module_management_to_superadmin_and_up(): void
    {
        $this->loginAs('superadmin');
        $acl = Zend_Registry::get('Zend_Acl');

        $this->assertTrue($acl->has('System_Service_Modules'), 'the system module acl.ini resource loaded');
        $this->assertTrue($acl->isAllowed('superadmin', 'System_Service_Modules'), 'superadmin manages modules');
        $this->assertTrue($acl->isAllowed('developer', 'System_Service_Modules'), 'the god developer role inherits it');
        $this->assertFalse($acl->isAllowed('admin', 'System_Service_Modules'), 'a plain admin cannot install/toggle modules');
        $this->assertFalse($acl->isAllowed('guest', 'System_Service_Modules'), 'a guest is denied');
    }

    #[Test]
    public function a_guest_is_denied_toggling_a_module(): void
    {
        $this->login('anon', 'o-1', 'guest');
        $res = $this->dispatch(['action' => 'deactivate', 'slug' => 'blog']);

        $this->assertSame(0, (int) $res->result);
        $this->assertStringContainsString('not_allowed', $this->messages($res), 'the ACL denial fired');
    }

    #[Test]
    public function a_plain_admin_is_denied_installing_a_module(): void
    {
        $this->loginAs('admin');
        $res = $this->dispatch(['action' => 'install', 'url' => 'https://github.com/WebTigers/TigerShop']);

        $this->assertSame(0, (int) $res->result, 'installing code is superadmin+, not admin');
        $this->assertStringContainsString('not_allowed', $this->messages($res), 'the ACL denial fired for admin');
    }

    // ---- the guard rails (superadmin, no network) ------------------------------------------------

    #[Test]
    public function the_protected_set_can_never_be_deactivated(): void
    {
        $this->loginAs('superadmin');
        foreach (System_Service_Modules::PROTECTED as $slug) {
            $res = $this->dispatch(['action' => 'deactivate', 'slug' => $slug]);
            $this->assertSame(0, (int) $res->result, "protected module '$slug' is not deactivatable");
            $this->assertStringContainsString('protected', $this->messages($res), "the protected-guard fired for '$slug'");
            $this->assertStringNotContainsString('not_allowed', $this->messages($res), 'superadmin cleared the ACL gate first');
        }
    }

    #[Test]
    public function an_unknown_slug_is_refused_before_any_migration(): void
    {
        $this->loginAs('superadmin');
        // A slug that is neither protected nor discovered on disk — refused at the discovery check,
        // which is BEFORE Tiger_Module_Installer::migrateModule() would ever run.
        $res = $this->dispatch(['action' => 'activate', 'slug' => 'no-such-module-xyz']);

        $this->assertSame(0, (int) $res->result);
        $this->assertStringContainsString('unknown', $this->messages($res), 'the unknown-module guard fired');
    }

    #[Test]
    public function an_empty_slug_is_a_clean_error(): void
    {
        $this->loginAs('superadmin');
        $res = $this->dispatch(['action' => 'deactivate', 'slug' => '']);

        $this->assertSame(0, (int) $res->result, 'an empty slug fails cleanly');
        $this->assertStringNotContainsString('not_allowed', $this->messages($res), 'it failed on validation, not the ACL');
    }

    #[Test]
    public function install_rejects_a_non_github_url_before_any_network(): void
    {
        $this->loginAs('superadmin');
        $res = $this->dispatch(['action' => 'install', 'url' => 'not a url at all']);

        $this->assertSame(0, (int) $res->result, 'a malformed repo URL is refused');
        $this->assertStringContainsString('GitHub', $this->messages($res), 'the URL-shape guard, not a network failure');
    }

    #[Test]
    public function inspect_rejects_a_non_github_url_before_any_network(): void
    {
        $this->loginAs('superadmin');
        $res = $this->dispatch(['action' => 'inspect', 'url' => 'ftp://example.com/whatever']);

        $this->assertSame(0, (int) $res->result, 'inspect refuses a non-GitHub URL up front');
        $this->assertStringContainsString('GitHub', $this->messages($res));
    }

    #[Test]
    public function upload_with_no_file_is_a_clean_error(): void
    {
        $this->loginAs('superadmin');
        // No $_FILES['archive'] present → the "choose a .zip" guard, never a fatal.
        unset($_FILES['archive']);
        $res = $this->dispatch(['action' => 'upload']);

        $this->assertSame(0, (int) $res->result, 'a missing upload fails cleanly');
        $this->assertStringNotContainsString('not_allowed', $this->messages($res), 'it failed on the missing file, not the ACL');
    }
}
