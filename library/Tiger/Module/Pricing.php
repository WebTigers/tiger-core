<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Module_Pricing — the manifest `pricing` block, normalized.
 *
 * A module's module.json may declare how it's sold. This is the single interpreter of that block, so the
 * installer, the Module Manager's Add screen, and the license checker all agree on what a module's pricing
 * MEANS rather than each poking at raw array keys.
 *
 * Models:
 *   free      — no charge.
 *   freemium  — free to install, with a paid upgrade elsewhere.
 *   paid      — sold OFF-platform; `pro_url` links out (the platform isn't in the transaction).
 *   licensed  — sold + licensed THROUGH the Module Manager against a license `authority` (a URL) run by a
 *               `vendor` (an "owner/repo" trust anchor). A licensed module is update-gated by its authority
 *               and MUST arrive as a signed artifact (integrity across an untrusted transport).
 *
 * Vendor-neutral: `authority`/`vendor` are plain fields — anyone can run an authority; this interpreter
 * says nothing about who the publisher is.
 *
 * @api
 * @see Tiger_Crypto_Signature
 */
class Tiger_Module_Pricing
{
    const FREE = 'free';
    const FREEMIUM = 'freemium';
    const PAID = 'paid';
    const LICENSED = 'licensed';

    const MODELS = [self::FREE, self::FREEMIUM, self::PAID, self::LICENSED];

    /**
     * Normalize a manifest's pricing block to a stable shape. Unknown/absent model → free; the legacy
     * `pro` value is read as `paid`.
     *
     * @param  array $manifest a decoded module.json (or a bare `pricing` sub-array)
     * @return array{model:string,authority:?string,vendor:?string,pro_url:?string,free_tier:bool} the normalized pricing
     */
    public static function of(array $manifest): array
    {
        if (isset($manifest['pricing']) && is_array($manifest['pricing'])) {
            $p = $manifest['pricing'];
        } elseif (isset($manifest['model'])) {
            $p = $manifest;                // a bare pricing block was passed
        } else {
            $p = [];
        }

        $model = strtolower(trim((string) ($p['model'] ?? self::FREE)));
        if ($model === 'pro') { $model = self::PAID; }               // legacy alias
        if (!in_array($model, self::MODELS, true)) { $model = self::FREE; }

        return [
            'model'     => $model,
            'authority' => self::_url($p['authority'] ?? null),
            'vendor'    => self::_ownerRepo($p['vendor'] ?? null),
            'pro_url'   => self::_url($p['pro_url'] ?? null),
            'free_tier' => in_array($model, [self::FREE, self::FREEMIUM], true),
        ];
    }

    /**
     * Whether a manifest is licensed (Module-Manager-sold, authority-gated, must be signed).
     *
     * @param  array $manifest a decoded module.json
     * @return bool
     */
    public static function isLicensed(array $manifest): bool
    {
        return self::of($manifest)['model'] === self::LICENSED;
    }

    /**
     * Assert a licensed manifest is well-formed — it must name an `authority` URL and a `vendor` trust
     * anchor. A no-op for every non-licensed model.
     *
     * @param  array $manifest a decoded module.json
     * @return void
     * @throws RuntimeException if licensed but missing a valid authority or vendor
     */
    public static function assertValid(array $manifest): void
    {
        $n = self::of($manifest);
        if ($n['model'] !== self::LICENSED) {
            return;
        }
        if ($n['authority'] === null) {
            throw new RuntimeException('A licensed module must declare pricing.authority (a license-authority URL).');
        }
        if ($n['vendor'] === null) {
            throw new RuntimeException("A licensed module must declare pricing.vendor (an 'owner/repo' trust anchor).");
        }
    }

    // ---- helpers ---------------------------------------------------------------

    /** An http(s) URL, or null. */
    protected static function _url($v): ?string
    {
        $v = is_string($v) ? trim($v) : '';
        return preg_match('#^https?://#i', $v) ? $v : null;
    }

    /** An "owner/repo" (optionally "@ref") shape — a bare slug or a github URL — or null. */
    protected static function _ownerRepo($v): ?string
    {
        $v = is_string($v) ? trim($v) : '';
        if ($v === '') { return null; }
        if (preg_match('~github\.com/([^/]+/[^/@#?\s]+)~i', $v, $m)) {
            $v = preg_replace('/\.git$/i', '', $m[1]);
        }
        return preg_match('~^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+(@[A-Za-z0-9._/-]+)?$~', $v) ? $v : null;
    }
}
