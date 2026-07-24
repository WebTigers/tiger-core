<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Profile;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Profile_Service_Contact;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_User;
use Zend_Registry;

/**
 * Profile_Service_Contact — the self-service Contacts tab (/api).
 *
 * Wave-4 coverage: the self-scope gate, create (a shared `contact` channel + a `user_contact` link),
 * edit-in-place (by link_id, ownership enforced), the type membership guard, the phone E.164 shape
 * guard (+ the ISO-country stashed on contact.type), the single-primary rule, delete (unlink +
 * soft-delete, ownership enforced), and the refreshed-list + rotated-token envelope.
 */
#[CoversClass(Profile_Service_Contact::class)]
final class ContactServiceTest extends IntegrationTestCase
{
    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();
        Zend_Registry::set('tiger.auth.stateless', true);
        $this->userId = (new Tiger_Model_User())->insert(['email' => 'contact@w4test.com', 'status' => 'active']);
        $this->login($this->userId, 'org-test', 'user');
    }

    protected function tearDown(): void
    {
        $reg = Zend_Registry::getInstance();
        if ($reg->offsetExists('tiger.auth.stateless')) { $reg->offsetUnset('tiger.auth.stateless'); }
        parent::tearDown();
    }

    private function call(string $action, array $params = []): object
    {
        return (new Profile_Service_Contact(['action' => $action] + $params))->getResponse();
    }

    // ----- gate ---------------------------------------------------------------------------------

    #[Test]
    public function a_guest_is_refused_on_save_and_delete(): void
    {
        $this->logout();
        $this->assertSame(0, (int) $this->call('save', ['type' => 'email', 'value' => 'a@b.com'])->result);
        $this->assertSame(0, (int) $this->call('delete', ['link_id' => 'x'])->result);
    }

    // ----- create / edit --------------------------------------------------------------------------

    #[Test]
    public function create_inserts_the_channel_and_link_and_returns_the_refreshed_list(): void
    {
        $res = $this->call('save', ['type' => 'email', 'value' => 'me@example.com', 'is_primary' => 1]);
        $this->assertSame(1, (int) $res->result);
        $this->assertStringContainsString('contact.saved', json_encode($res->messages));
        $this->assertArrayHasKey('contacts', $res->data);
        $this->assertArrayHasKey('_csrf', $res->data);
        $this->assertCount(1, $res->data['contacts']);
        $this->assertSame('me@example.com', $res->data['contacts'][0]['value']);
        $this->assertSame(1, (int) $res->data['contacts'][0]['is_primary']);
    }

    #[Test]
    public function edit_updates_the_existing_contact_in_place(): void
    {
        $created = $this->call('save', ['type' => 'email', 'value' => 'old@example.com']);
        $linkId  = $created->data['contacts'][0]['link_id'];

        $res = $this->call('save', ['link_id' => $linkId, 'type' => 'email', 'value' => 'new@example.com']);
        $this->assertSame(1, (int) $res->result);
        $this->assertCount(1, $res->data['contacts'], 'an edit does not add a row');
        $this->assertSame('new@example.com', $res->data['contacts'][0]['value']);
    }

    // ----- validation ----------------------------------------------------------------------------

    #[Test]
    public function an_unknown_type_is_refused(): void
    {
        $res = $this->call('save', ['type' => 'telepathy', 'value' => 'x']);
        $this->assertSame(0, (int) $res->result);
        $this->assertStringContainsString('bad_type', json_encode($res->messages));
    }

    #[Test]
    public function a_missing_required_value_returns_form_errors(): void
    {
        $res = $this->call('save', ['type' => 'email']);
        $this->assertSame(0, (int) $res->result);
        $this->assertNotNull($res->form);
        $this->assertArrayHasKey('value', $res->form);
    }

    // ----- phone --------------------------------------------------------------------------------

    #[Test]
    public function a_malformed_phone_e164_is_refused(): void
    {
        $res = $this->call('save', ['type' => 'phone', 'value' => '555-1234', 'phone_country' => 'US']);
        $this->assertSame(0, (int) $res->result);
        $this->assertStringContainsString('bad_phone', json_encode($res->messages));
    }

    #[Test]
    public function a_valid_phone_stores_the_number_and_stashes_the_iso_country(): void
    {
        $res = $this->call('save', ['type' => 'phone', 'value' => '+15551234567', 'phone_country' => 'us']);
        $this->assertSame(1, (int) $res->result, 'a canonical E.164 number is accepted');

        $link = $res->data['contacts'][0]['link_id'];
        $contactId = $this->db->fetchOne('SELECT contact_id FROM user_contact WHERE user_contact_id = ?', [$link]);
        $row = $this->db->fetchRow('SELECT kind, type, value FROM contact WHERE contact_id = ?', [$contactId]);
        $this->assertSame('phone', $row['kind']);
        $this->assertSame('US', $row['type'], 'the picked ISO-3166 country is stashed on contact.type');
        $this->assertSame('+15551234567', $row['value']);
    }

    // ----- single-primary -----------------------------------------------------------------------

    #[Test]
    public function setting_a_new_primary_clears_the_previous_primary(): void
    {
        $first   = $this->call('save', ['type' => 'email', 'value' => 'first@example.com', 'is_primary' => 1]);
        $firstId = $first->data['contacts'][0]['link_id'];

        $second = $this->call('save', ['type' => 'email', 'value' => 'second@example.com', 'is_primary' => 1]);

        $byLink = [];
        foreach ($second->data['contacts'] as $r) { $byLink[$r['link_id']] = (int) $r['is_primary']; }
        $this->assertSame(0, $byLink[$firstId], 'the former primary was cleared');
        $this->assertSame(1, array_sum($byLink), 'exactly one primary remains');
    }

    // ----- delete -------------------------------------------------------------------------------

    #[Test]
    public function delete_unlinks_and_soft_deletes_the_channel(): void
    {
        $created = $this->call('save', ['type' => 'email', 'value' => 'gone@example.com']);
        $linkId  = $created->data['contacts'][0]['link_id'];
        $contactId = $this->db->fetchOne('SELECT contact_id FROM user_contact WHERE user_contact_id = ?', [$linkId]);

        $res = $this->call('delete', ['link_id' => $linkId]);
        $this->assertSame(1, (int) $res->result);
        $this->assertStringContainsString('contact.deleted', json_encode($res->messages));
        $this->assertEmpty($res->data['contacts']);

        $this->assertSame(1, (int) $this->db->fetchOne('SELECT deleted FROM user_contact WHERE user_contact_id = ?', [$linkId]));
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT deleted FROM contact WHERE contact_id = ?', [$contactId]));
    }

    #[Test]
    public function you_cannot_edit_or_delete_a_contact_that_is_not_yours(): void
    {
        $otherId = (new Tiger_Model_User())->insert(['email' => 'other@w4test.com', 'status' => 'active']);
        $this->login($otherId, 'org-test', 'user');
        $foreign = $this->call('save', ['type' => 'email', 'value' => 'theirs@example.com'])->data['contacts'][0]['link_id'];

        $this->login($this->userId, 'org-test', 'user');
        $this->assertSame(0, (int) $this->call('save', ['link_id' => $foreign, 'type' => 'email', 'value' => 'hijack@example.com'])->result);
        $this->assertSame(0, (int) $this->call('delete', ['link_id' => $foreign])->result);
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT deleted FROM user_contact WHERE user_contact_id = ?', [$foreign]), 'the foreign link is untouched');
    }
}
