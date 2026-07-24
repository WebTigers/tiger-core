<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_Address;
use Tiger_Model_Contact;
use Tiger_Model_OrgAddress;
use Tiger_Model_OrgContact;
use Tiger_Model_UserAddress;
use Tiger_Model_UserContact;
use Tiger_Uuid;

/**
 * The owner-agnostic address/contact primitives and their org/user link tables.
 *
 * Two invariants under test:
 *   - the DATA row (address/contact) belongs to no one; ownership + the relationship label
 *     (`label`, `is_primary`) live on the LINK row, so one shared row means different things to
 *     different owners;
 *   - the read shape `withAddress()/withContact()` joins link→data, aliases the link PK to the
 *     generic `link_id` (so the shared Addresses/Contacts view works for a user OR an org), and
 *     orders primary-first then oldest — and never surfaces a soft-deleted link.
 */
#[CoversClass(Tiger_Model_Address::class)]
#[CoversClass(Tiger_Model_Contact::class)]
#[CoversClass(Tiger_Model_OrgAddress::class)]
#[CoversClass(Tiger_Model_UserAddress::class)]
#[CoversClass(Tiger_Model_OrgContact::class)]
#[CoversClass(Tiger_Model_UserContact::class)]
final class ContactAddressTest extends IntegrationTestCase
{
    #[Test]
    public function address_create_mints_a_uuid_and_persists_the_location(): void
    {
        $addr = new Tiger_Model_Address();
        $id   = $addr->create(['line1' => '1 Tiger Way', 'city' => 'Reno', 'region' => 'NV', 'postal' => '89501', 'country' => 'US']);

        $this->assertNotSame('', $id, 'create() returns the minted id');
        $row = $addr->findById($id);
        $this->assertSame('1 Tiger Way', $row->line1);
        $this->assertSame('Reno', $row->city);
    }

    #[Test]
    public function an_org_address_link_carries_the_relationship_and_reads_back_primary_first(): void
    {
        $orgId = Tiger_Uuid::v7();
        $addr  = new Tiger_Model_Address();
        $link  = new Tiger_Model_OrgAddress();

        $billing  = $addr->create(['line1' => '10 Billing St', 'city' => 'Reno']);
        $shipping = $addr->create(['line1' => '20 Ship Rd',    'city' => 'Sparks']);

        // Insert the non-primary first, the primary second — proving the ORDER, not insert time.
        $link->insert(['org_id' => $orgId, 'address_id' => $shipping, 'label' => 'shipping', 'is_primary' => 0]);
        $link->insert(['org_id' => $orgId, 'address_id' => $billing,  'label' => 'billing',  'is_primary' => 1]);

        $links = $link->findByOrg($orgId);
        $this->assertCount(2, $links, 'findByOrg returns every link for the org');

        $rows = $link->withAddress($orgId);
        $this->assertCount(2, $rows);
        $this->assertSame('billing', $rows[0]['label'], 'the primary link sorts first');
        $this->assertSame(1, (int) $rows[0]['is_primary']);
        $this->assertSame('10 Billing St', $rows[0]['line1'], 'the join carries the underlying location');
        $this->assertArrayHasKey('link_id', $rows[0], 'the link PK is aliased to the generic link_id');
        $this->assertSame('20 Ship Rd', $rows[1]['line1']);
    }

    #[Test]
    public function a_soft_deleted_org_address_link_drops_out_of_the_read_shape(): void
    {
        $orgId = Tiger_Uuid::v7();
        $addr  = new Tiger_Model_Address();
        $link  = new Tiger_Model_OrgAddress();

        $a = $addr->create(['line1' => 'Live']);
        $b = $addr->create(['line1' => 'Gone']);
        $link->insert(['org_id' => $orgId, 'address_id' => $a, 'label' => 'home', 'is_primary' => 1]);
        $goneId = $link->insert(['org_id' => $orgId, 'address_id' => $b, 'label' => 'old', 'is_primary' => 0]);

        $link->softDelete($this->db->quoteInto('org_address_id = ?', $goneId));

        $rows = $link->withAddress($orgId);
        $this->assertCount(1, $rows, 'the soft-deleted link is filtered out');
        $this->assertSame('Live', $rows[0]['line1']);
    }

    #[Test]
    public function a_user_address_link_reads_back_joined_to_the_location(): void
    {
        $userId = Tiger_Uuid::v7();
        $addr   = new Tiger_Model_Address();
        $link   = new Tiger_Model_UserAddress();

        $home = $addr->create(['line1' => '5 Home Ln', 'city' => 'Truckee']);
        $link->insert(['user_id' => $userId, 'address_id' => $home, 'label' => 'home', 'is_primary' => 1]);

        $this->assertCount(1, $link->findByUser($userId));
        $rows = $link->withAddress($userId);
        $this->assertCount(1, $rows);
        $this->assertSame('5 Home Ln', $rows[0]['line1']);
        $this->assertSame('home', $rows[0]['label']);
        $this->assertArrayHasKey('link_id', $rows[0]);
    }

    #[Test]
    public function an_org_contact_link_reads_back_joined_to_the_channel(): void
    {
        $orgId   = Tiger_Uuid::v7();
        $contact = new Tiger_Model_Contact();
        $link    = new Tiger_Model_OrgContact();

        $phone = $contact->insert(['kind' => 'phone', 'type' => 'work', 'value' => '+17755551234']);
        $email = $contact->insert(['kind' => 'email', 'type' => 'billing', 'value' => 'ap@example.test']);

        $link->insert(['org_id' => $orgId, 'contact_id' => $phone, 'label' => 'main', 'is_primary' => 0]);
        $link->insert(['org_id' => $orgId, 'contact_id' => $email, 'label' => 'ap',   'is_primary' => 1]);

        $this->assertCount(2, $link->findByOrg($orgId));
        $rows = $link->withContact($orgId);
        $this->assertSame('ap', $rows[0]['label'], 'the primary contact sorts first');
        $this->assertSame('email', $rows[0]['kind']);
        $this->assertSame('ap@example.test', $rows[0]['value']);
        $this->assertArrayHasKey('link_id', $rows[0]);
    }

    #[Test]
    public function a_user_contact_link_reads_back_joined_to_the_channel(): void
    {
        $userId  = Tiger_Uuid::v7();
        $contact = new Tiger_Model_Contact();
        $link    = new Tiger_Model_UserContact();

        $cell = $contact->insert(['kind' => 'phone', 'type' => 'cell', 'value' => '+17755559999']);
        $link->insert(['user_id' => $userId, 'contact_id' => $cell, 'label' => 'cell', 'is_primary' => 1]);

        $this->assertCount(1, $link->findByUser($userId));
        $rows = $link->withContact($userId);
        $this->assertCount(1, $rows);
        $this->assertSame('phone', $rows[0]['kind']);
        $this->assertSame('+17755559999', $rows[0]['value']);
        $this->assertSame('cell', $rows[0]['label']);
    }
}
