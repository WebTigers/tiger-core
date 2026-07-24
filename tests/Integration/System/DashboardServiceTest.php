<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\System;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use System_Service_Dashboard;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Acl_Acl;
use Tiger_Dashboard;
use Tiger_Model_Option;
use Zend_Auth;
use Zend_Auth_Storage_NonPersistent;
use Zend_Registry;

// `System_Service_Dashboard` resolves via the harness module autoloader (tests/bootstrap.php).

/** A trivial dashboard widget so widgetBody() has a real class with render() to instantiate. */
final class FakeDashboardWidget
{
    public function render(): string { return '<div class="fake">hello</div>'; }
}

/**
 * System_Service_Dashboard — persists a user's dashboard widget layout + visibility to the LAZY
 * option tier (scope=user), and renders a single allowed widget's body on demand. Admin+, and
 * fail-soft (an unparseable/oversized payload is an ignored success — layout is convenience state).
 *
 * These tests characterize the ACL gate, the hygiene filter (only KNOWN widget ids are stored,
 * unknowns dropped), the JSON round-trip into the `option` table, every fail-soft branch, and
 * widgetBody's ACL-scoped render (an allowed widget renders; an unknown id is refused).
 */
#[CoversClass(System_Service_Dashboard::class)]
final class DashboardServiceTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tiger_Dashboard::clear();
        // One known widget (no ACL resource → visible to any admin) the tests order/hide/render.
        Tiger_Dashboard::registerWidget([
            'id'     => 'test.widget',
            'module' => 'test',
            'title'  => 'Test Widget',
            'widget' => FakeDashboardWidget::class,
        ]);
    }

    protected function tearDown(): void
    {
        Tiger_Dashboard::clear();
        parent::tearDown();
    }

    private function dispatch(array $msg): object
    {
        return (new System_Service_Dashboard($msg))->getResponse();
    }

    private function messages(object $res): string
    {
        return json_encode($res->messages ?? []);
    }

    // ---- ACL -------------------------------------------------------------------------------------

    #[Test]
    public function a_guest_is_denied_every_dashboard_verb(): void
    {
        $this->login('anon', 'o-1', 'guest');
        foreach (['saveLayout', 'saveWidgetPrefs', 'widgetBody'] as $action) {
            $res = $this->dispatch(['action' => $action]);
            $this->assertSame(0, (int) $res->result, "$action denied to a guest");
            $this->assertStringContainsString('not_allowed', $this->messages($res));
        }
    }

    #[Test]
    public function an_admin_identity_with_no_user_id_cannot_save_a_layout(): void
    {
        // An authenticated admin with no user_id clears the ACL gate but has no user to scope state to →
        // the empty-uid guard refuses (there's no per-user option owner).
        $auth = Zend_Auth::getInstance();
        $auth->setStorage(new Zend_Auth_Storage_NonPersistent());
        $auth->getStorage()->write((object) ['user_id' => '', 'org_id' => 'org-test', 'role' => 'admin']);
        Zend_Registry::set('Zend_Acl', new Tiger_Acl_Acl());

        $res = $this->dispatch(['action' => 'saveLayout', 'layout' => '{"order":["test.widget"]}']);
        $this->assertSame(0, (int) $res->result);
        $this->assertStringContainsString('not_allowed', $this->messages($res));
    }

    // ---- saveLayout ------------------------------------------------------------------------------

    #[Test]
    public function save_layout_stores_only_known_widget_ids_in_order_and_collapsed_map(): void
    {
        $this->login('u-dash', 'org-test', 'admin');
        $layout = json_encode([
            'order'     => ['test.widget', 'ghost.widget', 'test.widget'],   // unknown + duplicate dropped
            'collapsed' => ['test.widget' => true, 'ghost.widget' => true, 'other' => false],
        ]);
        $res = $this->dispatch(['action' => 'saveLayout', 'layout' => $layout]);
        $this->assertSame(1, (int) $res->result, $this->messages($res));

        $stored = (new Tiger_Model_Option())->getJson(Tiger_Model_Option::SCOPE_USER, 'u-dash', 'tiger.dashboard.layout');
        $this->assertSame(['test.widget'], $stored['order'], 'unknown + duplicate ids filtered out');
        $this->assertSame(['test.widget' => true], $stored['collapsed'], 'only known + truthy collapsed kept');
    }

    #[Test]
    public function save_layout_is_fail_soft_for_empty_oversized_or_non_array_payloads(): void
    {
        $this->login('u-dash', 'org-test', 'admin');

        foreach (['', str_repeat('x', 20001), '"a string"', '12345'] as $bad) {
            $res = $this->dispatch(['action' => 'saveLayout', 'layout' => $bad]);
            $this->assertSame(1, (int) $res->result, 'fail-soft success for a junk payload');
        }
        // Nothing was persisted from any of those.
        $this->assertNull((new Tiger_Model_Option())->getJson(Tiger_Model_Option::SCOPE_USER, 'u-dash', 'tiger.dashboard.layout'));
    }

    // ---- saveWidgetPrefs -------------------------------------------------------------------------

    #[Test]
    public function save_widget_prefs_stores_only_known_hidden_ids(): void
    {
        $this->login('u-dash', 'org-test', 'admin');
        $res = $this->dispatch(['action' => 'saveWidgetPrefs', 'hidden' => json_encode(['test.widget', 'ghost.widget', 'test.widget'])]);
        $this->assertSame(1, (int) $res->result, $this->messages($res));

        $stored = (new Tiger_Model_Option())->getJson(Tiger_Model_Option::SCOPE_USER, 'u-dash', 'tiger.dashboard.prefs');
        $this->assertSame(['hidden' => ['test.widget']], $stored, 'unknown + duplicate hidden ids filtered');
    }

    #[Test]
    public function save_widget_prefs_treats_an_empty_list_as_show_all(): void
    {
        $this->login('u-dash', 'org-test', 'admin');
        $res = $this->dispatch(['action' => 'saveWidgetPrefs', 'hidden' => '']);
        $this->assertSame(1, (int) $res->result);

        $stored = (new Tiger_Model_Option())->getJson(Tiger_Model_Option::SCOPE_USER, 'u-dash', 'tiger.dashboard.prefs');
        $this->assertSame(['hidden' => []], $stored, 'an empty list persists as "show all"');
    }

    // ---- widgetBody ------------------------------------------------------------------------------

    #[Test]
    public function widget_body_renders_an_allowed_widget(): void
    {
        $this->login('u-dash', 'org-test', 'admin');
        $res = $this->dispatch(['action' => 'widgetBody', 'id' => 'test.widget']);

        $this->assertSame(1, (int) $res->result, $this->messages($res));
        $this->assertStringContainsString('hello', $res->data['html'], 'the widget class render() output is returned');
    }

    #[Test]
    public function widget_body_refuses_an_unknown_widget_id(): void
    {
        $this->login('u-dash', 'org-test', 'admin');
        $res = $this->dispatch(['action' => 'widgetBody', 'id' => 'ghost.widget']);

        $this->assertSame(0, (int) $res->result);
        $this->assertStringContainsString('not_allowed', $this->messages($res));
    }
}
