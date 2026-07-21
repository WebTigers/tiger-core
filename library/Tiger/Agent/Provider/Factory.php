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
     *  list from the provider (vendors churn models constantly — never hardcode as the source, and
     *  the static ids below WILL age). Most providers reuse the one OpenAI-compatible base adapter;
     *  Anthropic and Gemini have their own wire formats. */
    const PROVIDERS = [
        'anthropic' => [
            'label'   => 'Anthropic (Claude)',
            'adapter' => 'Tiger_Agent_Provider_Anthropic',
            'default' => 'claude-sonnet-5',
            'models'  => ['claude-opus-4-8', 'claude-sonnet-5', 'claude-haiku-4-5-20251001'],
        ],
        'openai' => [
            'label'   => 'OpenAI (GPT)',
            'adapter' => 'Tiger_Agent_Provider_OpenAi',
            'default' => 'gpt-4o',
            'models'  => ['gpt-4o', 'gpt-4o-mini', 'gpt-4.1', 'gpt-4.1-mini', 'o3-mini'],
        ],
        'gemini' => [
            'label'   => 'Google (Gemini)',
            'adapter' => 'Tiger_Agent_Provider_Gemini',
            'default' => 'gemini-2.0-flash',
            'models'  => ['gemini-2.5-pro', 'gemini-2.5-flash', 'gemini-2.0-flash', 'gemini-1.5-pro'],
        ],
        'grok' => [
            'label'   => 'xAI (Grok)',
            'adapter' => 'Tiger_Agent_Provider_Grok',
            'default' => 'grok-2-latest',
            'models'  => ['grok-2-latest', 'grok-2-vision-latest', 'grok-beta'],
        ],
        'groq' => [
            'label'   => 'Groq (fast open models)',
            'adapter' => 'Tiger_Agent_Provider_Groq',
            'default' => 'llama-3.3-70b-versatile',
            'models'  => ['llama-3.3-70b-versatile', 'llama-3.1-8b-instant', 'mixtral-8x7b-32768', 'gemma2-9b-it'],
        ],
        'mistral' => [
            'label'   => 'Mistral',
            'adapter' => 'Tiger_Agent_Provider_Mistral',
            'default' => 'mistral-small-latest',
            'models'  => ['mistral-large-latest', 'mistral-small-latest', 'open-mistral-nemo'],
        ],
        'deepseek' => [
            'label'   => 'DeepSeek',
            'adapter' => 'Tiger_Agent_Provider_DeepSeek',
            'default' => 'deepseek-chat',
            'models'  => ['deepseek-chat', 'deepseek-reasoner'],
        ],
        'openrouter' => [
            'label'   => 'OpenRouter (many providers)',
            'adapter' => 'Tiger_Agent_Provider_OpenRouter',
            'default' => 'openai/gpt-4o-mini',
            'models'  => ['openai/gpt-4o-mini', 'anthropic/claude-sonnet-5', 'google/gemini-2.0-flash-exp:free', 'meta-llama/llama-3.3-70b-instruct'],
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

    /**
     * Can this provider+model accept image input (multimodal / "vision")?
     *
     * Deliberately CONSERVATIVE: return true only for models we're confident take images. When unsure
     * we return false so the caller degrades to a caption/OCR pass (which works for every model) rather
     * than sending an image to a text-only model and getting an API error. Model names churn, so this
     * is a heuristic on the id — the safe direction is "no" (caption) not "yes" (risk a hard failure).
     *
     * @param  string $provider provider key
     * @param  string $model    model id
     * @return bool
     */
    public static function supportsVision($provider, $model)
    {
        $m = strtolower((string) $model);
        switch ((string) $provider) {
            case 'anthropic':                                   // every current Claude is multimodal
                return strpos($m, 'claude') !== false;
            case 'openai':                                      // 4o / 4.1 / 5 / o-series see images; not audio-only
                return (bool) preg_match('~gpt-4o|gpt-4\.1|gpt-5|chatgpt-4o|(?:^|[^a-z])o[134](?:$|[^a-z])~', $m)
                       && strpos($m, 'audio') === false && strpos($m, 'realtime') === false;
            case 'gemini':                                      // 1.5+ and 2.x are all multimodal
                return strpos($m, 'gemini') !== false;
            case 'grok':
                return strpos($m, 'vision') !== false || (bool) preg_match('~grok-(4|2-vision)~', $m);
            case 'mistral':                                     // pixtral + the newer vision-capable small/medium
                return strpos($m, 'pixtral') !== false || (bool) preg_match('~mistral-(small|medium)-2[45]~', $m);
            case 'openrouter':                                  // route id names its upstream — reuse the same cues
                return (bool) preg_match('~vision|gpt-4o|gpt-4\.1|gpt-5|claude|gemini|pixtral|llama-3\.2~', $m);
            case 'groq':
                return strpos($m, 'vision') !== false || strpos($m, 'llama-3.2') !== false;
            case 'deepseek':                                    // text-only today
            default:
                return false;
        }
    }
}
