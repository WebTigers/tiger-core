<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Audience — the registry of email/marketing AUDIENCE segments a module can offer.
 *
 * A module that knows a meaningful group of people — TigerMembership's paid members, a CRM's contacts,
 * a store's past buyers — registers an audience PROVIDER from its Bootstrap. An email tool (TigerList)
 * reads the registry to let an operator pick a segment to send to, and resolves it to recipients — it
 * never learns how any provider computes its people. The registry is CORE (like Tiger_Sitemap /
 * Tiger_Dashboard / Tiger_Menu) so offering an audience never depends on the email module being
 * installed, and consuming one never depends on any particular provider.
 *
 * A provider is an array: [
 *   'label'    => 'Membership',                         // human name for the provider group
 *   'segments' => fn($orgId): array,                    // -> [ ['key','label','count'], … ]
 *   'resolve'  => fn($orgId, $segmentKey): array,       // -> [ ['user_id','email','name'], … ]
 * ]
 *
 * IMPORTANT BOUNDARY — this registry conveys ENTITLEMENT/membership only, never CONSENT. A provider
 * says who *is* in a group; the email tool that consumes it MUST apply its own consent / opt-out /
 * suppression before sending. A resolved audience is not a licence to email.
 *
 * @api
 */
class Tiger_Audience
{
    /** @var array<string,array> provider key => provider definition */
    protected static $_providers = [];

    /**
     * Register (or replace) an audience provider. Call from a module Bootstrap.
     *
     * @param  string $key      unique provider key (e.g. 'membership', 'crm')
     * @param  array  $provider ['label' => string, 'segments' => callable, 'resolve' => callable]
     * @return void
     */
    public static function register($key, array $provider)
    {
        $key = (string) $key;
        if ($key !== '') {
            self::$_providers[$key] = $provider;
        }
    }

    /** The registered providers, keyed. @return array<string,array> */
    public static function providers()
    {
        return self::$_providers;
    }

    /** One provider definition, or null. */
    public static function get($key)
    {
        return self::$_providers[(string) $key] ?? null;
    }

    /**
     * Every segment across all providers, flattened. Each entry carries its provider so a consumer can
     * resolve it later. A throwing provider is skipped (fail-soft).
     *
     * @param  string $orgId the site/tenant org
     * @return array<int,array{provider:string,provider_label:string,key:string,label:string,count:mixed}>
     */
    public static function segments($orgId)
    {
        $out = [];
        foreach (self::$_providers as $pkey => $p) {
            if (empty($p['segments']) || !is_callable($p['segments'])) {
                continue;
            }
            try {
                $segs = (array) call_user_func($p['segments'], $orgId);
            } catch (Throwable $e) {
                continue;
            }
            foreach ($segs as $seg) {
                if (is_array($seg)) {
                    $out[] = ['provider' => (string) $pkey, 'provider_label' => (string) ($p['label'] ?? $pkey)] + $seg;
                }
            }
        }
        return $out;
    }

    /**
     * Resolve one provider's segment to its people. The consumer still applies consent before emailing.
     *
     * @param  string $providerKey
     * @param  string $orgId
     * @param  string $segmentKey
     * @return array<int,array{user_id:string,email:string,name:string}>
     */
    public static function resolve($providerKey, $orgId, $segmentKey)
    {
        $p = self::get($providerKey);
        if (!$p || empty($p['resolve']) || !is_callable($p['resolve'])) {
            return [];
        }
        try {
            return (array) call_user_func($p['resolve'], $orgId, $segmentKey);
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Clear the registry (tests). @internal */
    public static function _reset()
    {
        self::$_providers = [];
    }
}
