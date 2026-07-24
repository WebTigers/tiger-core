<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Profile;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Profile_Service_OrgAddress;
use Tiger\Tests\Support\IntegrationTestCase;
use Zend_Registry;

/**
 * Profile_Service_OrgAddress — the admin-gated ORG Addresses tab (/api).
 *
 * The org twin of Profile_Service_Address: identical CRUD, but admin-gated and scoped to the CURRENT
 * org ($this->_org_id). Wave-4 coverage: the admin gate (guest + plain user refused), create/edit,
 * the type/country guards, the single-primary rule, the coordinate normalizer, delete, and the
 * cross-org ownership guard (an admin in org B can't touch org A's link).
 */
#[CoversClass(Profile_Service_OrgAddress::class)]
final class OrgAddressServiceTest extends IntegrationTestCase
{
    private string $orgId = 'org-w4-addr';

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
        return (new Profile_Service_OrgAddress(['action' => $action] + $params))->getResponse();
    }

    private function validAddress(array $over = []): array
    {
        return $over + [
            'type'    => 'office',
            'country' => 'US',
            'line1'   => '1 Corporate Way',
            'city'    => 'Metropolis',
            'region'  => 'NY',
            'postal'  => '10001',
        ];
    }

    // ----- gate ---------------------------------------------------------------------------------

    #[Test]
    public function guest_and_plain_user_are_denied(): void
    {
        $this->login('anon', $this->orgId, 'guest');
        $this->assertSame(0, (int) $this->call('save', $this->validAddress())->result);
        $this->assertStringContainsString('not_allowed', json_encode($this->call('delete', ['link_id' => 'x'])->messages));

        $this->loginAs('user');
        $this->assertSame(0, (int) $this->call('save', $this->validAddress())->result, 'a plain user is not an org admin');
    }

    // ----- create / edit ------------------------------------------------------------------------

    #[Test]
    public function admin_creates_and_edits_an_org_address(): void
    {
        $created = $this->call('save', $this->validAddress(['is_primary' => 1]));
        $this->assertSame(1, (int) $created->result);
        $this->assertCount(1, $created->data['addresses']);
        $this->assertArrayHasKey('_csrf', $created->data);
        $linkId = $created->data['addresses'][0]['link_id'];

        $edited = $this->call('save', $this->validAddress(['link_id' => $linkId, 'type' => 'mailing', 'line1' => 'PO Box 9']));
        $this->assertSame(1, (int) $edited->result);
        $this->assertCount(1, $edited->data['addresses'], 'edit is in place');
        $this->assertSame('mailing', $edited->data['addresses'][0]['label']);
        $this->assertSame('PO Box 9', $edited->data['addresses'][0]['line1']);
    }

    #[Test]
    public function type_and_country_guards_fire(): void
    {
        $this->assertStringContainsString('bad_type', json_encode($this->call('save', $this->validAddress(['type' => 'nope']))->messages));
        $this->assertStringContainsString('bad_country', json_encode($this->call('save', $this->validAddress(['country' => 'QQ']))->messages));
    }

    #[Test]
    public function the_coordinate_normalizer_nulls_out_of_range_values(): void
    {
        $res    = $this->call('save', $this->validAddress(['latitude' => '999', 'longitude' => '12.34']));
        $link   = end($res->data['addresses'])['link_id'];
        $addrId = $this->db->fetchOne('SELECT address_id FROM org_address WHERE org_address_id = ?', [$link]);
        $stored = $this->db->fetchRow('SELECT latitude, longitude FROM address WHERE address_id = ?', [$addrId]);
        $this->assertNull($stored['latitude'], 'an out-of-range latitude stores NULL');
        $this->assertEqualsWithDelta(12.34, (float) $stored['longitude'], 0.001, 'an in-range longitude is kept');
    }

    #[Test]
    public function setting_a_new_primary_clears_the_previous_one(): void
    {
        $first   = $this->call('save', $this->validAddress(['is_primary' => 1]));
        $firstId = $first->data['addresses'][0]['link_id'];
        $second  = $this->call('save', $this->validAddress(['type' => 'mailing', 'line1' => '2 Second St', 'is_primary' => 1]));

        $byLink = [];
        foreach ($second->data['addresses'] as $r) { $byLink[$r['link_id']] = (int) $r['is_primary']; }
        $this->assertSame(0, $byLink[$firstId]);
        $this->assertSame(1, array_sum($byLink));
    }

    // ----- delete + cross-org -------------------------------------------------------------------

    #[Test]
    public function delete_unlinks_and_soft_deletes(): void
    {
        $link   = $this->call('save', $this->validAddress())->data['addresses'][0]['link_id'];
        $addrId = $this->db->fetchOne('SELECT address_id FROM org_address WHERE org_address_id = ?', [$link]);

        $res = $this->call('delete', ['link_id' => $link]);
        $this->assertSame(1, (int) $res->result);
        $this->assertEmpty($res->data['addresses']);
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT deleted FROM org_address WHERE org_address_id = ?', [$link]));
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT deleted FROM address WHERE address_id = ?', [$addrId]));
    }

    #[Test]
    public function an_admin_cannot_touch_another_orgs_address(): void
    {
        // org A owns a link.
        $this->login('admin-a', 'org-A', 'admin');
        $foreign = $this->call('save', $this->validAddress())->data['addresses'][0]['link_id'];

        // admin in org B tries to edit + delete it.
        $this->login('admin-b', 'org-B', 'admin');
        $this->assertSame(0, (int) $this->call('save', $this->validAddress(['link_id' => $foreign, 'line1' => 'hijack']))->result);
        $this->assertSame(0, (int) $this->call('delete', ['link_id' => $foreign])->result);
        $this->assertSame(0, (int) $this->db->fetchOne('SELECT deleted FROM org_address WHERE org_address_id = ?', [$foreign]), 'the other org link is untouched');
    }
}
