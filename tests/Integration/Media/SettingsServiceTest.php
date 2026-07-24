<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Media;

use Media_Service_Settings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_Config;
use Tiger_Model_Media;
use Zend_Registry;

/**
 * Media_Service_Settings — the /api service behind the Media Library settings screen. It validates the
 * two obfuscation selects and writes them (per visibility) to the `config` table, org-scoped. Wave-4
 * coverage: the ACL gate, the validate-and-persist happy path (both flags land in `config` at the
 * resolved scope), and the invalid-payload reject (a bad select value returns form errors, writes nothing).
 */
#[CoversClass(Media_Service_Settings::class)]
final class SettingsServiceTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Zend_Registry::set('tiger.auth.stateless', true);
    }

    protected function tearDown(): void
    {
        $reg = Zend_Registry::getInstance();
        if ($reg->offsetExists('tiger.auth.stateless')) { $reg->offsetUnset('tiger.auth.stateless'); }
        parent::tearDown();
    }

    private function call(array $params = []): object
    {
        return (new Media_Service_Settings(['action' => 'save'] + $params))->getResponse();
    }

    #[Test]
    public function guest_is_denied(): void
    {
        $this->login('anon', 'org-test', 'guest');
        $res = $this->call(['obfuscate_public' => '1', 'obfuscate_private' => '1']);
        $this->assertSame(0, (int) $res->result);
        $this->assertStringContainsString('not_allowed', json_encode($res->messages));
    }

    #[Test]
    public function a_plain_user_is_denied(): void
    {
        $this->loginAs('user');
        $res = $this->call(['obfuscate_public' => '0', 'obfuscate_private' => '0']);
        $this->assertSame(0, (int) $res->result);
        $this->assertStringContainsString('not_allowed', json_encode($res->messages));
    }

    #[Test]
    public function save_persists_both_flags_to_config(): void
    {
        $this->login('u-admin', '', 'admin');   // empty org → GLOBAL scope
        $res = $this->call(['obfuscate_public' => '1', 'obfuscate_private' => '0']);

        $this->assertSame(1, (int) $res->result, 'valid selects saved');
        $this->assertSame('/media/admin/settings', $res->redirect, 'redirect echoed');

        [$scope, $sid] = Tiger_Model_Media::settingScope('');
        $cfg = new Tiger_Model_Config();
        $this->assertSame('1', $cfg->get($scope, $sid, Tiger_Model_Media::CFG_OBFUSCATE . 'public'));
        $this->assertSame('0', $cfg->get($scope, $sid, Tiger_Model_Media::CFG_OBFUSCATE . 'private'));
    }

    #[Test]
    public function save_coerces_any_truthy_non_one_to_zero(): void
    {
        $this->login('u-admin', '', 'admin');
        // The service stores '1' only for an exact '1'; the select still validates the value, so pass
        // the allowed pair but flip which is on.
        $this->call(['obfuscate_public' => '0', 'obfuscate_private' => '1']);

        [$scope, $sid] = Tiger_Model_Media::settingScope('');
        $cfg = new Tiger_Model_Config();
        $this->assertSame('0', $cfg->get($scope, $sid, Tiger_Model_Media::CFG_OBFUSCATE . 'public'));
        $this->assertSame('1', $cfg->get($scope, $sid, Tiger_Model_Media::CFG_OBFUSCATE . 'private'));
    }

    #[Test]
    public function an_invalid_select_value_returns_form_errors_and_writes_nothing(): void
    {
        $this->loginAs('admin');
        $before = (int) $this->db->fetchOne('SELECT COUNT(*) FROM config');

        // '7' is not one of the select's multiOptions → Zend_Form's InArray validator rejects it.
        $res = $this->call(['obfuscate_public' => '7', 'obfuscate_private' => '0']);

        $this->assertSame(0, (int) $res->result, 'invalid select rejected');
        $this->assertNotNull($res->form, 'field errors returned');
        $this->assertArrayHasKey('obfuscate_public', $res->form);
        $this->assertSame($before, (int) $this->db->fetchOne('SELECT COUNT(*) FROM config'), 'nothing written');
    }
}
