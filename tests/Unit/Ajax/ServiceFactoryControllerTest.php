<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\Ajax {

    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\Attributes\Test;
    use Tiger\Tests\Support\UnitTestCase;
    use Tiger_Ajax_ServiceFactory;
    use Zend_Acl;
    use Zend_Acl_Resource;
    use Zend_Acl_Role;
    use Zend_Auth;
    use Zend_Auth_Storage_NonPersistent;
    use Zend_Config;
    use Zend_Controller_Request_Http;
    use Zend_Registry;

    /**
     * Tiger_Ajax_ServiceFactory — the branches the Wave-3 ServiceFactoryTest left: CONTROLLER mode (resolve
     * `{Module}_{Controller}Controller`, ACL-check, then hand ApiController a _forward descriptor), the
     * "neither service nor controller" shape guard, and the `tiger.api.default_module` config fallback that
     * lets a message omit the module. No DB / no network; a fixture controller stands in for a real one.
     */
    #[CoversClass(Tiger_Ajax_ServiceFactory::class)]
    final class ServiceFactoryControllerTest extends UnitTestCase
    {
        protected function setUp(): void
        {
            parent::setUp();
            Zend_Auth::getInstance()->setStorage(new Zend_Auth_Storage_NonPersistent());
        }

        protected function tearDown(): void
        {
            Zend_Auth::getInstance()->clearIdentity();
            parent::tearDown();
        }

        private function request(array $svc): Zend_Controller_Request_Http
        {
            $r = new Zend_Controller_Request_Http();
            foreach ($svc as $k => $v) { $r->setParam($k, $v); }
            return $r;
        }

        private function factory(array $svc): Tiger_Ajax_ServiceFactory
        {
            return new Tiger_Ajax_ServiceFactory($this->request($svc));
        }

        private function installAcl(string $resource, string $privilege, string $role = 'user'): void
        {
            $acl = new Zend_Acl();
            $acl->addRole(new Zend_Acl_Role('guest'));
            $acl->addRole(new Zend_Acl_Role('user'), 'guest');
            $acl->addResource(new Zend_Acl_Resource($resource));
            $acl->allow($role, $resource, $privilege);
            Zend_Registry::set('Zend_Acl', $acl);
        }

        private function asUser(string $role = 'user'): void
        {
            Zend_Auth::getInstance()->getStorage()->write((object) ['role' => $role, 'user_id' => 'u1']);
        }

        // ---- controller mode -----------------------------------------------

        #[Test]
        public function controller_mode_hands_back_a_forward_descriptor_for_an_allowed_call(): void
        {
            $this->installAcl('Ctlfix_ThingController', 'go');
            $this->asUser('user');

            $f = $this->factory(['svc_module' => 'ctlfix', 'svc_controller' => 'thing', 'svc_action' => 'go', 'extra' => 'v']);

            $fwd = $f->getForward();
            $this->assertIsArray($fwd, 'controller mode signals a _forward, not a service response');
            $this->assertSame('ctlfix', $fwd['module']);
            $this->assertSame('thing', $fwd['controller']);
            $this->assertSame('go', $fwd['action']);
            $this->assertSame('v', $fwd['params']['extra'], 'the whole message rides along to the action');
        }

        #[Test]
        public function controller_mode_denies_an_unauthorized_role_before_forwarding(): void
        {
            $this->installAcl('Ctlfix_ThingController', 'go');   // user may 'go'
            $this->asUser('user');

            $f = $this->factory(['svc_module' => 'ctlfix', 'svc_controller' => 'thing', 'svc_action' => 'nope']);
            $this->assertNull($f->getForward(), 'a denied controller call never forwards');
            $this->assertSame('core.api.error.not_allowed', $f->getResponse()->messages[0]->message);
        }

        #[Test]
        public function controller_mode_fails_generically_when_the_action_method_is_missing(): void
        {
            // Authorized, class exists, but there is no `missingAction` — resolution fails with the generic error.
            $this->installAcl('Ctlfix_ThingController', 'missing');
            $this->asUser('user');

            $f = $this->factory(['svc_module' => 'ctlfix', 'svc_controller' => 'thing', 'svc_action' => 'missing']);
            $this->assertNull($f->getForward());
            $this->assertSame('core.api.error.general', $f->getResponse()->messages[0]->message);
        }

        // ---- shape guards + config fallback --------------------------------

        #[Test]
        public function neither_service_nor_controller_is_a_missing_service_error(): void
        {
            $f = $this->factory(['svc_action' => 'go']);   // action only, no service, no controller
            $this->assertSame('core.api.error.missing_service', $f->getResponse()->messages[0]->message);
        }

        #[Test]
        public function the_default_module_config_lets_a_message_omit_the_module(): void
        {
            // With tiger.api.default_module set, an omitted module resolves to it — so we get past the
            // missing_module guard to (no ACL => fail open =>) the missing class → generic error. Without the
            // default, the same message would throw missing_module.
            $this->setConfig(['tiger' => ['api' => ['default_module' => 'account']]]);

            $f = $this->factory(['svc_service' => 'user', 'svc_action' => 'save']);   // no svc_module
            $this->assertSame('core.api.error.general', $f->getResponse()->messages[0]->message, 'the default module filled in');
        }

        #[Test]
        public function without_a_default_module_an_omitted_module_is_missing_module(): void
        {
            $f = $this->factory(['svc_service' => 'user', 'svc_action' => 'save']);   // no module, no default
            $this->assertSame('core.api.error.missing_module', $f->getResponse()->messages[0]->message);
        }
    }
}

namespace {

    /** A fixture controller for the factory's class_exists + method_exists(<action>Action) checks. */
    class Ctlfix_ThingController
    {
        public function goAction() {}
    }
}
