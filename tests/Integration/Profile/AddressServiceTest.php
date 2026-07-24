<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Profile;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Profile_Service_Address;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_User;
use Tiger_Model_UserAddress;
use Zend_Registry;

/**
 * Profile_Service_Address — the self-service Addresses tab (/api).
 *
 * Wave-4 coverage of the collection CRUD: the self-scope gate, create (a shared `address` row + a
 * `user_address` link carrying label + is_primary), edit-in-place (by link_id, ownership enforced),
 * the type/country membership guards, the single-primary rule (setting one clears the others), the
 * coordinate range-normalizer, delete (unlink + soft-delete the location, ownership enforced), and
 * the success envelope (the refreshed list + a rotated CSRF token). Extends Profile_Service_Base, so
 * these also cover _soloPrimary / _freshToken / _validCsrf.
 */
#[CoversClass(Profile_Service_Address::class)]
final class AddressServiceTest extends IntegrationTestCase
{
    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();
        Zend_Registry::set('tiger.auth.stateless', true);
        $this->userId = (new Tiger_Model_User())->insert(['email' => 'addr@w4test.com', 'status' => 'active']);
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
        return (new Profile_Service_Address(['action' => $action] + $params))->getResponse();
    }

    private function validAddress(array $over = []): array
    {
        return $over + [
            'type'    => 'home',
            'country' => 'US',
            'line1'   => '123 Main St',
            'city'    => 'Springfield',
            'region'  => 'IL',
            'postal'  => '62704',
        ];
    }

    // ----- gate ---------------------------------------------------------------------------------

    #[Test]
    public function a_guest_is_refused_on_save_and_delete(): void
    {
        $this->logout();
        $this->assertSame(0, (int) $this->call('save', $this->validAddress())->result);
        $this->assertSame(0, (int) $this->call('delete', ['link_id' => 'x'])->result);
    }

    // ----- create / envelope --------------------------------------------------------------------

    #[Test]
    public function create_inserts_the_location_and_link_and_returns_the_refreshed_list(): void
    {
        $res = $this->call('save', $this->validAddress(['is_primary' => 1]));
        $this->assertSame(1, (int) $res->result, 'a valid address saves');
        $this->assertStringContainsString('address.saved', json_encode($res->messages));

        // The success envelope carries the refreshed list + a rotated token key.
        $this->assertArrayHasKey('addresses', $res->data);
        $this->assertArrayHasKey('_csrf', $res->data);
        $this->assertCount(1, $res->data['addresses']);

        $row = $res->data['addresses'][0];
        $this->assertSame('home', $row['label'], 'the type is stored on the link label');
        $this->assertSame('123 Main St', $row['line1']);
        $this->assertSame('US', $row['country']);
        $this->assertSame(1, (int) $row['is_primary']);
    }

    #[Test]
    public function edit_updates_the_existing_link_and_location_in_place(): void
    {
        $created = $this->call('save', $this->validAddress());
        $linkId  = $created->data['addresses'][0]['link_id'];

        $res = $this->call('save', $this->validAddress([
            'link_id' => $linkId,
            'type'    => 'office',
            'line1'   => '999 New Ave',
        ]));
        $this->assertSame(1, (int) $res->result);
        $this->assertCount(1, $res->data['addresses'], 'an edit updates in place, no new row');
        $this->assertSame('office', $res->data['addresses'][0]['label']);
        $this->assertSame('999 New Ave', $res->data['addresses'][0]['line1']);
    }

    // ----- validation guards --------------------------------------------------------------------

    #[Test]
    public function an_unknown_type_is_refused(): void
    {
        $res = $this->call('save', $this->validAddress(['type' => 'moonbase']));
        $this->assertSame(0, (int) $res->result);
        $this->assertStringContainsString('bad_type', json_encode($res->messages));
    }

    #[Test]
    public function an_unknown_country_is_refused(): void
    {
        $res = $this->call('save', $this->validAddress(['country' => 'QQ']));   // QQ is not an ISO-3166 code
        $this->assertSame(0, (int) $res->result);
        $this->assertStringContainsString('bad_country', json_encode($res->messages));
    }

    #[Test]
    public function a_missing_required_line1_returns_form_errors(): void
    {
        $params = $this->validAddress();
        unset($params['line1']);
        $res = $this->call('save', $params);
        $this->assertSame(0, (int) $res->result);
        $this->assertNotNull($res->form);
        $this->assertArrayHasKey('line1', $res->form);
    }

    // ----- coordinate normalization -------------------------------------------------------------

    #[Test]
    public function an_in_range_geocode_is_stored_and_an_out_of_range_one_is_nulled(): void
    {
        $ok = $this->call('save', $this->validAddress(['latitude' => '39.78', 'longitude' => '-89.65']));
        $addrId = $this->db->fetchOne('SELECT address_id FROM user_address WHERE user_address_id = ?', [$ok->data['addresses'][0]['link_id']]);
        $stored = $this->db->fetchRow('SELECT latitude, longitude FROM address WHERE address_id = ?', [$addrId]);
        $this->assertEqualsWithDelta(39.78, (float) $stored['latitude'], 0.001, 'a valid latitude is stored');
        $this->assertEqualsWithDelta(-89.65, (float) $stored['longitude'], 0.001);

        $bad = $this->call('save', $this->validAddress(['latitude' => '999', 'longitude' => 'not-a-number']));
        // No primary set, so the list is ordered oldest-first — the row we just added is LAST.
        $badLink = end($bad->data['addresses'])['link_id'];
        $addrId2 = $this->db->fetchOne('SELECT address_id FROM user_address WHERE user_address_id = ?', [$badLink]);
        $stored2 = $this->db->fetchRow('SELECT latitude, longitude FROM address WHERE address_id = ?', [$addrId2]);
        $this->assertNull($stored2['latitude'], 'an out-of-range latitude stores NULL');
        $this->assertNull($stored2['longitude'], 'a non-numeric longitude stores NULL');
    }

    // ----- single-primary rule ------------------------------------------------------------------

    #[Test]
    public function setting_a_new_primary_clears_the_previous_primary(): void
    {
        $first  = $this->call('save', $this->validAddress(['type' => 'home', 'is_primary' => 1]));
        $firstId = $first->data['addresses'][0]['link_id'];

        $second = $this->call('save', $this->validAddress(['type' => 'office', 'line1' => '2 Office Rd', 'is_primary' => 1]));

        // The freshly-saved primary is the office one; the old home link must no longer be primary.
        $byLink = [];
        foreach ($second->data['addresses'] as $r) { $byLink[$r['link_id']] = (int) $r['is_primary']; }
        $this->assertSame(0, $byLink[$firstId], 'the former primary was cleared');
        $primaries = array_sum($byLink);
        $this->assertSame(1, $primaries, 'exactly one primary remains');
    }

    // ----- delete -------------------------------------------------------------------------------

    #[Test]
    public function delete_unlinks_and_soft_deletes_the_location(): void
    {
        $created = $this->call('save', $this->validAddress());
        $linkId  = $created->data['addresses'][0]['link_id'];
        $addrId  = $this->db->fetchOne('SELECT address_id FROM user_address WHERE user_address_id = ?', [$linkId]);

        $res = $this->call('delete', ['link_id' => $linkId]);
        $this->assertSame(1, (int) $res->result);
        $this->assertStringContainsString('address.deleted', json_encode($res->messages));
        $this->assertEmpty($res->data['addresses'], 'the list is empty after the delete');

        $this->assertSame(1, (int) $this->db->fetchOne('SELECT deleted FROM user_address WHERE user_address_id = ?', [$linkId]), 'link soft-deleted');
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT deleted FROM address WHERE address_id = ?', [$addrId]), 'location soft-deleted');
    }

    #[Test]
    public function you_cannot_edit_an_address_that_is_not_yours(): void
    {
        // Another user's address.
        $otherId = (new Tiger_Model_User())->insert(['email' => 'other@w4test.com', 'status' => 'active']);
        $this->login($otherId, 'org-test', 'user');
        $foreign = $this->call('save', $this->validAddress())->data['addresses'][0]['link_id'];

        // Back to me: try to edit their link.
        $this->login($this->userId, 'org-test', 'user');
        $res = $this->call('save', $this->validAddress(['link_id' => $foreign, 'line1' => 'hijack']));
        $this->assertSame(0, (int) $res->result, 'editing a foreign address is refused');
    }

    #[Test]
    public function you_cannot_delete_an_address_that_is_not_yours(): void
    {
        $otherId = (new Tiger_Model_User())->insert(['email' => 'other2@w4test.com', 'status' => 'active']);
        $this->login($otherId, 'org-test', 'user');
        $foreign = $this->call('save', $this->validAddress())->data['addresses'][0]['link_id'];

        $this->login($this->userId, 'org-test', 'user');
        $res = $this->call('delete', ['link_id' => $foreign]);
        $this->assertSame(0, (int) $res->result, 'deleting a foreign address is refused');
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT deleted FROM user_address WHERE user_address_id = ?', [$foreign]), 'the foreign link is untouched');
    }
}
