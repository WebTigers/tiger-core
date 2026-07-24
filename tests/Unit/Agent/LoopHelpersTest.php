<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\Agent;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Agent_Loop;

/** Exposes the Loop's protected turn-shaping helpers (no provider/DB) for direct assertion. */
final class ExposedLoop extends Tiger_Agent_Loop
{
    public function toNeutral(array $t): array { return $this->_transcriptToNeutral($t); }
    public function enrich(array &$w, $text, array $ctx, $mode): void { $this->_enrichLastUserTurn($w, $text, $ctx, $mode); }
    public function feedback(array $e): string { return $this->_feedbackText($e); }
    public function strip(array $l): array { return $this->_strip($l); }
    public function clientEntry(array $a): array { return $this->_clientEntry($a); }
    public function attachImages(array &$w, $conv, array $a): void { $this->_attachImages($w, $conv, $a); }
}

/**
 * Tiger_Agent_Loop — the pure turn-shaping helpers that surround the (network/DB) step engine:
 * transcript → provider-neutral messages, the context-enriched trailing user envelope, the model-
 * facing tool-results block (including the capped /api data bridge), the client (DOM) ledger entry,
 * the ledger strip, mode ranking, and the vision-guard that skips image bytes for a text-only model.
 * The live `_converse` loop (provider + DB models) is the documented network boundary, not covered.
 */
#[CoversClass(Tiger_Agent_Loop::class)]
final class LoopHelpersTest extends UnitTestCase
{
    private ExposedLoop $loop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loop = new ExposedLoop('developer', 'user-1', 'org-1');
    }

    #[Test]
    public function modeRankOrdersAskAutoYoloAndDefaultsUnknownToAsk(): void
    {
        $this->assertSame(0, Tiger_Agent_Loop::modeRank('ask'));
        $this->assertSame(1, Tiger_Agent_Loop::modeRank('auto'));
        $this->assertSame(2, Tiger_Agent_Loop::modeRank('yolo'));
        $this->assertSame(0, Tiger_Agent_Loop::modeRank('bogus'));
    }

    #[Test]
    public function transcriptToNeutralMapsRolesAndDropsEmptyTurns(): void
    {
        // Literal role strings (== Tiger_Model_AgentMessage::ROLE_USER/ROLE_ASSISTANT) so the DB model
        // class isn't autoloaded here — it carries a pre-existing PHP 8.5 implicit-nullable deprecation.
        $neutral = $this->loop->toNeutral([
            ['role' => 'user', 'content' => 'hello'],
            ['role' => 'assistant', 'content' => 'hi there'],
            ['role' => 'assistant', 'content' => ''],   // empty → dropped
        ]);
        $this->assertCount(2, $neutral);
        $this->assertSame('user', $neutral[0]['role']);
        $this->assertSame('assistant', $neutral[1]['role']);
    }

    #[Test]
    public function enrichLastUserTurnReplacesTheTrailingUserWithAContextEnvelope(): void
    {
        $working = [['role' => 'user', 'content' => 'raw text']];
        $this->loop->enrich($working, 'Add an FAQ page', ['path' => '/x', 'role' => 'admin'], 'auto');

        $decoded = json_decode($working[0]['content'], true);
        $this->assertSame('Add an FAQ page', $decoded['message']);
        $this->assertSame('/x', $decoded['context']['path']);
        $this->assertSame('auto', $decoded['context']['mode']);
    }

    #[Test]
    public function enrichAppendsAnEnvelopeWhenNoTrailingUserTurnExists(): void
    {
        $working = [['role' => 'assistant', 'content' => 'earlier']];
        $this->loop->enrich($working, 'hi', [], 'ask');
        $this->assertSame('assistant', $working[0]['role']);
        $this->assertSame('user', $working[1]['role']);
        $this->assertStringContainsString('"message": "hi"', $working[1]['content']);
    }

    #[Test]
    public function feedbackTextRendersScoutFeedbackAndBridgesApiDataCapped(): void
    {
        $block = $this->loop->feedback([
            ['type' => 'read.file', 'status' => 'done', 'summary' => 'Read x', 'feedback' => 'FILE CONTENTS HERE'],
            ['type' => 'api', 'status' => 'done', 'summary' => 'Called cms/page/save', 'detail' => ['data' => ['id' => 'new-id']]],
        ]);
        $this->assertStringContainsString('[tool results', $block);
        $this->assertStringContainsString('FILE CONTENTS HERE', $block);
        $this->assertStringContainsString('Data: ', $block);
        $this->assertStringContainsString('new-id', $block);
    }

    #[Test]
    public function feedbackTextTruncatesAnOversizedApiPayload(): void
    {
        $big   = ['rows' => array_fill(0, 5000, 'x')];
        $block = $this->loop->feedback([['type' => 'api', 'status' => 'done', 'summary' => 's', 'detail' => ['data' => $big]]]);
        $this->assertStringContainsString('(truncated)', $block);
    }

    #[Test]
    public function stripDropsTheHeavyFeedbackPayloadFromEachLedgerEntry(): void
    {
        $stripped = $this->loop->strip([
            ['type' => 'read.file', 'summary' => 's', 'feedback' => 'HUGE'],
            ['type' => 'api', 'summary' => 's2'],
        ]);
        $this->assertArrayNotHasKey('feedback', $stripped[0]);
        $this->assertArrayNotHasKey('feedback', $stripped[1]);
        $this->assertSame('s', $stripped[0]['summary']);
    }

    #[Test]
    public function clientEntryDescribesADomActionForTheBrowserToRun(): void
    {
        $entry = $this->loop->clientEntry(['type' => 'dom.write', 'target' => 'articleBody', 'value' => '<b>x</b>', 'kind' => 'html', 'reason' => 'improve']);
        $this->assertSame('client', $entry['status']);
        $this->assertSame('dom.write', $entry['type']);
        $this->assertStringContainsString('articleBody', $entry['summary']);
        $this->assertSame('<b>x</b>', $entry['action']['value']);

        $read = $this->loop->clientEntry(['type' => 'dom.read', 'target' => 'headline']);
        $this->assertStringStartsWith('Read', $read['summary']);
    }

    #[Test]
    public function attachImagesSkipsWhenTheModelIsNotMultimodal(): void
    {
        // grok-2-latest is text-only per the vision heuristic → image bytes are never attached
        // (and no media disk is touched), so a text-only turn never fails on an unreadable image.
        $working = [['role' => 'user', 'content' => 'look at this']];
        $conv    = (object) ['provider' => 'grok', 'model' => 'grok-2-latest'];
        $this->loop->attachImages($working, $conv, [
            ['kind' => 'image', 'storage_key' => 'k', 'disk' => 'local', 'mime' => 'image/png'],
        ]);
        $this->assertArrayNotHasKey('images', $working[0]);
    }
}
