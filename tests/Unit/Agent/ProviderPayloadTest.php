<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\Agent;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Agent_Provider_Anthropic;
use Tiger_Agent_Provider_DeepSeek;
use Tiger_Agent_Provider_Gemini;
use Tiger_Agent_Provider_Grok;
use Tiger_Agent_Provider_Groq;
use Tiger_Agent_Provider_Mistral;
use Tiger_Agent_Provider_OpenAi;
use Tiger_Agent_Provider_OpenAiCompatible;
use Tiger_Agent_Provider_OpenRouter;

/**
 * The provider adapters — request-building and response-parsing, exercised by stubbing the ONE
 * transport seam each adapter exposes (`_post`, the single cURL call). This covers everything
 * reachable without a live network: neutral-message → provider-native payload mapping (system
 * placement, role alternation, multimodal image parts), the response → {text, usage} parse, and
 * the keyless static model fallback. The live cURL POST itself is the only uncovered line.
 *
 * @see Tiger_Agent_Provider_Anthropic
 * @see Tiger_Agent_Provider_OpenAiCompatible
 * @see Tiger_Agent_Provider_Gemini
 */
#[CoversClass(Tiger_Agent_Provider_Anthropic::class)]
#[CoversClass(Tiger_Agent_Provider_OpenAiCompatible::class)]
#[CoversClass(Tiger_Agent_Provider_OpenAi::class)]
#[CoversClass(Tiger_Agent_Provider_OpenRouter::class)]
#[CoversClass(Tiger_Agent_Provider_Grok::class)]
#[CoversClass(Tiger_Agent_Provider_Groq::class)]
#[CoversClass(Tiger_Agent_Provider_Mistral::class)]
#[CoversClass(Tiger_Agent_Provider_DeepSeek::class)]
#[CoversClass(Tiger_Agent_Provider_Gemini::class)]
final class ProviderPayloadTest extends UnitTestCase
{
    // ===== Anthropic =========================================================

    private function anthropic(): Tiger_Agent_Provider_Anthropic
    {
        return new class extends Tiger_Agent_Provider_Anthropic {
            public array $lastPayload = [];
            public function payload($s, array $m, $model): array { return $this->_payload($s, $m, $model); }
            public function mapMessages(array $m): array { return $this->_mapMessages($m); }
            protected function _post(array $payload, $apiKey) {
                $this->lastPayload = $payload;
                return [
                    'content' => [['type' => 'text', 'text' => 'Hello'], ['type' => 'thinking', 'text' => 'ignored']],
                    'usage'   => ['input_tokens' => 10, 'cache_read_input_tokens' => 2, 'cache_creation_input_tokens' => 3, 'output_tokens' => 7],
                ];
            }
        };
    }

    #[Test]
    public function anthropicCompleteConcatenatesTextBlocksAndSumsCachedInputTokens(): void
    {
        $a   = $this->anthropic();
        $res = $a->complete('SYS', [['role' => 'user', 'content' => 'hi']], 'claude-sonnet-5', 'key');
        $this->assertSame('Hello', $res['text']);                 // only text blocks, thinking dropped
        $this->assertSame(15, $res['usage']['input']);            // 10 + 2 + 3
        $this->assertSame(7, $res['usage']['output']);
    }

    #[Test]
    public function anthropicPayloadMarksTheSystemPromptEphemeralForPromptCaching(): void
    {
        $p = $this->anthropic()->payload('SYSTEM', [['role' => 'user', 'content' => 'hi']], 'claude-sonnet-5');
        $this->assertSame('claude-sonnet-5', $p['model']);
        $this->assertSame(4096, $p['max_tokens']);
        $this->assertSame('SYSTEM', $p['system'][0]['text']);
        $this->assertSame('ephemeral', $p['system'][0]['cache_control']['type']);
        $this->assertNotEmpty($p['messages']);
    }

    #[Test]
    public function anthropicMapMessagesPrependsAUserTurnMergesRolesAndRendersImages(): void
    {
        $mapped = $this->anthropic()->mapMessages([
            ['role' => 'assistant', 'content' => 'leading assistant'],   // forces a (continue) user prepend
            ['role' => 'user', 'content' => 'look', 'images' => [['mime' => 'image/png', 'data' => 'AAAA']]],
            ['role' => 'user', 'content' => 'and more'],                 // merges into the previous user turn
            ['role' => 'user', 'content' => ''],                         // empty + no image → skipped
            ['role' => 'assistant', 'content' => 'x', 'images' => [['mime' => 'image/png', 'data' => 'IGNORED']]],
        ]);

        $this->assertSame('user', $mapped[0]['role']);
        $this->assertSame('(continue)', $mapped[0]['content'][0]['text']);

        // The image ended up as a base64 image block on a user turn.
        $flat = json_encode($mapped);
        $this->assertStringContainsString('"type":"image"', $flat);
        $this->assertStringContainsString('"data":"AAAA"', $flat);
        // An assistant turn never carries an image.
        $this->assertStringNotContainsString('IGNORED', $flat);
    }

    #[Test]
    public function anthropicModelsWithoutAKeyReturnsTheStaticFallbackShape(): void
    {
        $models = (new Tiger_Agent_Provider_Anthropic())->models('');
        $this->assertNotEmpty($models);
        $this->assertArrayHasKey('id', $models[0]);
        $this->assertArrayHasKey('label', $models[0]);
    }

    // ===== OpenAI-compatible base ============================================

    /** An OpenAI-compatible stub that captures the outgoing request and returns a canned reply. */
    private function openAiStub(): Tiger_Agent_Provider_OpenAiCompatible
    {
        return new class extends Tiger_Agent_Provider_OpenAi {
            public array $captured = [];
            protected function _post($url, array $payload, array $headers) {
                $this->captured = ['url' => $url, 'payload' => $payload, 'headers' => $headers];
                return ['choices' => [['message' => ['content' => 'reply text']]], 'usage' => ['prompt_tokens' => 4, 'completion_tokens' => 6]];
            }
        };
    }

    #[Test]
    public function openAiCompatibleBuildsSystemPlusTurnsAndParsesTheReply(): void
    {
        $stub = $this->openAiStub();
        $res  = $stub->complete('SYS', [
            ['role' => 'user', 'content' => 'question'],
            ['role' => 'assistant', 'content' => 'earlier answer'],
            ['role' => 'user', 'content' => ''],   // empty + no image → skipped
        ], 'gpt-4o', 'key');

        $this->assertSame('reply text', $res['text']);
        $this->assertSame(4, $res['usage']['input']);
        $this->assertSame(6, $res['usage']['output']);

        $turns = $stub->captured['payload']['messages'];
        $this->assertSame('system', $turns[0]['role']);
        $this->assertSame('SYS', $turns[0]['content']);
        $this->assertSame('user', $turns[1]['role']);
        $this->assertSame('assistant', $turns[2]['role']);
        $this->assertStringEndsWith('/chat/completions', $stub->captured['url']);
    }

    #[Test]
    public function openAiUsesMaxCompletionTokensNotMaxTokens(): void
    {
        $stub = $this->openAiStub();
        $stub->complete('', [['role' => 'user', 'content' => 'hi']], 'gpt-5', 'key');
        $this->assertArrayHasKey('max_completion_tokens', $stub->captured['payload']);
        $this->assertArrayNotHasKey('max_tokens', $stub->captured['payload']);
        // No system turn when the system prompt is empty.
        $this->assertSame('user', $stub->captured['payload']['messages'][0]['role']);
    }

    #[Test]
    public function openAiCompatibleRendersAUserImageAsAVisionImageUrlPart(): void
    {
        $stub = $this->openAiStub();
        $stub->complete('SYS', [
            ['role' => 'user', 'content' => 'see this', 'images' => [['mime' => 'image/jpeg', 'data' => 'ZZZ']]],
        ], 'gpt-4o', 'key');

        $parts = $stub->captured['payload']['messages'][1]['content'];   // [0]=system, [1]=user
        $this->assertSame('text', $parts[0]['type']);
        $this->assertSame('image_url', $parts[1]['type']);
        $this->assertSame('data:image/jpeg;base64,ZZZ', $parts[1]['image_url']['url']);
    }

    #[Test]
    public function openRouterAddsAttributionHeaders(): void
    {
        $stub = new class extends Tiger_Agent_Provider_OpenRouter {
            public array $headers = [];
            protected function _post($url, array $payload, array $headers) {
                $this->headers = $headers;
                return ['choices' => [['message' => ['content' => 'x']]], 'usage' => []];
            }
        };
        $stub->complete('', [['role' => 'user', 'content' => 'hi']], 'openai/gpt-4o-mini', 'key');
        $joined = implode("\n", $stub->headers);
        $this->assertStringContainsString('HTTP-Referer: https://webtigers.com', $joined);
        $this->assertStringContainsString('X-Title: Tiger', $joined);
        $this->assertStringContainsString('Authorization: Bearer key', $joined);
    }

    #[Test]
    public function everyConcreteOpenAiProviderTargetsItsOwnBaseUrl(): void
    {
        $cases = [
            [new class extends Tiger_Agent_Provider_Grok { public string $u = ''; protected function _post($url, array $p, array $h) { $this->u = $url; return ['choices' => [['message' => ['content' => '']]], 'usage' => []]; } }, 'api.x.ai'],
            [new class extends Tiger_Agent_Provider_Groq { public string $u = ''; protected function _post($url, array $p, array $h) { $this->u = $url; return ['choices' => [['message' => ['content' => '']]], 'usage' => []]; } }, 'api.groq.com'],
            [new class extends Tiger_Agent_Provider_Mistral { public string $u = ''; protected function _post($url, array $p, array $h) { $this->u = $url; return ['choices' => [['message' => ['content' => '']]], 'usage' => []]; } }, 'api.mistral.ai'],
            [new class extends Tiger_Agent_Provider_DeepSeek { public string $u = ''; protected function _post($url, array $p, array $h) { $this->u = $url; return ['choices' => [['message' => ['content' => '']]], 'usage' => []]; } }, 'api.deepseek.com'],
        ];
        foreach ($cases as [$stub, $host]) {
            $stub->complete('', [['role' => 'user', 'content' => 'hi']], 'm', 'k');
            $this->assertStringContainsString($host, $stub->u);
            $this->assertStringEndsWith('/chat/completions', $stub->u);
        }
    }

    #[Test]
    public function everyConcreteOpenAiProviderReturnsItsKeylessStaticModelList(): void
    {
        foreach ([
            new Tiger_Agent_Provider_OpenAi(),
            new Tiger_Agent_Provider_OpenRouter(),
            new Tiger_Agent_Provider_Grok(),
            new Tiger_Agent_Provider_Groq(),
            new Tiger_Agent_Provider_Mistral(),
            new Tiger_Agent_Provider_DeepSeek(),
        ] as $adapter) {
            $models = $adapter->models('');   // no key → the curated static fallback (no network)
            $this->assertNotEmpty($models, get_class($adapter) . ' should have static models');
            $this->assertSame($models[0]['id'], $models[0]['label']);
        }
    }

    // ===== Gemini ============================================================

    private function geminiStub(): Tiger_Agent_Provider_Gemini
    {
        return new class extends Tiger_Agent_Provider_Gemini {
            public array $captured = [];
            public function contents(array $m): array { return $this->_contents($m); }
            protected function _post($url, array $payload, $apiKey) {
                $this->captured = ['url' => $url, 'payload' => $payload];
                return [
                    'candidates'    => [['content' => ['parts' => [['text' => 'A'], ['text' => 'B']]]]],
                    'usageMetadata' => ['promptTokenCount' => 11, 'candidatesTokenCount' => 22],
                ];
            }
        };
    }

    #[Test]
    public function geminiNormalizesTheModelIdSetsSystemInstructionAndParsesParts(): void
    {
        $stub = $this->geminiStub();
        $res  = $stub->complete('SYS', [['role' => 'user', 'content' => 'hi']], 'models/gemini-2.0-flash', 'key');

        $this->assertSame('AB', $res['text']);            // parts concatenated
        $this->assertSame(11, $res['usage']['input']);
        $this->assertSame(22, $res['usage']['output']);

        // 'models/' prefix stripped, then re-added by the endpoint builder → single occurrence.
        $this->assertStringContainsString('models/gemini-2.0-flash:generateContent', $stub->captured['url']);
        $this->assertSame('SYS', $stub->captured['payload']['systemInstruction']['parts'][0]['text']);
        $this->assertSame(4096, $stub->captured['payload']['generationConfig']['maxOutputTokens']);
    }

    #[Test]
    public function geminiOmitsSystemInstructionWhenSystemIsEmpty(): void
    {
        $stub = $this->geminiStub();
        $stub->complete('', [['role' => 'user', 'content' => 'hi']], 'gemini-2.0-flash', 'key');
        $this->assertArrayNotHasKey('systemInstruction', $stub->captured['payload']);
    }

    #[Test]
    public function geminiContentsUsesModelRoleMergesTurnsRendersInlineImagesAndLeadsWithUser(): void
    {
        $contents = $this->geminiStub()->contents([
            ['role' => 'assistant', 'content' => 'lead'],   // → prepend a user (continue)
            ['role' => 'user', 'content' => 'look', 'images' => [['mime' => 'image/png', 'data' => 'IMG']]],
            ['role' => 'user', 'content' => 'more'],         // merges
        ]);

        $this->assertSame('user', $contents[0]['role']);
        $flat = json_encode($contents);
        $this->assertStringContainsString('"role":"model"', $flat);       // assistant → model
        $this->assertStringContainsString('"inlineData"', $flat);
        $this->assertStringContainsString('"data":"IMG"', $flat);
    }

    #[Test]
    public function geminiModelsWithoutAKeyIsTheStaticFallback(): void
    {
        $models = (new Tiger_Agent_Provider_Gemini())->models('');
        $this->assertNotEmpty($models);
        $this->assertArrayHasKey('id', $models[0]);
    }
}
