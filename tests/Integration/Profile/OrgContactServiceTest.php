<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Profile;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Profile_Service_OrgContact;
use Tiger\Tests\Support\IntegrationTestCase;
use Zend_Registry;

/**
 * Profile_Service_OrgContact — the admin-gated ORG Contacts tab (/api).
 *
 * The org twin of Profile_Service_Contact: admin-gated + scoped to the CURRENT org. Wave-4 coverage:
 * the admin gate, create/edit, the type + phone-E.164 guards (ISO country stashed on contact.type),
 * the single-primary rule, delete, and the cross-org ownership guard.
 */
#[CoversClass(Profile_Service_OrgContact::class)]
final class OrgContactServiceTest extends IntegrationTestCase
{
    private string $orgId = 'org-w4-contact';

    protected function setUp(): void
    {
        parent::setUp();
        Zend_Registry::set('tiger.auth.stateless', true);
        $this->login('admin-actor', $this->orgId, 'admin');
    }

    protected function tearDown(): void
    {
        $reg = Zend_Registry::getInstance();
        if ($reg->offsetExists('tiger.auth.stateless')) { $reg->offsetUnset('tiger.auth.stateless'); }
        parent::tearDown();
    }

    private function call(string $action, array $params = []): object
    {
        return (new Profile_Service_OrgContact(['action' => $action] + $params))->getResponse();
    }

    // ----- gate ---------------------------------------------------------------------------------

    #[Test]
    public function guest_and_plain_user_are_denied(): void
    {
        $this->login('anon', $this->orgId, 'guest');
        $this->assertStringContainsString('not_allowed', json_encode($this->call('save', ['type' => 'email', 'value' => 'a@b.com'])->messages));
        $this->assertStringContainsString('not_allowed', json_encode($this->call('delete', ['link_id' => 'x'])->messages));

        $this->loginAs('user');
        $this->assertSame(0, (int) $this->call('save', ['type' => 'email', 'value' => 'a@b.com'])->result);
    }

    // ----- create / edit ------------------------------------------------------------------------

    #[Test]
    public function admin_creates_and_edits_an_org_contact(): void
    {
        $created = $this->call('save', ['type' => 'email', 'value' => 'info@acme.com', 'is_primary' => 1]);
        $this->assertSame(1, (int) $created->result);
        $this->assertCount(1, $created->data['contacts']);
        $this->assertArrayHasKey('_csrf', $created->data);
        $linkId = $created->data['contacts'][0]['link_id'];

        $edited = $this->call('save', ['link_id' => $linkId, 'type' => 'website', 'value' => 'https://acme.com']);
        $this->assertSame(1, (int) $edited->result);
        $this->assertCount(1, $edited->data['contacts'], 'edit is in place');
        $this->assertSame('https://acme.com', $edited->data['contacts'][0]['value']);
    }

    // ----- validation ---------------------------------------------------------------------------

    #[Test]
    public function an_unknown_type_is_refused(): void
    {
        $this->assertStringContainsString('bad_type', json_encode($this->call('save', ['type' => 'smoke-signal', 'value' => 'x'])->messages));
    }

    #[Test]
    public function a_malformed_phone_is_refused_and_a_valid_one_stashes_the_country(): void
    {
        $this->assertStringContainsString('bad_phone', json_encode($this->call('save', ['type' => 'phone', 'value' => '01234', 'phone_country' => 'GB'])->messages));

        $ok   = $this->call('save', ['type' => 'phone', 'value' => '+442071234567', 'phone_country' => 'gb']);
        $this->assertSame(1, (int) $ok->result);
        $link = $ok->data['contacts'][0]['link_id'];
        $contactId = $this->db->fetchOne('SELECT contact_id FROM org_contact WHERE org_contact_id = ?', [$link]);
        $this->assertSame('GB', $this->db->fetchOne('SELECT type FROM contact WHERE contact_id = ?', [$contactId]));
    }

    // ----- single-primary + delete + cross-org --------------------------------------------------

    #[Test]
    public function setting_a_new_primary_clears_the_previous_one(): void
    {
        $first   = $this->call('save', ['type' => 'email', 'value' => 'a@acme.com', 'is_primary' => 1]);
        $firstId = $first->data['contacts'][0]['link_id'];
        $second  = $this->call('save', ['type' => 'email', 'value' => 'b@acme.com', 'is_primary' => 1]);

        $byLink = [];
        foreach ($second->data['contacts'] as $r) { $byLink[$r['link_id']] = (int) $r['is_primary']; }
        $this->assertSame(0, $byLink[$firstId]);
        $this->assertSame(1, array_sum($byLink));
    }

    #[Test]
    public function delete_unlinks_and_soft_deletes(): void
    {
        $link      = $this->call('save', ['type' => 'email', 'value' => 'bye@acme.com'])->data['contacts'][0]['link_id'];
        $contactId = $this->db->fetchOne('SELECT contact_id FROM org_contact WHERE org_contact_id = ?', [$link]);

        $res = $this->call('delete', ['link_id' => $link]);
        $this->assertSame(1, (int) $res->result);
        $this->assertEmpty($res->data['contacts']);
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT deleted FROM org_contact WHERE org_contact_id = ?', [$link]));
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT deleted FROM contact WHERE contact_id = ?', [$contactId]));
    }

    #[Test]
    public function an_admin_cannot_touch_another_orgs_contact(): void
    {
        $this->login('admin-a', 'org-CA', 'admin');
        $foreign = $this->call('save', ['type' => 'email', 'value' => 'theirs@acme.com'])->data['contacts'][0]['link_id'];

        $this->login('admin-b', 'org-CB', 'admin');
        $this->assertSame(0, (int) $this->call('save', ['link_id' => $foreign, 'type' => 'email', 'value' => 'hijack@acme.com'])->result);
        $this->assertSame(0, (int) $this->call('delete', ['link_id' => $foreign])->result);
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT deleted FROM org_contact WHERE org_contact_id = ?', [$foreign]));
    }
}
