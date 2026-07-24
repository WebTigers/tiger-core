<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Session;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Session_SaveHandler_DbTable;
use Zend_Config;
use Zend_Registry;

/**
 * Tiger_Session_SaveHandler_DbTable — the DB-backed PHP session handler (required behind a multi-
 * instance load balancer, where file sessions live on separate boxes). It extends ZF1's DbTable handler
 * to (a) validate the session id, (b) stamp the current user/username/role/org/ip onto the row for
 * auditing + admin session views, and (c) apply a tiered, config-driven idle TTL (privileged roles get
 * the short, sensitive lifetime). GC reaps expired rows.
 *
 * The handler methods are exercised directly against the real `session` table (no PHP session start),
 * inside the per-test transaction so writes roll back. The current identity is set via login(), which
 * the handler reads through Zend_Auth exactly as a live request does.
 */
#[CoversClass(Tiger_Session_SaveHandler_DbTable::class)]
final class DbTableSaveHandlerTest extends IntegrationTestCase
{
    private function handler(): Tiger_Session_SaveHandler_DbTable
    {
        // The same construction the app Bootstrap uses (Bootstrap::_initSession).
        return new Tiger_Session_SaveHandler_DbTable([
            'name'           => 'session',
            'primary'        => 'session_id',
            'modifiedColumn' => 'modified',
            'dataColumn'     => 'data',
            'lifetimeColumn' => 'lifetime',
        ]);
    }

    private function rowCount(string $id): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM session WHERE session_id = ?', [$id]);
    }

    #[Test]
    public function open_and_close_return_true(): void
    {
        $h = $this->handler();
        $this->assertTrue($h->open('/tmp', 'PHPSESSID'));
        $this->assertTrue($h->close());
    }

    #[Test]
    public function write_rejects_a_malformed_session_id(): void
    {
        $this->assertFalse($this->handler()->write('bad id with spaces!', 'x'));
        $this->assertFalse($this->handler()->write('', 'x'));
    }

    #[Test]
    public function an_empty_anonymous_session_is_not_persisted(): void
    {
        $id = 'guestempty0000000000000000000001';
        $this->assertTrue($this->handler()->write($id, ''), 'a skipped empty guest write still reports success');
        $this->assertSame(0, $this->rowCount($id), 'no row per anonymous hit');
    }

    #[Test]
    public function write_persists_a_session_and_stamps_the_identity_context(): void
    {
        $this->login('user-writer', 'org-writer', 'manager');
        $id = 'sess00000000000000000000000000writer';
        $this->assertTrue($this->handler()->write($id, 'the-payload'));

        $row = $this->db->fetchRow('SELECT * FROM session WHERE session_id = ?', [$id]);
        $this->assertSame('the-payload', $row['data']);
        $this->assertSame('user-writer', $row['user_id']);
        $this->assertSame('manager', $row['role']);
        $this->assertSame('org-writer', $row['org_id']);
        $this->assertSame(604800, (int) $row['lifetime'], 'an authenticated non-privileged role gets the long TTL');
    }

    #[Test]
    public function a_privileged_role_gets_the_short_sensitive_ttl(): void
    {
        $this->login('user-admin', 'org-a', 'admin');
        $id = 'sess0000000000000000000000000adminx';
        $this->handler()->write($id, 'x');

        $row = $this->db->fetchRow('SELECT lifetime FROM session WHERE session_id = ?', [$id]);
        $this->assertSame(28800, (int) $row['lifetime'], 'admin/superadmin/developer get the 8h tier');
    }

    #[Test]
    public function a_guest_session_with_data_persists_at_the_guest_ttl(): void
    {
        // A guest (no identity) with non-empty data IS persisted — only empty guest sessions skip.
        $this->logout();
        $id = 'sess0000000000000000000000000guestd';
        $this->handler()->write($id, 'cart=1');

        $row = $this->db->fetchRow('SELECT role, lifetime, user_id FROM session WHERE session_id = ?', [$id]);
        $this->assertSame('guest', $row['role']);
        $this->assertNull($row['user_id']);
        $this->assertSame(86400, (int) $row['lifetime'], 'the 1d guest tier');
    }

    #[Test]
    public function the_ttl_is_config_overridable_live(): void
    {
        Zend_Registry::set('Zend_Config', new Zend_Config(['tiger' => ['session' => ['ttl' => ['privileged' => '3600']]]]));
        $this->login('user-admin2', 'org-a', 'superadmin');
        $id = 'sess000000000000000000000000cfgttl';
        $this->handler()->write($id, 'x');

        $row = $this->db->fetchRow('SELECT lifetime FROM session WHERE session_id = ?', [$id]);
        $this->assertSame(3600, (int) $row['lifetime'], 'the config tier overrides the default privileged TTL');
    }

    #[Test]
    public function read_returns_the_written_payload(): void
    {
        $this->login('user-reader', 'org-r', 'user');
        $id = 'sess0000000000000000000000000reader';
        $this->handler()->write($id, 'session-body');

        $this->assertSame('session-body', $this->handler()->read($id));
    }

    #[Test]
    public function read_of_an_unknown_id_is_empty(): void
    {
        $this->assertSame('', $this->handler()->read('sess000000000000000000000000unknown'));
    }

    #[Test]
    public function read_of_an_expired_row_returns_empty_and_destroys_it(): void
    {
        $this->login('user-exp', 'org-e', 'user');
        $id = 'sess00000000000000000000000expired0';
        $this->handler()->write($id, 'stale');

        // Age the row so (modified + lifetime) < now — the read must treat it as expired.
        $this->db->update('session', ['modified' => time() - 999999999], ['session_id = ?' => $id]);

        $this->assertSame('', $this->handler()->read($id), 'an expired session reads empty');
        $this->assertSame(0, $this->rowCount($id), 'and it is destroyed on read');
    }

    #[Test]
    public function destroy_removes_the_row(): void
    {
        $this->login('user-del', 'org-d', 'user');
        $id = 'sess0000000000000000000000000delete';
        $this->handler()->write($id, 'x');
        $this->assertSame(1, $this->rowCount($id));

        $this->assertTrue($this->handler()->destroy($id));
        $this->assertSame(0, $this->rowCount($id));
    }

    #[Test]
    public function gc_reaps_expired_rows_and_never_throws(): void
    {
        $this->login('user-gc', 'org-g', 'user');
        $live = 'sess000000000000000000000000gclive0';
        $dead = 'sess000000000000000000000000gcdead0';
        $this->handler()->write($live, 'fresh');
        $this->handler()->write($dead, 'old');
        $this->db->update('session', ['modified' => time() - 999999999], ['session_id = ?' => $dead]);

        $this->assertTrue($this->handler()->gc(0));
        $this->assertSame(1, $this->rowCount($live), 'a live session survives GC');
        $this->assertSame(0, $this->rowCount($dead), 'an expired session is reaped');
    }
}
