<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\Agent;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Agent_Contract;

/**
 * Tiger_Agent_Contract — the request/response parser between the app and the model.
 *
 * The load-bearing seam: a model's raw reply → a normalized {say, actions, navigate, done}. It is
 * fail-closed (unknown action types dropped, malformed JSON degrades to a say-only turn) and it
 * sanitizes every action's fields before the Forge can act on them. All pure, no DB/network.
 */
#[CoversClass(Tiger_Agent_Contract::class)]
final class ContractTest extends UnitTestCase
{
    // ----- parse(): the envelope --------------------------------------------

    #[Test]
    public function parsesAWholeJsonObjectIntoTheNormalizedContract(): void
    {
        $raw = json_encode([
            'say'      => 'Creating the page.',
            'actions'  => [['type' => 'api', 'module' => 'cms', 'service' => 'page', 'method' => 'save', 'params' => ['title' => 'FAQ'], 'reason' => 'r']],
            'navigate' => '/cms/admin/pages',
            'done'     => false,
        ]);
        $c = Tiger_Agent_Contract::parse($raw);
        $this->assertSame('Creating the page.', $c['say']);
        $this->assertCount(1, $c['actions']);
        $this->assertSame('api', $c['actions'][0]['type']);
        $this->assertSame('/cms/admin/pages', $c['navigate']);
        $this->assertFalse($c['done']);
    }

    #[Test]
    public function nonJsonReplyDegradesToASayOnlyDoneTurn(): void
    {
        $c = Tiger_Agent_Contract::parse('  Just chatting, no JSON here.  ');
        $this->assertSame('Just chatting, no JSON here.', $c['say']);
        $this->assertSame([], $c['actions']);
        $this->assertNull($c['navigate']);
        $this->assertTrue($c['done']);   // a chatty model still answers
    }

    #[Test]
    public function extractsJsonFromAFencedCodeBlock(): void
    {
        $raw = "Sure!\n```json\n{\"say\":\"Hi\",\"done\":true}\n```\nthanks";
        $c = Tiger_Agent_Contract::parse($raw);
        $this->assertSame('Hi', $c['say']);
        $this->assertTrue($c['done']);
    }

    #[Test]
    public function extractsTheFirstBalancedBraceBlockFromSurroundingProse(): void
    {
        $raw = 'Here you go: {"say":"With a nested {brace} and \"quote\"","done":false} — done.';
        $c = Tiger_Agent_Contract::parse($raw);
        $this->assertSame('With a nested {brace} and "quote"', $c['say']);
        $this->assertFalse($c['done']);
    }

    #[Test]
    public function doneDefaultsToTrueWhenTheKeyIsAbsent(): void
    {
        $c = Tiger_Agent_Contract::parse('{"say":"no done key"}');
        $this->assertTrue($c['done']);
    }

    #[Test]
    public function noBraceAtAllIsProse(): void
    {
        $c = Tiger_Agent_Contract::parse('absolutely no json');
        $this->assertSame('absolutely no json', $c['say']);
        $this->assertTrue($c['done']);
    }

    // ----- navigate validation ----------------------------------------------

    #[Test]
    public function navigateMustBeAPathAndRejectsSchemeHostAndDoubleSlash(): void
    {
        $this->assertNull(Tiger_Agent_Contract::parse('{"say":"","navigate":"https://evil.test/x"}')['navigate']);
        $this->assertNull(Tiger_Agent_Contract::parse('{"say":"","navigate":"//evil.test"}')['navigate']);
        $this->assertNull(Tiger_Agent_Contract::parse('{"say":"","navigate":"relative/path"}')['navigate']);
        $this->assertSame('/ok/path', Tiger_Agent_Contract::parse('{"say":"","navigate":"/ok/path"}')['navigate']);
    }

    // ----- isRead / isClient ------------------------------------------------

    #[Test]
    public function classifiesReadAndClientActionTypes(): void
    {
        $this->assertTrue(Tiger_Agent_Contract::isRead(Tiger_Agent_Contract::READ_FILE));
        $this->assertTrue(Tiger_Agent_Contract::isRead(Tiger_Agent_Contract::READ_INVENTORY));
        $this->assertFalse(Tiger_Agent_Contract::isRead(Tiger_Agent_Contract::ACTION_API));
        $this->assertFalse(Tiger_Agent_Contract::isRead(Tiger_Agent_Contract::DOM_READ));

        $this->assertTrue(Tiger_Agent_Contract::isClient(Tiger_Agent_Contract::DOM_WRITE));
        $this->assertTrue(Tiger_Agent_Contract::isClient(Tiger_Agent_Contract::DOM_READ));
        $this->assertFalse(Tiger_Agent_Contract::isClient(Tiger_Agent_Contract::ACTION_FILE));
    }

    // ----- normalizeAction: fail-closed -------------------------------------

    #[Test]
    public function dropsActionsWithNoTypeOrAnUnknownType(): void
    {
        $this->assertNull(Tiger_Agent_Contract::normalizeAction(['reason' => 'no type']));
        $this->assertNull(Tiger_Agent_Contract::normalizeAction(['type' => 'hallucinated']));
        $this->assertNull(Tiger_Agent_Contract::normalizeAction('not even an array'));
    }

    #[Test]
    public function parseDropsUnrecognizedActionsButKeepsGoodOnes(): void
    {
        $raw = json_encode(['say' => 'x', 'actions' => [
            ['type' => 'nope'],
            ['type' => 'api', 'module' => 'cms', 'service' => 'page', 'method' => 'save'],
        ]]);
        $c = Tiger_Agent_Contract::parse($raw);
        $this->assertCount(1, $c['actions']);
        $this->assertSame('api', $c['actions'][0]['type']);
    }

    // ----- normalizeAction: api ---------------------------------------------

    #[Test]
    public function apiActionRequiresModuleServiceMethodAndSanitizesThem(): void
    {
        $this->assertNull(Tiger_Agent_Contract::normalizeAction(['type' => 'api', 'module' => 'cms', 'service' => 'page']));

        $a = Tiger_Agent_Contract::normalizeAction([
            'type' => 'api', 'module' => 'c!m@s', 'service' => 'pa ge', 'method' => 'sa-ve_2',
            'params' => ['a' => 1], 'reason' => 'why',
        ]);
        $this->assertSame('cms', $a['module']);        // non-alpha stripped
        $this->assertSame('page', $a['service']);
        $this->assertSame('save_2', $a['method']);     // digits + underscore kept
        $this->assertSame(['a' => 1], $a['params']);
        $this->assertSame('why', $a['reason']);
    }

    #[Test]
    public function apiParamsDefaultToEmptyArrayWhenNotAnArray(): void
    {
        $a = Tiger_Agent_Contract::normalizeAction(['type' => 'api', 'module' => 'cms', 'service' => 'page', 'method' => 'save', 'params' => 'nope']);
        $this->assertSame([], $a['params']);
    }

    // ----- normalizeAction: code --------------------------------------------

    #[Test]
    public function codeActionRequiresNonEmptyCodeAndDefaultsNameLanguage(): void
    {
        $this->assertNull(Tiger_Agent_Contract::normalizeAction(['type' => 'code', 'code' => '']));

        $a = Tiger_Agent_Contract::normalizeAction(['type' => 'code', 'code' => '<?php 1;']);
        $this->assertSame('agent-snippet', $a['name']);
        $this->assertSame('php', $a['language']);
        $this->assertSame('<?php 1;', $a['code']);
    }

    // ----- normalizeAction: file --------------------------------------------

    #[Test]
    public function fileActionRequiresPathAndContentsKey(): void
    {
        $this->assertNull(Tiger_Agent_Contract::normalizeAction(['type' => 'file', 'path' => 'x']));         // no contents key
        $this->assertNull(Tiger_Agent_Contract::normalizeAction(['type' => 'file', 'contents' => 'x']));     // no path

        $a = Tiger_Agent_Contract::normalizeAction(['type' => 'file', 'path' => 'modules/x/v.phtml', 'contents' => '<h1>hi</h1>', 'reason' => 'r']);
        $this->assertSame('modules/x/v.phtml', $a['path']);
        $this->assertSame('<h1>hi</h1>', $a['contents']);
    }

    #[Test]
    public function fileActionAcceptsEmptyStringContents(): void
    {
        $a = Tiger_Agent_Contract::normalizeAction(['type' => 'file', 'path' => 'x.txt', 'contents' => '']);
        $this->assertSame('', $a['contents']);   // empty is valid; only a MISSING contents key is refused
    }

    // ----- normalizeAction: module ------------------------------------------

    #[Test]
    public function moduleSlugIsNormalizedToTheGeneratorGrammar(): void
    {
        $this->assertNull(Tiger_Agent_Contract::normalizeAction(['type' => 'module']));                // no name
        $this->assertNull(Tiger_Agent_Contract::normalizeAction(['type' => 'module', 'name' => '123'])); // becomes empty after strip

        $a = Tiger_Agent_Contract::normalizeAction(['type' => 'module', 'name' => 'Book-Store 2']);
        $this->assertSame('bookstore2', $a['name']);   // lowercased, hyphen/space stripped (trailing digit kept)

        // A leading digit run is stripped (a slug must start with a letter).
        $this->assertSame('books', Tiger_Agent_Contract::normalizeAction(['type' => 'module', 'name' => '42books'])['name']);
    }

    // ----- normalizeAction: read.* ------------------------------------------

    #[Test]
    public function readInventoryAndTreeAndGuideNormalize(): void
    {
        $this->assertSame(['type' => 'read.inventory', 'reason' => ''], Tiger_Agent_Contract::normalizeAction(['type' => 'read.inventory']));

        $tree = Tiger_Agent_Contract::normalizeAction(['type' => 'read.tree', 'path' => 'a/b']);
        $this->assertSame('a/b', $tree['path']);

        // read.guide sanitizes the module slug and is valid even with no module (platform conventions).
        $guide = Tiger_Agent_Contract::normalizeAction(['type' => 'read.guide', 'module' => 'C-M/S']);
        $this->assertSame('cms', $guide['module']);
        $this->assertSame('', Tiger_Agent_Contract::normalizeAction(['type' => 'read.guide'])['module']);
    }

    #[Test]
    public function readFileAndGrepRequireTheirCoreField(): void
    {
        $this->assertNull(Tiger_Agent_Contract::normalizeAction(['type' => 'read.file']));
        $this->assertSame('a.php', Tiger_Agent_Contract::normalizeAction(['type' => 'read.file', 'path' => 'a.php'])['path']);

        $this->assertNull(Tiger_Agent_Contract::normalizeAction(['type' => 'read.grep']));
        $grep = Tiger_Agent_Contract::normalizeAction(['type' => 'read.grep', 'query' => 'foo', 'path' => 'lib']);
        $this->assertSame('foo', $grep['query']);
        $this->assertSame('lib', $grep['path']);
    }

    // ----- normalizeAction: dom.* -------------------------------------------

    #[Test]
    public function domReadRequiresTargetAndDomWriteRequiresTargetPlusValue(): void
    {
        $this->assertNull(Tiger_Agent_Contract::normalizeAction(['type' => 'dom.read']));
        $this->assertSame('body', Tiger_Agent_Contract::normalizeAction(['type' => 'dom.read', 'target' => 'body'])['target']);

        $this->assertNull(Tiger_Agent_Contract::normalizeAction(['type' => 'dom.write', 'target' => 'body']));   // no value
        $w = Tiger_Agent_Contract::normalizeAction(['type' => 'dom.write', 'target' => 'body', 'value' => '<b>x</b>', 'kind' => 'html']);
        $this->assertSame('<b>x</b>', $w['value']);
        $this->assertSame('html', $w['kind']);
    }
}
