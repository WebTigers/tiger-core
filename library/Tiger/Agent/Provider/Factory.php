<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Agent_Provider_Factory — resolve a provider adapter (and its default model) by key.
 *
 * The one place that knows the provider roster, so adding OpenAI/Ollama later is a single
 * `case` here plus a new adapter class — nothing else in the agent changes (TIGERAGENT.md
 * §7). Anthropic is the reference; unknown keys fall back to it rather than failing hard, so
 * a stale config value never bricks the aside.
 *
 * @api
 */
class Tiger_Agent_Provider_Factory
{
    /** The provider roster: key => [label, adapter class, default model, curated static models]. The
     *  `models` list is the NO-KEY fallback for the selector; with a key the adapter fetches the live
     *  list from the provider (vendors churn models constantly — never hardcode as the source). */
    const PROVIDERS = [
        'anthropic' => [
            'label'   => 'Anthropic (Claude)',
            'adapter' => 'Tiger_Agent_Provider_Anthropic',
            'default' => 'claude-sonnet-5',
            'models'  => ['claude-opus-4-8', 'claude-sonnet-5', 'claude-haiku-4-5-20251001'],
        ],
    ];

    /**
     * Build the adapter for a provider key.
     *
     * @param  string $provider the provider key
     * @return Tiger_Agent_Provider_Adapter
     */
    public static function make($provider)
    {
        $spec  = self::PROVIDERS[$provider] ?? self::PROVIDERS['anthropic'];
        $class = $spec['adapter'];
        return new $class();
    }

    /**
     * The default model id for a provider.
     *
     * @param  string $provider the provider key
     * @return string
     */
    public static function defaultModel($provider)
    {
        $spec = self::PROVIDERS[$provider] ?? self::PROVIDERS['anthropic'];
        return $spec['default'];
    }

    /**
     * The curated static model ids for a provider — the selector's fallback when there's no key.
     *
     * @param  string $provider the provider key
     * @return string[]
     */
    public static function staticModels($provider)
    {
        $spec = self::PROVIDERS[$provider] ?? self::PROVIDERS['anthropic'];
        return !empty($spec['models']) ? $spec['models'] : [$spec['default']];
    }

    /**
     * The roster for the settings dropdown: [key => label].
     *
     * @return array<string,string>
     */
    public static function options()
    {
        $out = [];
        foreach (self::PROVIDERS as $key => $spec) {
            $out[$key] = $spec['label'];
        }
        return $out;
    }
}
