<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\Google;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use ReflectionProperty;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Crypto;
use Tiger_Google_Analytics;

/**
 * Tiger_Google_Analytics — the dependency-free GA4 reporting client (broker + BYO OAuth).
 *
 * The network-free surface is broad and it's what's tested here: connection-state logic (isConnected /
 * isConfigurable across broker vs BYO, driven by the encrypted refresh-token + property id + client
 * creds), config normalization (property id digit-strip, broker base URL, mode), the deterministic PKCE
 * S256 challenge, OAuth-consent URL construction (broker + BYO), and the GA4 response shapers
 * (_rowMetrics / _series with its YYYYMMDD→YYYY-MM-DD reformat / _dimRows) fed canned report JSON. The
 * memoized per-request access token is reset per test. Every live hop — accessToken() with a real
 * refresh token, runReport / summary / testConnection past the guards, exchangeCode/exchangeHandoff —
 * goes over HTTPS to Google/the broker and is the HTTP boundary (see WAVE5-FINDINGS-geo.md).
 */
#[CoversClass(Tiger_Google_Analytics::class)]
final class AnalyticsTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetAccess();
    }

    protected function tearDown(): void
    {
        $this->resetAccess();
        parent::tearDown();
    }

    /** Clear the memoized per-request access token so tests don't bleed into each other. */
    private function resetAccess(): void
    {
        (new ReflectionProperty(Tiger_Google_Analytics::class, '_access'))->setValue(null, null);
    }

    /** Seed a crypto key, then return an encrypted blob for a value (key must be registered first). */
    private function seedCryptoAndEncrypt(string $plain): array
    {
        $key = base64_encode(str_repeat("\x11", 32));
        $this->setConfig(['tiger' => ['crypto' => ['key' => $key]]]);
        return ['key' => $key, 'enc' => Tiger_Crypto::encrypt($plain)];
    }

    // -- config normalization ----------------------------------------------------------------------

    #[Test]
    public function mode_defaults_to_broker_and_honors_byo(): void
    {
        $this->setConfig(['tiger' => []]);
        $this->assertSame('broker', Tiger_Google_Analytics::mode());

        $this->setConfig(['tiger' => ['analytics' => ['oauth' => ['mode' => 'byo']]]]);
        $this->assertSame('byo', Tiger_Google_Analytics::mode());

        $this->setConfig(['tiger' => ['analytics' => ['oauth' => ['mode' => 'nonsense']]]]);
        $this->assertSame('broker', Tiger_Google_Analytics::mode(), 'an unknown mode falls back to broker');
    }

    #[Test]
    public function broker_base_defaults_and_strips_a_trailing_slash(): void
    {
        $this->setConfig(['tiger' => []]);
        $this->assertSame('https://connect.webtigers.com', Tiger_Google_Analytics::brokerBase());

        $this->setConfig(['tiger' => ['analytics' => ['connect' => ['base_url' => 'https://broker.example/']]]]);
        $this->assertSame('https://broker.example', Tiger_Google_Analytics::brokerBase());
    }

    #[Test]
    public function property_id_keeps_only_digits(): void
    {
        $this->setConfig(['tiger' => ['analytics' => ['property_id' => 'GA-123 456_789']]]);
        $this->assertSame('123456789', Tiger_Google_Analytics::propertyId());
    }

    #[Test]
    public function client_id_is_trimmed(): void
    {
        $this->setConfig(['tiger' => ['analytics' => ['oauth' => ['client_id' => '  cid.apps  ']]]]);
        $this->assertSame('cid.apps', Tiger_Google_Analytics::clientId());
    }

    // -- connection state --------------------------------------------------------------------------

    #[Test]
    public function it_is_not_connected_without_a_property_id(): void
    {
        $this->setConfig(['tiger' => ['analytics' => []]]);
        $this->assertFalse(Tiger_Google_Analytics::isConnected());
        $this->assertFalse(Tiger_Google_Analytics::isConfigurable());
    }

    #[Test]
    public function broker_mode_is_connected_with_a_property_and_a_refresh_token(): void
    {
        $c = $this->seedCryptoAndEncrypt('refresh-abc');
        $this->setConfig(['tiger' => [
            'crypto'    => ['key' => $c['key']],
            'analytics' => [
                'property_id' => '123456789',
                'oauth'       => ['refresh_token_enc' => $c['enc']],   // mode defaults to broker
            ],
        ]]);

        $this->assertTrue(Tiger_Google_Analytics::isConnected());
        $this->assertTrue(Tiger_Google_Analytics::isConfigurable(), 'broker only needs the property id to be configurable');
    }

    #[Test]
    public function broker_mode_needs_a_refresh_token_to_be_connected(): void
    {
        $this->setConfig(['tiger' => ['analytics' => ['property_id' => '123456789']]]);
        $this->assertFalse(Tiger_Google_Analytics::isConnected(), 'property but no refresh token');
        $this->assertTrue(Tiger_Google_Analytics::isConfigurable(), 'still configurable in broker mode');
    }

    #[Test]
    public function byo_mode_requires_property_refresh_and_client_creds(): void
    {
        // one crypto key encrypts both secrets
        $key = base64_encode(str_repeat("\x11", 32));
        $this->setConfig(['tiger' => ['crypto' => ['key' => $key]]]);
        $refreshEnc = Tiger_Crypto::encrypt('refresh-xyz');
        $secretEnc  = Tiger_Crypto::encrypt('client-secret');
        $this->setConfig(['tiger' => [
            'crypto'    => ['key' => $key],
            'analytics' => [
                'property_id' => '55',
                'oauth'       => [
                    'mode'               => 'byo',
                    'client_id'          => 'cid',
                    'client_secret_enc'  => $secretEnc,
                    'refresh_token_enc'  => $refreshEnc,
                ],
            ],
        ]]);

        $this->assertTrue(Tiger_Google_Analytics::isConnected());
        $this->assertTrue(Tiger_Google_Analytics::isConfigurable());
    }

    #[Test]
    public function byo_mode_is_not_configurable_without_client_creds(): void
    {
        $this->setConfig(['tiger' => ['analytics' => [
            'property_id' => '55',
            'oauth'       => ['mode' => 'byo'],   // no client id/secret
        ]]]);
        $this->assertFalse(Tiger_Google_Analytics::isConfigurable());
        $this->assertFalse(Tiger_Google_Analytics::isConnected());
    }

    // -- PKCE + OAuth URL construction -------------------------------------------------------------

    #[Test]
    public function pkce_challenge_is_the_unpadded_base64url_sha256_of_the_verifier(): void
    {
        $this->setConfig(['tiger' => []]);
        $verifier = 'a-random-verifier-string';
        $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $challenge = Tiger_Google_Analytics::pkceChallenge($verifier);
        $this->assertSame($expected, $challenge);
        $this->assertStringNotContainsString('=', $challenge, 'unpadded');
        $this->assertDoesNotMatchRegularExpression('#[+/]#', $challenge, 'url-safe alphabet');
    }

    #[Test]
    public function broker_auth_url_carries_the_callback_and_challenge(): void
    {
        $this->setConfig(['tiger' => ['analytics' => ['connect' => ['base_url' => 'https://broker.example']]]]);
        $url = Tiger_Google_Analytics::brokerAuthUrl('https://app/callback', 'CHAL');

        $this->assertStringStartsWith('https://broker.example/google/start?', $url);
        $this->assertStringContainsString('callback=' . rawurlencode('https://app/callback'), $url);
        $this->assertStringContainsString('challenge=CHAL', $url);
    }

    #[Test]
    public function byo_auth_url_requests_offline_access_and_forces_consent(): void
    {
        $this->setConfig(['tiger' => ['analytics' => ['oauth' => ['client_id' => 'cid.apps']]]]);
        $url = Tiger_Google_Analytics::authUrl('https://app/oauth/cb', 'STATE-1');

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        $this->assertStringContainsString('client_id=cid.apps', $url);
        $this->assertStringContainsString('redirect_uri=' . rawurlencode('https://app/oauth/cb'), $url);
        $this->assertStringContainsString('access_type=offline', $url);
        $this->assertStringContainsString('prompt=consent', $url);
        $this->assertStringContainsString('scope=' . rawurlencode('https://www.googleapis.com/auth/analytics.readonly'), $url);
        $this->assertStringContainsString('state=STATE-1', $url);
    }

    // -- guards that return before any network -----------------------------------------------------

    #[Test]
    public function access_token_is_empty_without_a_stored_refresh_token(): void
    {
        $this->setConfig(['tiger' => ['analytics' => []]]);
        $this->assertSame('', Tiger_Google_Analytics::accessToken(), 'no refresh token => no network, empty token');
    }

    #[Test]
    public function access_token_returns_the_memoized_value_without_recomputing(): void
    {
        // once minted for the request, the access token is memoized and returned verbatim (no refresh,
        // no network) — even with no config present.
        (new ReflectionProperty(Tiger_Google_Analytics::class, '_access'))->setValue(null, 'cached-token');
        $this->setConfig(['tiger' => []]);
        $this->assertSame('cached-token', Tiger_Google_Analytics::accessToken());
    }

    #[Test]
    public function summary_is_null_when_not_connected(): void
    {
        $this->setConfig(['tiger' => ['analytics' => []]]);
        $this->assertNull(Tiger_Google_Analytics::summary());
    }

    #[Test]
    public function test_connection_reports_not_connected_before_calling_out(): void
    {
        $this->setConfig(['tiger' => ['analytics' => []]]);
        $r = Tiger_Google_Analytics::testConnection();
        $this->assertFalse($r['ok']);
        $this->assertSame('not_connected', $r['code']);
    }

    // -- response shapers (canned GA4 JSON) --------------------------------------------------------

    #[Test]
    public function row_metrics_reads_the_first_row_metric_values_as_floats(): void
    {
        $canned = ['rows' => [['metricValues' => [['value' => '1234'], ['value' => '56'], ['value' => '78.0']]]]];
        $out    = (new ReflectionMethod(Tiger_Google_Analytics::class, '_rowMetrics'))->invoke(null, $canned);
        $this->assertSame([1234.0, 56.0, 78.0], $out);
    }

    #[Test]
    public function row_metrics_is_empty_for_a_report_with_no_rows(): void
    {
        $out = (new ReflectionMethod(Tiger_Google_Analytics::class, '_rowMetrics'))->invoke(null, ['rows' => []]);
        $this->assertSame([], $out);
    }

    #[Test]
    public function series_reformats_the_compact_date_and_casts_metrics(): void
    {
        $canned = ['rows' => [
            ['dimensionValues' => [['value' => '20260724']], 'metricValues' => [['value' => '10'], ['value' => '25']]],
            ['dimensionValues' => [['value' => 'partial']], 'metricValues' => [['value' => '3'], ['value' => '4']]],
        ]];
        $out = (new ReflectionMethod(Tiger_Google_Analytics::class, '_series'))->invoke(null, $canned);

        $this->assertSame('2026-07-24', $out[0]['date'], 'YYYYMMDD => YYYY-MM-DD');
        $this->assertSame(10, $out[0]['users']);
        $this->assertSame(25, $out[0]['views']);
        $this->assertSame('partial', $out[1]['date'], 'a non-8-char value passes through unchanged');
    }

    #[Test]
    public function series_is_empty_for_null_or_rowless_input(): void
    {
        $m = new ReflectionMethod(Tiger_Google_Analytics::class, '_series');
        $this->assertSame([], $m->invoke(null, null));
        $this->assertSame([], $m->invoke(null, ['rows' => []]));
    }

    #[Test]
    public function dim_rows_maps_label_and_integer_value(): void
    {
        $canned = ['rows' => [
            ['dimensionValues' => [['value' => '/home']], 'metricValues' => [['value' => '900']]],
            ['dimensionValues' => [['value' => '/pricing']], 'metricValues' => [['value' => '120']]],
        ]];
        $out = (new ReflectionMethod(Tiger_Google_Analytics::class, '_dimRows'))->invoke(null, $canned);

        $this->assertSame([['label' => '/home', 'value' => 900], ['label' => '/pricing', 'value' => 120]], $out);
    }

    #[Test]
    public function dim_rows_is_empty_for_null_input(): void
    {
        $this->assertSame([], (new ReflectionMethod(Tiger_Google_Analytics::class, '_dimRows'))->invoke(null, null));
    }
}
