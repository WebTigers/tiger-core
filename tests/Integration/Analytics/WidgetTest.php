<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Analytics;

use Analytics_Widget_Ga;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Crypto;
use Zend_Config;
use Zend_Registry;

/**
 * Analytics_Widget_Ga — the admin-dashboard "Traffic" widget. Wave-4 coverage of the reachable branch:
 * with Google Analytics not connected, `render()` returns the "Connect Google Analytics" prompt card
 * (a set-up CTA to /analytics/admin) rather than the live sparkline shell.
 *
 * The connected branch renders a chart shell whose data arrives over /api and is drawn by Chart.js in
 * the browser; standing up a live GA connection (a signed refresh token + a Google round-trip) is out
 * of scope for a hermetic test — see WAVE4-FINDINGS-mediaan.md.
 */
#[CoversClass(Analytics_Widget_Ga::class)]
final class WidgetTest extends IntegrationTestCase
{
    #[Test]
    public function render_shows_the_connect_prompt_when_not_connected(): void
    {
        // No GA config → Tiger_Google_Analytics::isConnected() is false → the prompt branch.
        $html = (new Analytics_Widget_Ga())->render();

        $this->assertStringContainsString('Connect Google Analytics', $html, 'the set-up prompt is shown');
        $this->assertStringContainsString('/analytics/admin', $html, 'links to the settings screen');
        $this->assertStringContainsString('btn', $html, 'renders a CTA button');
        $this->assertStringNotContainsString('<canvas', $html, 'no live chart shell when disconnected');
    }

    #[Test]
    public function render_shows_the_live_chart_shell_when_connected(): void
    {
        $this->connectGa();

        $html = (new Analytics_Widget_Ga())->render();

        // Connected → the sparkline shell (its data arrives client-side over /api), not the prompt.
        $this->assertStringContainsString('<canvas', $html, 'the chart canvas shell is rendered');
        $this->assertStringContainsString('active users', $html);
        $this->assertStringContainsString('/analytics/admin/dashboard', $html, 'links to the full dashboard');
        $this->assertStringNotContainsString('Connect Google Analytics', $html, 'no set-up prompt when connected');
    }

    /**
     * Make Tiger_Google_Analytics::isConnected() true: a crypto key + a property id + a decryptable
     * refresh token (broker mode needs only those two). All read from the eager Zend_Config tier.
     */
    private function connectGa(): void
    {
        $key = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';   // 32 zero bytes, base64 (as CryptoTest)
        Zend_Registry::set('Zend_Config', new Zend_Config(['tiger' => ['crypto' => ['key' => $key]]], true));
        $enc = Tiger_Crypto::encrypt('fake-refresh-token');

        Zend_Registry::set('Zend_Config', new Zend_Config(['tiger' => [
            'crypto'    => ['key' => $key],
            'analytics' => [
                'property_id' => '123456789',
                'oauth'       => ['mode' => 'broker', 'refresh_token_enc' => $enc],
            ],
        ]], true));
    }
}
