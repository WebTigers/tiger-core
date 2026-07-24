<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_License_Checker — the client half of licensing: hold an install's keys, verify them against
 * whichever authority a licensed module declares, and gate auto-update. VENDOR-NEUTRAL — a licensed
 * module names its own authority (module.json `pricing.authority`); this checker phones it, verifies the
 * signed reply, caches it, and never cares who the vendor is. Any publisher can sell licensed modules on
 * this built-in checker against their own authority.
 *
 * Two rules make it safe rather than hostile:
 *   1. NAG, NEVER DISABLE. Only a *definitive, reached-home* "lapsed" verdict withholds an UPDATE. The
 *      installed module keeps running forever regardless. `unknown` (couldn't reach home, or an untrusted
 *      reply) is NOT `lapsed` — it assumes-current and stays quiet, so our first outage can't nag every
 *      install. This is INTEGRITY-gated licensing, not DRM: it's source-available and patchable.
 *   2. TRUST THE SIGNATURE, CACHE THE VERDICT. The authority signs its short-TTL reply; the checker
 *      verifies it against the pinned public key and serves the cache until it expires, so a brief
 *      authority outage rides through on the last good answer.
 *
 * Persistence goes through Tiger_License_Store (default: the `option` tier); the transport + store are
 * injectable seams so the whole decision logic is testable with no DB and no network.
 *
 * @api
 * @see Tiger_License_Store
 * @see Tiger_Crypto_Signature
 * @see Tiger_Module_Pricing
 */
class Tiger_License_Checker
{
    /** Verdict states. */
    const VALID      = 'valid';        // reached home, entitled
    const LAPSED     = 'lapsed';       // reached home, NOT entitled — the only state that gates an update
    const UNKNOWN    = 'unknown';      // couldn't reach/trust home — assume current, stay quiet
    const UNLICENSED = 'unlicensed';   // no license on file — not gated at all

    /** Default verdict TTL (seconds) when the authority doesn't specify one. */
    const DEFAULT_TTL = 3600;

    /** @var Tiger_License_Store|null injected/lazy store */
    protected static $store = null;

    /** @var callable|null injected transport: fn(string $authority, array $payload): ?array */
    protected static $transport = null;

    // ---- seams (DI / tests) ----------------------------------------------------

    /**
     * Swap the license store (tests inject an in-memory one). Pass null to reset to the default.
     *
     * @param  Tiger_License_Store|null $store the store, or null to reset
     * @return void
     */
    public static function setStore(?Tiger_License_Store $store): void
    {
        self::$store = $store;
    }

    /**
     * Swap the authority transport (tests inject a fake). The callable takes ($authority, $payload) and
     * returns the decoded response array, or null when unreachable. Pass null to reset to real HTTP.
     *
     * @param  callable|null $transport the transport, or null to reset
     * @return void
     */
    public static function setTransport(?callable $transport): void
    {
        self::$transport = $transport;
    }

    /**
     * Reset all injected seams — for test isolation.
     *
     * @return void
     */
    public static function _reset(): void
    {
        self::$store = null;
        self::$transport = null;
    }

    // ---- holding a license -----------------------------------------------------

    /**
     * Remember a license this install holds for a module (called by the buy/install flow). A fresh store
     * drops any previously cached verdict.
     *
     * @param  string $slug    the module slug
     * @param  array  $license {key, authority, vendor, public_key}
     * @return void
     */
    public static function remember(string $slug, array $license): void
    {
        self::store()->put($slug, [
            'key'        => (string) ($license['key'] ?? ''),
            'authority'  => (string) ($license['authority'] ?? ''),
            'vendor'     => (string) ($license['vendor'] ?? ''),
            'public_key' => (string) ($license['public_key'] ?? ''),
        ]);
    }

    /**
     * The stored license record for a module, or null.
     *
     * @param  string $slug the module slug
     * @return array|null the record, or null
     */
    public static function get(string $slug): ?array
    {
        return self::store()->get($slug);
    }

    /**
     * Forget a module's license (on uninstall).
     *
     * @param  string $slug the module slug
     * @return void
     */
    public static function forget(string $slug): void
    {
        self::store()->forget($slug);
    }

    // ---- verify + gate ---------------------------------------------------------

    /**
     * Verify a module's license against its authority, using the cached verdict while it's fresh.
     *
     * @param  string $slug    the module slug
     * @param  array  $context optional {domain, extra:[...]} to send with the check
     * @param  int    $now     unix time override (0 = time()); for deterministic tests
     * @return array the verdict {state, can_update, latest_version, checked_at, expires_at}
     */
    public static function verify(string $slug, array $context = [], int $now = 0): array
    {
        $now = $now > 0 ? $now : time();
        $lic = self::store()->get($slug);

        if (!$lic || empty($lic['key']) || empty($lic['authority'])) {
            return self::_verdict(self::UNLICENSED, true, null, 0, $now);   // nothing to gate
        }

        $cached = (isset($lic['verdict']) && is_array($lic['verdict'])) ? $lic['verdict'] : null;
        if ($cached && (int) ($lic['expires_at'] ?? 0) > $now) {
            return $cached;   // fresh cache — no network
        }

        $resp = self::_ask((string) $lic['authority'], [
            'key'     => (string) $lic['key'],
            'domain'  => (string) ($context['domain'] ?? self::_domain()),
            'product' => $slug,
        ] + (isset($context['extra']) && is_array($context['extra']) ? $context['extra'] : []));

        // Unreachable, or a reply we couldn't trust → assume-current, stay quiet (serve last good, else unknown).
        if ($resp === null) {
            return $cached ?: self::_verdict(self::UNKNOWN, true, null, 0, $now);
        }
        $verdict = self::_interpret($resp, (string) ($lic['public_key'] ?? ''), $now);
        if ($verdict === null) {
            return $cached ?: self::_verdict(self::UNKNOWN, true, null, 0, $now);
        }

        // Cache the trusted verdict.
        $lic['verdict']    = $verdict;
        $lic['checked_at'] = $now;
        $lic['expires_at'] = $verdict['expires_at'];
        self::store()->put($slug, $lic);
        return $verdict;
    }

    /**
     * May this module auto-update right now? NAG-NEVER-DISABLE: only a definitive lapsed verdict says no;
     * unlicensed / unknown / valid all allow it.
     *
     * @param  string $slug    the module slug
     * @param  array  $context optional check context
     * @return bool
     */
    public static function canUpdate(string $slug, array $context = []): bool
    {
        return (bool) self::verify($slug, $context)['can_update'];
    }

    /**
     * The last cached verdict for a module — NO network (for UI badges). Unknown if never checked.
     *
     * @param  string $slug the module slug
     * @return array the verdict
     */
    public static function status(string $slug): array
    {
        $lic = self::store()->get($slug);
        if (!$lic || empty($lic['key'])) {
            return self::_verdict(self::UNLICENSED, true, null, 0, 0);
        }
        return (isset($lic['verdict']) && is_array($lic['verdict'])) ? $lic['verdict'] : self::_verdict(self::UNKNOWN, true, null, 0, 0);
    }

    // ---- internals -------------------------------------------------------------

    /** The active license store (the injected one, else the default option-backed store). */
    protected static function store(): Tiger_License_Store
    {
        if (self::$store === null) {
            self::$store = new Tiger_License_Store_Option();
        }
        return self::$store;
    }

    /**
     * Turn an authority reply into a trusted verdict, or null if it can't be trusted. When a public key is
     * pinned, the reply MUST carry a `payload` (the exact signed JSON string) + a `signature` over it; an
     * unsigned/forged reply returns null (→ treated as unreachable, so a forgery never nags).
     */
    protected static function _interpret(array $resp, string $publicKey, int $now): ?array
    {
        if ($publicKey !== '') {
            $payload   = $resp['payload'] ?? null;
            $signature = (string) ($resp['signature'] ?? '');
            if (!is_string($payload) || $signature === '' || !Tiger_Crypto_Signature::verify($payload, $signature, $publicKey)) {
                return null;
            }
            $data = json_decode($payload, true);
            if (!is_array($data)) {
                return null;
            }
        } else {
            $data = $resp;   // no pinned key (unsigned/dev) — trust the raw reply
        }

        $valid = !empty($data['valid']);
        $ttl   = isset($data['ttl']) ? max(0, (int) $data['ttl']) : self::DEFAULT_TTL;
        return self::_verdict(
            $valid ? self::VALID : self::LAPSED,
            $valid,                                      // can_update: only a reached-home valid=true allows it
            $data['latest_version'] ?? null,
            $now + $ttl,
            $now
        );
    }

    /** Build a normalized verdict. */
    protected static function _verdict(string $state, bool $canUpdate, $latest = null, int $expiresAt = 0, int $checkedAt = 0): array
    {
        return [
            'state'          => $state,
            'can_update'     => $canUpdate,
            'latest_version' => $latest,
            'checked_at'     => $checkedAt,
            'expires_at'     => $expiresAt,
        ];
    }

    /** POST the check to the authority's /verify endpoint (real HTTP), or the injected transport. Null on any failure. */
    protected static function _ask(string $authority, array $payload): ?array
    {
        if (self::$transport !== null) {
            return (self::$transport)($authority, $payload);
        }
        $url = rtrim($authority, '/') . '/verify';
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'content'       => (string) json_encode($payload),
            'timeout'       => 6,
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /** This install's host, for the check payload. */
    protected static function _domain(): string
    {
        if (!empty($_SERVER['HTTP_HOST'])) {
            return (string) $_SERVER['HTTP_HOST'];
        }
        return defined('TIGER_HOST') ? (string) TIGER_HOST : '';
    }
}
