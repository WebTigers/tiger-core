<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Profile;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Profile_Service_User;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_Option;
use Tiger_Model_User;
use Zend_Config;
use Zend_Registry;

/**
 * Profile_Service_User — the self-service Basic-info tab (/api).
 *
 * Wave-4 coverage of the strictly-self-scoped identity save: it only ever writes the CURRENT
 * identity ($this->_user_id) — a guest (no identity) is refused, a signed-in user writes `username`
 * (unique, checked in the service), the two i18n primitives `locale`/`timezone` (membership-validated
 * against config + IANA), and a friendly `display_name` in the per-user option tier (blank clears it).
 * The savepoint-aware harness lets the service's own `_transaction()` nest, so the save is dispatched
 * inline and the base rollback isolates it.
 */
#[CoversClass(Profile_Service_User::class)]
final class UserServiceTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Zend_Registry::set('tiger.auth.stateless', true);   // CSRF-immune API path (no session in CLI)
        // The service resolves supported languages from config (tiger.i18n.locales); the base harness
        // seeds no Zend_Config, so provide a minimal one (mirrors the Access UserServiceTest).
        Zend_Registry::set('Zend_Config', new Zend_Config(['tiger' => ['i18n' => ['locales' => 'en,es']]], true));
    }

    protected function tearDown(): void
    {
        $reg = Zend_Registry::getInstance();
        if ($reg->offsetExists('tiger.auth.stateless')) { $reg->offsetUnset('tiger.auth.stateless'); }
        parent::tearDown();
    }

    /** Dispatch the service with an action + payload and hand back the response object. */
    private function call(string $action, array $params = []): object
    {
        return (new Profile_Service_User(['action' => $action] + $params))->getResponse();
    }

    // ----- self-scope gate ----------------------------------------------------------------------

    #[Test]
    public function a_guest_with_no_identity_is_refused(): void
    {
        // No login → no identity → _user_id is empty → not_allowed.
        $res = $this->call('save', ['username' => 'nope']);
        $this->assertSame(0, (int) $res->result, 'a guest cannot save a profile');
        $this->assertStringContainsString('not_allowed', json_encode($res->messages));
    }

    // ----- save (validate -> transaction) -------------------------------------------------------

    #[Test]
    public function save_writes_username_locale_and_timezone_to_the_identity_row(): void
    {
        $id = (new Tiger_Model_User())->insert(['email' => 'basic@w4test.com', 'status' => 'active']);
        $this->login($id, 'org-test', 'user');

        $res = $this->call('save', [
            'username' => 'thundarr',
            'locale'   => 'es',
            'timezone' => 'America/New_York',
        ]);

        $this->assertSame(1, (int) $res->result, 'a valid self-save succeeds');
        $this->assertSame('es', $res->data['locale'], 'the resolved locale is returned');

        $row = (new Tiger_Model_User())->findById($id);
        $this->assertSame('thundarr', $row->username);
        $this->assertSame('es', $row->locale);
        $this->assertSame('America/New_York', $row->timezone);
    }

    #[Test]
    public function save_nulls_an_unsupported_locale_and_a_bogus_timezone(): void
    {
        $id = (new Tiger_Model_User())->insert(['email' => 'bogus@w4test.com', 'status' => 'active']);
        $this->login($id, 'org-test', 'user');

        $res = $this->call('save', [
            'username' => 'ariel',
            'locale'   => 'zz',                 // not in tiger.i18n.locales
            'timezone' => 'Mars/Phobos',        // not an IANA identifier
        ]);

        $this->assertSame(1, (int) $res->result);
        $this->assertNull($res->data['locale'], 'an unsupported locale resolves to null');

        $row = (new Tiger_Model_User())->findById($id);
        $this->assertNull($row->locale, 'the bogus locale is stored as NULL');
        $this->assertNull($row->timezone, 'the bogus timezone is stored as NULL');
    }

    #[Test]
    public function save_stores_and_clears_the_display_name_in_the_option_tier(): void
    {
        $id = (new Tiger_Model_User())->insert(['email' => 'dn@w4test.com', 'status' => 'active']);
        $this->login($id, 'org-test', 'user');
        $opt = new Tiger_Model_Option();

        $this->call('save', ['username' => 'ookla', 'display_name' => 'Ookla the Mok']);
        $this->assertSame(
            'Ookla the Mok',
            $opt->get(Tiger_Model_Option::SCOPE_USER, $id, Profile_Service_User::OPTION_DISPLAY_NAME),
            'the display name is written to the per-user option tier'
        );

        // A blank display_name on a later save clears the option (UI falls back to email).
        $res = $this->call('save', ['username' => 'ookla', 'display_name' => '']);
        $this->assertSame(1, (int) $res->result);
        $this->assertSame('', $res->data['display_name']);
        $this->assertNull(
            $opt->get(Tiger_Model_Option::SCOPE_USER, $id, Profile_Service_User::OPTION_DISPLAY_NAME),
            'a blank display name forgets the option'
        );
    }

    #[Test]
    public function save_clears_username_when_blank(): void
    {
        $id = (new Tiger_Model_User())->insert(['email' => 'clr@w4test.com', 'username' => 'oldname', 'status' => 'active']);
        $this->login($id, 'org-test', 'user');

        $res = $this->call('save', ['username' => '']);
        $this->assertSame(1, (int) $res->result);
        $row = (new Tiger_Model_User())->findById($id);
        $this->assertNull($row->username, 'a blank username clears the column');
    }

    #[Test]
    public function save_rejects_a_username_already_taken_by_another_user(): void
    {
        (new Tiger_Model_User())->insert(['email' => 'owner@w4test.com', 'username' => 'takenname', 'status' => 'active']);
        $mine = (new Tiger_Model_User())->insert(['email' => 'me@w4test.com', 'status' => 'active']);
        $this->login($mine, 'org-test', 'user');

        $res = $this->call('save', ['username' => 'takenname']);
        $this->assertSame(0, (int) $res->result, 'a duplicate username is refused');
        $this->assertStringContainsString('username_taken', json_encode($res->messages));

        $row = (new Tiger_Model_User())->findById($mine);
        $this->assertNull($row->username, 'nothing was written on the taken-username path');
    }

    #[Test]
    public function save_returns_form_errors_for_an_over_long_username(): void
    {
        $id = (new Tiger_Model_User())->insert(['email' => 'long@w4test.com', 'status' => 'active']);
        $this->login($id, 'org-test', 'user');

        $res = $this->call('save', ['username' => str_repeat('a', 65)]);   // > 64 char StringLength cap
        $this->assertSame(0, (int) $res->result);
        $this->assertNotNull($res->form, 'field errors returned');
        $this->assertArrayHasKey('username', $res->form);
    }
}
