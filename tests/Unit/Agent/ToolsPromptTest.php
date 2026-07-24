<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\Agent;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Agent_Tools;

/**
 * Tiger_Agent_Tools::systemPrompt — assembles the model's system prompt from the acting role, its
 * capability tiers, the request context, and the auto-mode. With no ACL registered, catalog() is
 * empty (so the prompt is dependency-free to build), which lets us assert every conditional block —
 * the capability line, the read-tool block, the DOM-targets block, the a11y block, and the mode
 * line — is emitted exactly when its capability is on. (catalog() over a live ACL is covered by the
 * integration test.)
 */
#[CoversClass(Tiger_Agent_Tools::class)]
final class ToolsPromptTest extends UnitTestCase
{
    #[Test]
    public function withNoAclTheCatalogIsEmptyAndTheRoleIsStatedInThePrompt(): void
    {
        $prompt = Tiger_Agent_Tools::systemPrompt('manager', [], [], 'ask');
        $this->assertStringContainsString('role is', $prompt);
        $this->assertStringContainsString('"manager"', $prompt);
        $this->assertStringContainsString('(no callable services for this role)', $prompt);
        $this->assertStringContainsString('answer questions and guide the user', $prompt);   // no caps → default line
    }

    #[Test]
    public function capabilitiesLineListsEachUnlockedTier(): void
    {
        $prompt = Tiger_Agent_Tools::systemPrompt('developer', [
            'inventory' => true, 'read' => true, 'dom' => true, 'api' => true,
            'code' => true, 'file' => true, 'module' => true,
        ], [], 'ask');
        $this->assertStringContainsString('inspect the system (inventory)', $prompt);
        $this->assertStringContainsString('call /api services', $prompt);
        $this->assertStringContainsString('author executable PHP snippets', $prompt);
        $this->assertStringContainsString('scaffold new modules', $prompt);
    }

    #[Test]
    public function readToolBlockAppearsOnlyWithTheReadCapability(): void
    {
        $without = Tiger_Agent_Tools::systemPrompt('manager', ['api' => true], [], 'ask');
        $this->assertStringNotContainsString('LOOK BEFORE YOU LEAP', $without);

        $with = Tiger_Agent_Tools::systemPrompt('superadmin', ['read' => true, 'inventory' => true], [], 'ask');
        $this->assertStringContainsString('LOOK BEFORE YOU LEAP', $with);
        $this->assertStringContainsString('read.inventory', $with);
        $this->assertStringContainsString('read.grep', $with);
    }

    #[Test]
    public function domBlockIsAdvertisedOnlyWhenThePageDeclaresTargets(): void
    {
        // dom cap but no targets → no block
        $noTargets = Tiger_Agent_Tools::systemPrompt('manager', ['dom' => true], [], 'ask');
        $this->assertStringNotContainsString('EDITABLE TARGETS', $noTargets);

        $withTargets = Tiger_Agent_Tools::systemPrompt('manager', ['dom' => true], [
            'targets' => [
                ['name' => 'articleBody', 'kind' => 'html', 'label' => 'Article body'],
                ['name' => 'headline', 'kind' => 'text'],
                ['bogus' => 'no name — skipped'],
            ],
        ], 'ask');
        $this->assertStringContainsString('EDITABLE TARGETS', $withTargets);
        $this->assertStringContainsString('articleBody [html] Article body', $withTargets);
        $this->assertStringContainsString('headline [text]', $withTargets);
    }

    #[Test]
    public function a11yBlockAppearsOnlyWithTheFileCapability(): void
    {
        $this->assertStringNotContainsString('ACCESSIBILITY (TigerAlly)', Tiger_Agent_Tools::systemPrompt('manager', ['api' => true], [], 'ask'));
        $this->assertStringContainsString('ACCESSIBILITY (TigerAlly)', Tiger_Agent_Tools::systemPrompt('superadmin', ['file' => true], [], 'ask'));
    }

    #[Test]
    public function eachModeEmitsItsOwnModeLineAndAnUnknownModeFallsBackToAsk(): void
    {
        $this->assertStringContainsString('ASK —', Tiger_Agent_Tools::systemPrompt('manager', [], [], 'ask'));
        $this->assertStringContainsString('AUTO —', Tiger_Agent_Tools::systemPrompt('manager', [], [], 'auto'));
        $this->assertStringContainsString('YOLO —', Tiger_Agent_Tools::systemPrompt('manager', [], [], 'yolo'));
        $this->assertStringContainsString('ASK —', Tiger_Agent_Tools::systemPrompt('manager', [], [], 'nonsense'));
    }

    #[Test]
    public function theCurrentPagePathIsWovenIntoThePrompt(): void
    {
        $prompt = Tiger_Agent_Tools::systemPrompt('manager', ['api' => true], ['path' => '/cms/admin/pages'], 'ask');
        $this->assertStringContainsString('/cms/admin/pages', $prompt);
    }
}
