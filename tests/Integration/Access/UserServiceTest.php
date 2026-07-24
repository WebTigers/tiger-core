<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Access;

use Access_Service_User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_User;
use Tiger_Model_UserCredential;
use Zend_Config;
use Zend_Registry;

/**
 * Access_Service_User — the /api CRUD service behind the Users admin (datatable / save / delete).
 *
 * Wave-3 coverage of the admin-CRUD `/api` surface: the ACL gate (deny-by-default — guest and a
 * plain user are refused, only admin+ clears it), the DataTables envelope with its server-computed
 * per-row permission flags, the validate→transaction save (created_by stamped from the acting
 * admin; an invalid payload returns form errors and writes NO row), and the soft-delete (the row is
 * flagged, reads exclude it) plus the "never delete yourself" guard.
 *
 * Forms carry a CSRF token by default; a `/api` dispatch in CLI has no session, so we flag the
 * request STATELESS (the framework's own token-request path — Tiger_Form skips CSRF when
 * `tiger.auth.stateless` is set), exactly as a Bearer-token API call does. Cleared in tearDown.
 */
#[CoversClass(Access_Service_User::class)]
final class UserServiceTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Zend_Registry::set('tiger.auth.stateless', true);   // CSRF-immune API path (no session in CLI)
        // Access_Form_User builds its locale picker from tiger.i18n.locales; the base harness seeds no
        // Zend_Config, so provide a minimal one (mirrors how the credential/model tests seed config).
        Zend_Registry::set('Zend_Config', new Zend_Config(['tiger' => ['i18n' => ['locales' => 'en,es']]], true));
    }

    protected function tearDown(): void
    {
        // Access_Service_User::save() opens its OWN transaction (_transaction), which can't nest inside
        // the harness's per-test transaction (Zend_Db/PDO has no nesting). Those tests commit the harness
        // txn first (escapeTxn) and rely on this scrub — the isolated test DB's user tables are owned
        // entirely by this suite (migrations seed no rows). For a still-in-transaction test the DELETEs
        // run inside that txn and are undone by the base rollback, so this is harmless either way.
        try {
            $this->db->query('DELETE FROM user_credential');
            $this->db->query('DELETE FROM user');
        } catch (\Throwable $e) {
            // ignore
        }
        $reg = Zend_Registry::getInstance();
        if ($reg->offsetExists('tiger.auth.stateless')) { $reg->offsetUnset('tiger.auth.stateless'); }
        parent::tearDown();
    }

    /** Dispatch the service with an action + payload and hand back the response object. */
    private function call(string $action, array $params = []): object
    {
        return (new Access_Service_User(['action' => $action] + $params))->getResponse();
    }

    /** Commit + leave the harness txn so the service can open its own (nested txns aren't supported). */
    private function escapeTxn(): void
    {
        $this->db->commit();
    }

    // ----- ACL gate (deny-by-default) -----------------------------------------------------------

    #[Test]
    public function guest_is_denied_every_action(): void
    {
        $this->login('anon', 'org-test', 'guest');
        foreach (['datatable', 'save', 'delete'] as $action) {
            $res = $this->call($action);
            $this->assertSame(0, (int) $res->result, "guest denied on {$action}");
            $this->assertStringContainsString('not_allowed', json_encode($res->messages), "ACL denial on {$action}");
        }
    }

    #[Test]
    public function a_plain_user_is_denied(): void
    {
        $this->loginAs('user');
        $res = $this->call('datatable', ['draw' => 1]);
        $this->assertSame(0, (int) $res->result, 'a plain authenticated user is not an admin');
        $this->assertStringContainsString('not_allowed', json_encode($res->messages));
    }

    #[Test]
    public function an_admin_clears_the_gate(): void
    {
        $this->loginAs('admin');
        $res = $this->call('datatable', ['draw' => 1, 'start' => 0, 'length' => 10]);
        $this->assertSame(1, (int) $res->result, 'admin is allowed');
        $this->assertStringNotContainsString('not_allowed', json_encode($res->messages));
    }

    // ----- datatable ----------------------------------------------------------------------------

    #[Test]
    public function datatable_returns_the_envelope_with_permission_flags(): void
    {
        $this->loginAs('admin');
        (new Tiger_Model_User())->insert(['email' => 'grid1@w3ctest.com', 'status' => 'active']);

        $res  = $this->call('datatable', ['draw' => 7, 'start' => 0, 'length' => 25]);
        $data = $res->data;

        $this->assertSame(7, $data['draw'], 'draw echoes back');
        $this->assertArrayHasKey('recordsTotal', $data);
        $this->assertArrayHasKey('recordsFiltered', $data);
        $this->assertGreaterThanOrEqual(1, $data['recordsTotal']);
        $this->assertNotEmpty($data['data']);

        $row = $data['data'][0];
        $this->assertArrayHasKey('can_edit', $row, 'server-computed edit flag present');
        $this->assertArrayHasKey('can_delete', $row);
        $this->assertTrue($row['can_edit'], 'admin may edit');
    }

    #[Test]
    public function datatable_search_narrows_records_filtered(): void
    {
        $this->loginAs('admin');
        $user = new Tiger_Model_User();
        $user->insert(['email' => 'needle-unique@w3ctest.com', 'status' => 'active']);
        $user->insert(['email' => 'haystack-a@w3ctest.com', 'status' => 'active']);
        $user->insert(['email' => 'haystack-b@w3ctest.com', 'status' => 'active']);

        $res = $this->call('datatable', ['draw' => 1, 'start' => 0, 'length' => 25, 'search' => 'needle-unique']);
        $data = $res->data;

        $this->assertSame(1, $data['recordsFiltered'], 'search narrows the filtered count to the one match');
        $this->assertGreaterThanOrEqual(3, $data['recordsTotal'], 'total is the unfiltered working set');
        $this->assertSame('needle-unique@w3ctest.com', $data['data'][0]['email']);
    }

    #[Test]
    public function datatable_paging_limits_rows_without_shrinking_total(): void
    {
        $this->loginAs('admin');
        $user = new Tiger_Model_User();
        for ($i = 0; $i < 3; $i++) { $user->insert(['email' => "page{$i}@w3ctest.com", 'status' => 'active']); }

        $res  = $this->call('datatable', ['draw' => 1, 'start' => 0, 'length' => 1]);
        $data = $res->data;

        $this->assertCount(1, $data['data'], 'length=1 returns a single row');
        $this->assertGreaterThanOrEqual(3, $data['recordsTotal'], 'paging does not reduce recordsTotal');
    }

    #[Test]
    public function datatable_marks_the_acting_user_as_self_and_blocks_self_delete(): void
    {
        $user = new Tiger_Model_User();
        $meId = $user->insert(['email' => 'me-self@w3ctest.com', 'status' => 'active']);
        $this->login($meId, 'org-test', 'admin');   // act AS the seeded user

        $res  = $this->call('datatable', ['draw' => 1, 'start' => 0, 'length' => 100, 'search' => 'me-self']);
        $mine = null;
        foreach ($res->data['data'] as $r) { if ($r['user_id'] === $meId) { $mine = $r; break; } }

        $this->assertNotNull($mine, 'the acting user appears in the grid');
        $this->assertTrue($mine['is_self'], 'flagged as self');
        $this->assertFalse($mine['can_delete'], 'you can never delete your own account');
    }

    // ----- save (validate -> transaction) -------------------------------------------------------

    #[Test]
    public function save_persists_a_new_user_and_stamps_created_by(): void
    {
        $this->login('admin-actor', 'org-test', 'admin');
        $this->escapeTxn();
        $res = $this->call('save', ['email' => 'created@w3ctest.com', 'username' => 'creado', 'status' => 'active']);

        $this->assertSame(1, (int) $res->result, 'valid payload saved');
        $id = $res->data['user_id'];
        $this->assertNotEmpty($id);

        $row = (new Tiger_Model_User())->findById($id);
        $this->assertNotNull($row);
        $this->assertSame('created@w3ctest.com', $row->email);
        $this->assertSame('creado', $row->username);
        $this->assertSame('active', $row->status);
        $this->assertSame(0, (int) $row->deleted, 'a fresh row is not deleted');
        $this->assertSame('admin-actor', $row->created_by, 'created_by stamped from the acting admin');
    }

    #[Test]
    public function save_lowercases_and_trims_the_email(): void
    {
        $this->loginAs('admin');
        $this->escapeTxn();
        $res = $this->call('save', ['email' => '  MixedCase@W3Ctest.com  ', 'status' => 'active']);
        $this->assertSame(1, (int) $res->result);
        $row = (new Tiger_Model_User())->findById($res->data['user_id']);
        $this->assertSame('mixedcase@w3ctest.com', $row->email, 'email is normalized to lowercase+trimmed');
    }

    #[Test]
    public function an_invalid_payload_returns_form_errors_and_writes_no_row(): void
    {
        $this->loginAs('admin');
        $before = (int) $this->db->fetchOne('SELECT COUNT(*) FROM user');

        $res = $this->call('save', ['email' => 'not-an-email', 'status' => 'active']);

        $this->assertSame(0, (int) $res->result, 'invalid email is rejected');
        $this->assertNotNull($res->form, 'field errors are returned');
        $this->assertArrayHasKey('email', $res->form);

        $after = (int) $this->db->fetchOne('SELECT COUNT(*) FROM user');
        $this->assertSame($before, $after, 'nothing was inserted (rollback / pre-transaction reject)');
    }

    #[Test]
    public function save_rejects_a_duplicate_email_with_a_friendly_error(): void
    {
        $this->loginAs('admin');
        (new Tiger_Model_User())->insert(['email' => 'taken@w3ctest.com', 'status' => 'active']);
        $before = (int) $this->db->fetchOne('SELECT COUNT(*) FROM user');

        $res = $this->call('save', ['email' => 'taken@w3ctest.com', 'status' => 'active']);

        $this->assertSame(0, (int) $res->result);
        $this->assertStringContainsString('email_taken', json_encode($res->messages));
        $this->assertSame($before, (int) $this->db->fetchOne('SELECT COUNT(*) FROM user'), 'no duplicate written');
    }

    #[Test]
    public function save_updates_an_existing_user_in_place(): void
    {
        $this->loginAs('admin');
        $id = (new Tiger_Model_User())->insert(['email' => 'before@w3ctest.com', 'status' => 'active']);
        $this->escapeTxn();

        $res = $this->call('save', ['user_id' => $id, 'email' => 'after@w3ctest.com', 'status' => 'suspended']);
        $this->assertSame(1, (int) $res->result);
        $this->assertSame($id, $res->data['user_id'], 'same id — an update, not an insert');

        $row = (new Tiger_Model_User())->findById($id);
        $this->assertSame('after@w3ctest.com', $row->email);
        $this->assertSame('suspended', $row->status);
    }

    #[Test]
    public function save_sets_a_password_credential_when_provided(): void
    {
        $this->loginAs('admin');
        $this->escapeTxn();
        $res = $this->call('save', ['email' => 'withpw@w3ctest.com', 'status' => 'active', 'new_password' => 'S3cure-P@ssw0rd-9x']);
        $this->assertSame(1, (int) $res->result, 'valid password policy accepted');
        $id = $res->data['user_id'];

        $count = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM user_credential WHERE user_id = ? AND type = 'password'",
            [$id]
        );
        $this->assertSame(1, $count, 'a password credential row was created');
    }

    // ----- delete (soft-delete) -----------------------------------------------------------------

    #[Test]
    public function delete_soft_deletes_and_reads_exclude_it(): void
    {
        $this->loginAs('admin');
        $model = new Tiger_Model_User();
        $id = $model->insert(['email' => 'doomed@w3ctest.com', 'status' => 'active']);

        $res = $this->call('delete', ['user_id' => $id]);
        $this->assertSame(1, (int) $res->result);

        $this->assertSame(1, (int) $this->db->fetchOne('SELECT deleted FROM user WHERE user_id = ?', [$id]), 'flag flipped');
        $this->assertNull($model->findById($id), 'findById excludes the soft-deleted row');
    }

    #[Test]
    public function delete_refuses_to_delete_your_own_account(): void
    {
        $model = new Tiger_Model_User();
        $meId  = $model->insert(['email' => 'self-del@w3ctest.com', 'status' => 'active']);
        $this->login($meId, 'org-test', 'admin');

        $res = $this->call('delete', ['user_id' => $meId]);
        $this->assertSame(0, (int) $res->result, 'self-delete refused');
        $this->assertStringContainsString('no_self_delete', json_encode($res->messages));
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT deleted FROM user WHERE user_id = ?', [$meId]), 'still live');
    }
}
