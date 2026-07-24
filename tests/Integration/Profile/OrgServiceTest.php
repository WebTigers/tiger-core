<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Profile;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Profile_Service_Org;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_Org;
use Zend_Registry;

/**
 * Profile_Service_Org — the admin-gated org Basic tab (/api).
 *
 * Wave-4 coverage: the deny-by-default admin gate (guest + plain user refused, admin cleared), a
 * strictly-current-org save (never an org_id from the payload) that writes name + a slugified,
 * uniqueness-checked slug, the slug-required guard (blank name AND slug), and the slug-taken guard
 * (another org already owns the slug). The slug is derived by the service's own slugify rule.
 */
#[CoversClass(Profile_Service_Org::class)]
final class OrgServiceTest extends IntegrationTestCase
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

    private function call(string $action, array $params = []): object
    {
        return (new Profile_Service_Org(['action' => $action] + $params))->getResponse();
    }

    /** Seed an org and act as an admin signed into it. */
    private function loginIntoNewOrg(array $orgData = []): string
    {
        $orgId = (new Tiger_Model_Org())->insert(['name' => 'Seed Org', 'slug' => 'seed-org-' . bin2hex(random_bytes(3))] + $orgData);
        $this->login('admin-actor', $orgId, 'admin');
        return $orgId;
    }

    // ----- ACL gate -----------------------------------------------------------------------------

    #[Test]
    public function guest_is_denied(): void
    {
        $this->login('anon', 'org-test', 'guest');
        $res = $this->call('save', ['name' => 'X']);
        $this->assertSame(0, (int) $res->result);
        $this->assertStringContainsString('not_allowed', json_encode($res->messages));
    }

    #[Test]
    public function a_plain_user_is_denied(): void
    {
        $this->loginAs('user');
        $res = $this->call('save', ['name' => 'X']);
        $this->assertSame(0, (int) $res->result, 'the org profile is admin-only');
        $this->assertStringContainsString('not_allowed', json_encode($res->messages));
    }

    // ----- save ---------------------------------------------------------------------------------

    #[Test]
    public function admin_saves_name_and_derives_the_slug_from_the_name(): void
    {
        $orgId = $this->loginIntoNewOrg();

        $res = $this->call('save', ['name' => 'Acme Widgets, Inc.', 'slug' => '']);
        $this->assertSame(1, (int) $res->result, 'a valid admin save succeeds');
        $this->assertSame('acme-widgets-inc', $res->data['slug'], 'the slug is derived + slugified from the name');

        $row = (new Tiger_Model_Org())->findById($orgId);
        $this->assertSame('Acme Widgets, Inc.', $row->name);
        $this->assertSame('acme-widgets-inc', $row->slug);
    }

    #[Test]
    public function admin_saves_an_explicit_slug_slugified(): void
    {
        $orgId = $this->loginIntoNewOrg();
        $res = $this->call('save', ['name' => 'Whatever', 'slug' => 'My Custom Slug!']);
        $this->assertSame(1, (int) $res->result);
        $this->assertSame('my-custom-slug', $res->data['slug'], 'an explicit slug is slugified too');
    }

    #[Test]
    public function a_name_and_slug_that_slugify_to_nothing_is_refused(): void
    {
        $orgId = $this->loginIntoNewOrg();
        // name is required by the form, so give a symbol-only name that slugifies to '' with no slug.
        $res = $this->call('save', ['name' => '!!!', 'slug' => '']);
        $this->assertSame(0, (int) $res->result, 'an empty slug is refused');
        $this->assertStringContainsString('slug_required', json_encode($res->messages));
    }

    #[Test]
    public function a_slug_owned_by_another_org_is_refused(): void
    {
        (new Tiger_Model_Org())->insert(['name' => 'Rival', 'slug' => 'contested']);
        $orgId = $this->loginIntoNewOrg();

        $res = $this->call('save', ['name' => 'Contested', 'slug' => 'contested']);
        $this->assertSame(0, (int) $res->result, 'a slug taken by another org is refused');
        $this->assertStringContainsString('slug_taken', json_encode($res->messages));
    }

    #[Test]
    public function an_org_may_keep_its_own_slug_on_re_save(): void
    {
        $orgId = (new Tiger_Model_Org())->insert(['name' => 'Keeper', 'slug' => 'keeper-co']);
        $this->login('admin-actor', $orgId, 'admin');

        // Re-saving the same slug excludes self from the uniqueness check → allowed.
        $res = $this->call('save', ['name' => 'Keeper Renamed', 'slug' => 'keeper-co']);
        $this->assertSame(1, (int) $res->result, 'an org keeps its own slug');
        $row = (new Tiger_Model_Org())->findById($orgId);
        $this->assertSame('Keeper Renamed', $row->name);
        $this->assertSame('keeper-co', $row->slug);
    }

    #[Test]
    public function an_empty_name_returns_form_errors(): void
    {
        $this->loginIntoNewOrg();
        $res = $this->call('save', ['name' => '']);   // name is required
        $this->assertSame(0, (int) $res->result);
        $this->assertNotNull($res->form);
        $this->assertArrayHasKey('name', $res->form);
    }
}
