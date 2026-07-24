<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Signup;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Signup_IndexController;
use Tiger\Tests\Support\ModuleControllerTestCase;
use Tiger_Model_Option;
use Zend_Controller_Action_Exception;

/**
 * Signup_IndexController — the public signup screen + the email-verify link handler. init() switches to
 * the 'auth' layout; index renders the form (or 404s when public signup is disabled, or redirects an
 * already-authenticated user to /admin); verify consumes the emailed link and reports ok/failure. The
 * harness dispatches each action rendering-off and asserts the outcome.
 */
#[CoversClass(Signup_IndexController::class)]
final class IndexControllerTest extends ModuleControllerTestCase
{
    #[Test]
    public function index_renders_the_signup_form_for_a_guest(): void
    {
        $this->dispatchAction(Signup_IndexController::class, 'index', [], 'GET');

        $view = $this->controller()->view;
        $this->assertStringContainsString('Create your account', (string) $view->title);
        $this->assertInstanceOf(\Signup_Form_Signup::class, $view->form);
        $this->assertTrue((bool) $view->authWide);
        // init() switched to the public 'auth' layout.
        $this->assertSame('auth', $this->controller()->getHelper('layout')->getLayout());
    }

    #[Test]
    public function index_redirects_an_authenticated_user_to_admin(): void
    {
        $this->loginAs('user');
        $this->dispatchAction(Signup_IndexController::class, 'index', [], 'GET');

        $this->assertStringContainsString('/admin', $this->redirectLocation());
    }

    #[Test]
    public function index_404s_when_public_signup_is_disabled(): void
    {
        (new Tiger_Model_Option())->set(Tiger_Model_Option::SCOPE_GLOBAL, '', 'signup.public_disabled', '1');

        $this->expectException(Zend_Controller_Action_Exception::class);
        $this->dispatchAction(Signup_IndexController::class, 'index', [], 'GET');
    }

    #[Test]
    public function verify_reports_failure_for_a_bogus_token(): void
    {
        $this->dispatchAction(Signup_IndexController::class, 'verify', ['cid' => 'nope', 'code' => 'bad'], 'GET');

        $view = $this->controller()->view;
        $this->assertStringContainsString('verification', (string) $view->title);
        $this->assertFalse((bool) $view->ok);
    }
}
