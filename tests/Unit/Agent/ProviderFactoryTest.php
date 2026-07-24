<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\Agent;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Agent_Provider_Anthropic;
use Tiger_Agent_Provider_Factory;
use Tiger_Agent_Provider_Gemini;
use Tiger_Agent_Provider_OpenAi;

/**
 * Tiger_Agent_Provider_Factory — resolve an adapter + its default/curated models by provider key,
 * and the conservative supportsVision heuristic. Unknown keys fall back to Anthropic (a stale
 * config value never bricks the aside). All pure, no network.
 */
#[CoversClass(Tiger_Agent_Provider_Factory::class)]
final class ProviderFactoryTest extends UnitTestCase
{
    #[Test]
    public function makeResolvesTheAdapterClassForAKnownKey(): void
    {
        $this->assertInstanceOf(Tiger_Agent_Provider_Anthropic::class, Tiger_Agent_Provider_Factory::make('anthropic'));
        $this->assertInstanceOf(Tiger_Agent_Provider_OpenAi::class, Tiger_Agent_Provider_Factory::make('openai'));
        $this->assertInstanceOf(Tiger_Agent_Provider_Gemini::class, Tiger_Agent_Provider_Factory::make('gemini'));
    }

    #[Test]
    public function makeFallsBackToAnthropicForAnUnknownKey(): void
    {
        $this->assertInstanceOf(Tiger_Agent_Provider_Anthropic::class, Tiger_Agent_Provider_Factory::make('does-not-exist'));
    }

    #[Test]
    public function defaultModelIsTheProvidersDefaultAndFallsBackForUnknown(): void
    {
        $this->assertSame('claude-sonnet-5', Tiger_Agent_Provider_Factory::defaultModel('anthropic'));
        $this->assertSame('gpt-4o', Tiger_Agent_Provider_Factory::defaultModel('openai'));
        // unknown → anthropic's default
        $this->assertSame('claude-sonnet-5', Tiger_Agent_Provider_Factory::defaultModel('nope'));
    }

    #[Test]
    public function staticModelsReturnsTheCuratedFallbackList(): void
    {
        $m = Tiger_Agent_Provider_Factory::staticModels('deepseek');
        $this->assertContains('deepseek-chat', $m);
        $this->assertContains('deepseek-reasoner', $m);
        // unknown key → anthropic's list
        $this->assertContains('claude-sonnet-5', Tiger_Agent_Provider_Factory::staticModels('nope'));
    }

    #[Test]
    public function optionsIsTheKeyToLabelRosterForTheDropdown(): void
    {
        $opts = Tiger_Agent_Provider_Factory::options();
        $this->assertSame('Anthropic (Claude)', $opts['anthropic']);
        $this->assertArrayHasKey('openrouter', $opts);
        $this->assertCount(8, $opts);   // the full roster
    }

    // ----- supportsVision: the conservative heuristic -----------------------

    /** @return array<string,array{0:string,1:string,2:bool}> */
    public static function visionCases(): array
    {
        return [
            'claude is multimodal'          => ['anthropic', 'claude-opus-4-8', true],
            'non-claude anthropic id'       => ['anthropic', 'palm-2', false],
            'gpt-4o sees'                   => ['openai', 'gpt-4o', true],
            'gpt-4.1 sees'                  => ['openai', 'gpt-4.1-mini', true],
            'o3 sees'                       => ['openai', 'o3-mini', true],
            'gpt-4o-audio does not'         => ['openai', 'gpt-4o-audio-preview', false],
            'gpt-4o-realtime does not'      => ['openai', 'gpt-4o-realtime-preview', false],
            'plain gpt-3.5 does not'        => ['openai', 'gpt-3.5-turbo', false],
            'gemini is multimodal'          => ['gemini', 'gemini-2.0-flash', true],
            'non-gemini id'                 => ['gemini', 'palm', false],
            'grok vision variant'           => ['grok', 'grok-2-vision-latest', true],
            'grok-4 sees'                   => ['grok', 'grok-4', true],
            'plain grok-2 does not'         => ['grok', 'grok-2-latest', false],
            'pixtral sees'                  => ['mistral', 'pixtral-12b', true],
            'mistral-small-24 sees'         => ['mistral', 'mistral-small-2409', true],
            'plain mistral does not'        => ['mistral', 'mistral-large-latest', false],
            'openrouter claude route sees'  => ['openrouter', 'anthropic/claude-sonnet-5', true],
            'openrouter llama-3.2 sees'     => ['openrouter', 'meta-llama/llama-3.2-11b-vision', true],
            'openrouter plain text route'   => ['openrouter', 'meta-llama/llama-3.1-8b', false],
            'groq vision sees'              => ['groq', 'llama-3.2-11b-vision-preview', true],
            'groq plain text does not'      => ['groq', 'llama-3.3-70b-versatile', false],
            'deepseek is text-only'         => ['deepseek', 'deepseek-chat', false],
            'unknown provider defaults off' => ['whoknows', 'anything', false],
        ];
    }

    #[Test]
    #[DataProvider('visionCases')]
    public function supportsVisionMatchesTheHeuristic(string $provider, string $model, bool $expected): void
    {
        $this->assertSame($expected, Tiger_Agent_Provider_Factory::supportsVision($provider, $model));
    }
}
