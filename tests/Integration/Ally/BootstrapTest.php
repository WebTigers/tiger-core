<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Ally;

use Ally_Bootstrap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use ReflectionProperty;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Admin_Nav;

/**
 * Ally_Bootstrap — registers the top-level "Accessibility" admin sidebar item. The hook touches only
 * the process-wide Tiger_Admin_Nav registry (no $this state), so it's invoked on a constructor-less
 * instance via reflection and the registration effect asserted.
 */
#[CoversClass(Ally_Bootstrap::class)]
final class BootstrapTest extends IntegrationTestCase
{
    public static function setUpBeforeClass(): void
    {
        // The bare `Ally_Bootstrap` isn't a typed module class (the harness autoloader only resolves
        // controllers/services/forms/models/plugins), so require it directly.
        if (!class_exists(Ally_Bootstrap::class, false)) {
            require_once TIGER_CORE_PATH . '/modules/ally/Bootstrap.php';
        }
    }

    protected function tearDown(): void
    {
        Tiger_Admin_Nav::clear();
        parent::tearDown();
    }

    /** The registered nav items keyed by 'key', read straight from the registry (no ACL filtering). */
    private function registered(): array
    {
        $p = new ReflectionProperty(Tiger_Admin_Nav::class, '_items');
        $p->setAccessible(true);
        $items = [];
        foreach ((array) $p->getValue() as $item) {
            $items[$item['key'] ?? ''] = $item;
        }
        return $items;
    }

    #[Test]
    public function it_registers_the_accessibility_sidebar_item(): void
    {
        Tiger_Admin_Nav::clear();

        $bootstrap = (new \ReflectionClass(Ally_Bootstrap::class))->newInstanceWithoutConstructor();
        $m = new ReflectionMethod(Ally_Bootstrap::class, '_initAdminNav');
        $m->setAccessible(true);
        $m->invoke($bootstrap);

        $items = $this->registered();
        $this->assertArrayHasKey('ally', $items, 'the Accessibility nav item is registered');
        $this->assertSame('Accessibility', $items['ally']['label']);
        $this->assertSame('/ally', $items['ally']['href']);
        $this->assertSame('Ally_IndexController', $items['ally']['resource'], 'ACL-gated to the controller');
    }
}
