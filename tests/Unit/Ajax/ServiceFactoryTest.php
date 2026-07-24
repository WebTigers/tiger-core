<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\Ajax;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Ajax_ServiceFactory;
use Zend_Acl;
use Zend_Acl_Resource;
use Zend_Acl_Role;
use Zend_Auth;
use Zend_Auth_Storage_NonPersistent;
use Zend_Controller_Request_Http;
use Zend_Registry;

/**
 * Tiger_Ajax_ServiceFactory — the /api gateway. The sharp end of the platform: it turns message text
 * into class names and method calls, so its guards are load-bearing. Covered here: input sanitization
 * (routing segments → class names), deny-by-default authorization ordering (authorize BEFORE the class
 * is touched), and the guest/role/allow decision envelopes.
 *
 * NOTE (by design): there is **no reserved-module pre-gate** — it was intentionally removed as an
 * unnecessary early gate. The ACL is the single gate: deny-by-default authorizes every call before a
 * class is touched, so a core `module=tiger` service is reachable over /api gated purely by ACL, and a
 * service with no allow rule is refused regardless. `isReserved()`/`reserve()` remain as a public
 * helper and are covered for their list behavior; they do not gate dispatch.
 */
#[CoversClass(Tiger_Ajax_ServiceFactory::class)]
final class ServiceFactoryTest extends UnitTestCase
{
    /** @var string[] snapshot of the reserved list to restore after tests that mutate it */
    private array $reservedSnapshot = [];

    protected function setUp(): void
    {
        parent::setUp();
        // No sessions in unit tests: request-only auth storage. Empty == guest.
        Zend_Auth::getInstance()->setStorage(new Zend_Auth_Storage_NonPersistent());

        $ref = new ReflectionClass(Tiger_Ajax_ServiceFactory::class);
        $prop = $ref->getProperty('_reserved');
        $this->reservedSnapshot = $prop->getValue();
    }

    protected function tearDown(): void
    {
        // restore the static reserved list so reserve() tests don't leak
        $ref = new ReflectionClass(Tiger_Ajax_ServiceFactory::class);
        $prop = $ref->getProperty('_reserved');
        $prop->setValue(null, $this->reservedSnapshot);

        Zend_Auth::getInstance()->clearIdentity();
        parent::tearDown();
    }

    // ---- helpers ------------------------------------------------------------

    private function request(array $svc): Zend_Controller_Request_Http
    {
        $r = new Zend_Controller_Request_Http();
        foreach ($svc as $k => $v) {
            $r->setParam($k, $v);
        }
        return $r;
    }

    /** An in-memory ACL: guest + user roles, one resource, allow `user` the given privilege. */
    private function installAcl(string $resource = 'Account_Service_User', string $privilege = 'save'): void
    {
        $acl = new Zend_Acl();
        $acl->addRole(new Zend_Acl_Role('guest'));
        $acl->addRole(new Zend_Acl_Role('user'), 'guest');
        $acl->addResource(new Zend_Acl_Resource($resource));
        $acl->allow('user', $resource, $privilege);
        Zend_Registry::set('Zend_Acl', $acl);
    }

    private function asUser(string $role = 'user'): void
    {
        Zend_Auth::getInstance()->getStorage()->write((object) ['role' => $role, 'user_id' => 'u1']);
    }

    private function dispatch(array $svc): object
    {
        return (new Tiger_Ajax_ServiceFactory($this->request($svc)))->getResponse();
    }

    private function firstMessage(object $response): string
    {
        return $response->messages[0]->message ?? '';
    }

    // ---- reserved list ------------------------------------------------------

    #[Test]
    public function reserved_modules_are_reported(): void
    {
        foreach (['tiger', 'zend', 'core', 'default', 'library', 'application'] as $name) {
            $this->assertTrue(Tiger_Ajax_ServiceFactory::isReserved($name), "$name must be reserved");
            $this->assertTrue(Tiger_Ajax_ServiceFactory::isReserved(strtoupper($name)), 'case-insensitive');
        }
        $this->assertFalse(Tiger_Ajax_ServiceFactory::isReserved('account'));
    }

    #[Test]
    public function reserve_adds_a_name(): void
    {
        $this->assertFalse(Tiger_Ajax_ServiceFactory::isReserved('shop'));
        Tiger_Ajax_ServiceFactory::reserve('shop');
        $this->assertTrue(Tiger_Ajax_ServiceFactory::isReserved('shop'));
    }

    #[Test]
    public function a_core_module_is_gated_by_acl_not_a_reserved_pregate(): void
    {
        // BY DESIGN: there is no reserved pre-gate. A `module=tiger` request is NOT short-circuited;
        // it flows to the same ACL/resolution path as any module (here, no ACL => authorize fails
        // open, then the bogus class fails resolution — a reserved refusal would look different).
        $r = $this->dispatch(['svc_module' => 'tiger', 'svc_service' => 'nope', 'svc_action' => 'go']);
        $this->assertSame(0, $r->result);
        $this->assertSame('core.api.error.general', $this->firstMessage($r), 'not a reserved-module refusal');
    }

    // ---- authorization ------------------------------------------------------

    #[Test]
    public function a_guest_is_denied_with_login_required(): void
    {
        $this->installAcl();
        $r = $this->dispatch(['svc_module' => 'account', 'svc_service' => 'user', 'svc_action' => 'save']);

        $this->assertSame(0, $r->result);
        $this->assertSame('core.api.error.login_required', $this->firstMessage($r));
        $this->assertSame(1, $r->data->login ?? null, 'guest denial signals the client to log in');
    }

    #[Test]
    public function an_authenticated_role_without_a_rule_is_not_allowed(): void
    {
        $this->installAcl(privilege: 'save');   // user may 'save', nothing else
        $this->asUser('user');
        $r = $this->dispatch(['svc_module' => 'account', 'svc_service' => 'user', 'svc_action' => 'destroy']);

        $this->assertSame(0, $r->result);
        $this->assertSame('core.api.error.not_allowed', $this->firstMessage($r));
    }

    #[Test]
    public function an_allowed_role_passes_authorization_then_fails_only_on_the_missing_class(): void
    {
        // Proves authorize() ran and PASSED (a denied call would be not_allowed/login_required).
        // The class doesn't exist in the test image, so resolution fails with the generic error.
        $this->installAcl();
        $this->asUser('user');
        $r = $this->dispatch(['svc_module' => 'account', 'svc_service' => 'user', 'svc_action' => 'save']);

        $this->assertSame(0, $r->result);
        $this->assertSame('core.api.error.general', $this->firstMessage($r));
    }

    #[Test]
    public function no_acl_registered_fails_open(): void
    {
        // With no ACL, authorize() fails OPEN — so the request proceeds and only the missing class
        // stops it (generic error), distinct from the deny path's login_required.
        $r = $this->dispatch(['svc_module' => 'account', 'svc_service' => 'user', 'svc_action' => 'save']);
        $this->assertSame('core.api.error.general', $this->firstMessage($r));
    }

    // ---- sanitization -------------------------------------------------------

    #[Test]
    public function routing_segments_are_sanitized_before_becoming_a_class_name(): void
    {
        // The allow rule is on the CLEAN resource `Account_Service_User`. We send dirty segments with
        // dots/slashes/spaces; if sanitization works they collapse to the clean class → authorize
        // passes → generic (class-missing). If it DIDN'T, the resource would be a dirty string the ACL
        // never allows → not_allowed. So `general` here is proof the injection chars were stripped.
        $this->installAcl('Account_Service_User', 'save');
        $this->asUser('user');
        $r = $this->dispatch(['svc_module' => 'ac/co.unt', 'svc_service' => 'us..er', 'svc_action' => 'sa ve']);

        $this->assertSame('core.api.error.general', $this->firstMessage($r), 'dirty input reached the clean resource');
    }

    // ---- shape guards -------------------------------------------------------

    #[Test]
    public function a_missing_action_is_rejected(): void
    {
        $r = $this->dispatch(['svc_module' => 'account', 'svc_service' => 'user', 'svc_action' => '']);
        $this->assertSame('core.api.error.missing_action', $this->firstMessage($r));
    }

    #[Test]
    public function a_named_service_without_a_module_is_rejected(): void
    {
        $r = $this->dispatch(['svc_module' => '', 'svc_service' => 'user', 'svc_action' => 'save']);
        $this->assertSame('core.api.error.missing_module', $this->firstMessage($r));
    }
}
