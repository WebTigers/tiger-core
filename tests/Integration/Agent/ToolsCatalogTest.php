<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Agent;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Agent_Tools;

/**
 * Tiger_Agent_Tools::catalog — the model's tool catalog is not a hand-kept list: it IS the live,
 * role-filtered /api surface, discovered by reflection (Tiger_OpenApi_Generator) and filtered
 * through the REAL ACL. This drives the reflection + ACL-filter path that the pure prompt-assembly
 * unit test can't reach (that one runs with no ACL, so catalog() short-circuits to empty).
 */
#[CoversClass(Tiger_Agent_Tools::class)]
final class ToolsCatalogTest extends IntegrationTestCase
{
    #[Test]
    public function catalogReflectsTheRoleFilteredApiSurface(): void
    {
        $this->loginAs('developer');
        $catalog = Tiger_Agent_Tools::catalog('developer');

        $this->assertIsArray($catalog);
        // The agent never advertises its own plumbing service surface.
        $this->assertArrayNotHasKey('agent', $catalog);

        // Each group is a list of {service, method, summary} rows.
        foreach ($catalog as $module => $ops) {
            $this->assertIsString($module);
            $this->assertNotEmpty($ops);
            $this->assertArrayHasKey('service', $ops[0]);
            $this->assertArrayHasKey('method', $ops[0]);
            $this->assertArrayHasKey('summary', $ops[0]);
        }
    }

    #[Test]
    public function aGuestSeesAtMostWhatADeveloperSees(): void
    {
        $this->loginAs('developer');
        $devCount = $this->flatCount(Tiger_Agent_Tools::catalog('developer'));

        $this->loginAs('guest');
        $guestCount = $this->flatCount(Tiger_Agent_Tools::catalog('guest'));

        // Deny-by-default: the guest's callable surface is never larger than the developer's.
        $this->assertLessThanOrEqual($devCount, $guestCount);
    }

    #[Test]
    public function systemPromptOverTheLiveAclProducesAUsablePrompt(): void
    {
        $this->loginAs('developer');
        $prompt = Tiger_Agent_Tools::systemPrompt('developer',
            ['inventory' => true, 'read' => true, 'api' => true, 'file' => true, 'module' => true], ['path' => '/'], 'auto');

        $this->assertStringContainsString('You are TigerAgent', $prompt);
        $this->assertStringContainsString('CALLABLE /api CATALOG', $prompt);
        $this->assertStringContainsString('AUTO —', $prompt);
    }

    /** Total callable operations across all module groups. */
    private function flatCount(array $catalog): int
    {
        $n = 0;
        foreach ($catalog as $ops) { $n += count($ops); }
        return $n;
    }
}
