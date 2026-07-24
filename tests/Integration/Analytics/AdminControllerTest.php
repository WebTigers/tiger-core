<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Analytics;

use Analytics_AdminController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\ModuleControllerTestCase;

/**
 * Analytics_AdminController — the Google Analytics settings + OAuth-connect screens. index prefills the
 * settings form + the connection state; connect/callback/disconnect drive the OAuth handshake (broker or
 * BYO) and redirect back to /analytics/admin; dashboard renders the reports shell. With nothing
 * configured the connection is unconfigurable/unconnected, so the guard branches (redirect with a flash)
 * are what run under the harness.
 */
#[CoversClass(Analytics_AdminController::class)]
final class AdminControllerTest extends ModuleControllerTestCase
{
    #[Test]
    public function index_prefills_the_settings_form_and_connection_state(): void
    {
        $this->loginAs('admin');
        $this->dispatchAction(Analytics_AdminController::class, 'index', [], 'GET');

        $view = $this->controller()->view;
        $this->assertStringContainsString('Analytics', (string) $view->title);
        $this->assertInstanceOf(\Analytics_Form_Settings::class, $view->form);
        $this->assertFalse($view->enabled);
        $this->assertIsArray($view->ga);
        $this->assertFalse((bool) $view->ga['connected']);
        $this->assertFalse((bool) $view->ga['configurable']);
        $this->assertStringContainsString('/analytics/admin/callback', (string) $view->ga['redirect_uri']);
    }

    #[Test]
    public function connect_redirects_back_with_an_error_when_not_configurable(): void
    {
        // No GA4 property id set → not configurable → flash + redirect to the settings screen.
        $this->loginAs('admin');
        $this->dispatchAction(Analytics_AdminController::class, 'connect', [], 'GET');

        $this->assertStringContainsString('/analytics/admin', $this->redirectLocation());
    }

    #[Test]
    public function callback_broker_mode_flashes_and_redirects_on_a_denied_grant(): void
    {
        // Broker mode is the default; an ?error param means the user denied consent → flash + redirect.
        $this->loginAs('admin');
        $this->dispatchAction(Analytics_AdminController::class, 'callback', ['error' => 'access_denied'], 'GET');

        $this->assertStringContainsString('/analytics/admin', $this->redirectLocation());
    }

    #[Test]
    public function disconnect_forgets_the_connection_and_redirects(): void
    {
        $this->loginAs('admin');
        $this->dispatchAction(Analytics_AdminController::class, 'disconnect', [], 'GET');

        $this->assertStringContainsString('/analytics/admin', $this->redirectLocation());
    }

    #[Test]
    public function dashboard_renders_the_reports_shell(): void
    {
        $this->loginAs('admin');
        $this->dispatchAction(Analytics_AdminController::class, 'dashboard', [], 'GET');

        $view = $this->controller()->view;
        $this->assertStringContainsString('Dashboard', (string) $view->title);
        $this->assertFalse((bool) $view->connected);
    }
}
