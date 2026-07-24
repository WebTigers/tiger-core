<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Cms;

use Cms_Service_Settings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_Config;
use Zend_Registry;

/**
 * Cms_Service_Settings — the /api Settings service (save only).
 *
 * The domain specific worth proving: Settings does NOT write a settings table — it writes the site
 * name + home page into the DB `config` table (scope=global) via Tiger_Model_Config, the live-override
 * tier of the config cascade (config-discipline: config store, no option landfill). Coverage: the ACL
 * gate (admin+), the validate→write (site_name required → form error, no config row), an upsert that
 * updates the same key in place rather than duplicating it, and that the values land as global config
 * rows.
 *
 * Config::set writes directly (no service-level transaction), so these tests live inside the harness's
 * per-test transaction.
 */
#[CoversClass(Cms_Service_Settings::class)]
final class SettingsServiceTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Zend_Registry::set('tiger.auth.stateless', true);   // CSRF-immune API path (no session in CLI)
    }

    protected function tearDown(): void
    {
        $reg = Zend_Registry::getInstance();
        if ($reg->offsetExists('tiger.auth.stateless')) { $reg->offsetUnset('tiger.auth.stateless'); }
        parent::tearDown();
    }

    private function call(string $action, array $params = []): object
    {
        return (new Cms_Service_Settings(['action' => $action] + $params))->getResponse();
    }

    private function globalConfig(string $key): ?string
    {
        return (new Tiger_Model_Config())->get(Tiger_Model_Config::SCOPE_GLOBAL, '', $key);
    }

    // ----- ACL gate -----------------------------------------------------------------------------

    #[Test]
    public function guest_and_plain_user_are_denied(): void
    {
        $this->login('anon', 'org-test', 'guest');
        $res = $this->call('save', ['site_name' => 'X']);
        $this->assertSame(0, (int) $res->result);
        $this->assertStringContainsString('not_allowed', json_encode($res->messages), 'guest denied');

        $this->loginAs('user');
        $res = $this->call('save', ['site_name' => 'X']);
        $this->assertSame(0, (int) $res->result);
        $this->assertStringContainsString('not_allowed', json_encode($res->messages), 'plain user denied');

        // Nothing leaked into config from a denied call.
        $this->assertNull($this->globalConfig('tiger.site.name'));
    }

    // ----- save writes to the config tier -------------------------------------------------------

    #[Test]
    public function save_writes_site_name_and_home_page_to_global_config(): void
    {
        $this->loginAs('admin');
        $res = $this->call('save', ['site_name' => 'My Tiger Site', 'home_page' => '']);

        $this->assertSame(1, (int) $res->result);
        $this->assertSame('My Tiger Site', $this->globalConfig('tiger.site.name'), 'site name lands in the config tier');
        $this->assertSame('', $this->globalConfig('tiger.site.home_page'), 'home page (empty = built-in landing) is written too');
    }

    #[Test]
    public function save_is_an_upsert_it_updates_the_same_key_in_place(): void
    {
        $this->loginAs('admin');
        $this->call('save', ['site_name' => 'First Name', 'home_page' => '']);
        $this->call('save', ['site_name' => 'Second Name', 'home_page' => '']);

        $this->assertSame('Second Name', $this->globalConfig('tiger.site.name'), 'last write wins');
        $count = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM config WHERE scope = 'global' AND scope_id = '' AND config_key = 'tiger.site.name' AND deleted = 0"
        );
        $this->assertSame(1, $count, 'upsert — a single config row, not a duplicate per save');
    }

    #[Test]
    public function save_trims_the_site_name(): void
    {
        $this->loginAs('admin');
        $this->call('save', ['site_name' => '   Spaced Site   ', 'home_page' => '']);
        $this->assertSame('Spaced Site', $this->globalConfig('tiger.site.name'));
    }

    // ----- validation ---------------------------------------------------------------------------

    #[Test]
    public function a_blank_site_name_returns_a_form_error_and_writes_no_config(): void
    {
        $this->loginAs('admin');
        $before = (int) $this->db->fetchOne('SELECT COUNT(*) FROM config');

        $res = $this->call('save', ['site_name' => '', 'home_page' => '']);

        $this->assertSame(0, (int) $res->result, 'site_name is required');
        $this->assertNotNull($res->form);
        $this->assertArrayHasKey('site_name', $res->form);
        $this->assertSame($before, (int) $this->db->fetchOne('SELECT COUNT(*) FROM config'), 'no config written');
    }
}
