<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\Location;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Location_Adapter_Abstract;
use Tiger_Location_Adapter_Interface;
use Tiger_Location_Exception;
use Tiger_Test_BareAdapter;

require_once __DIR__ . '/../../Support/Fixtures/LocationDoubles.php';

/**
 * Tiger_Location_Adapter_Abstract — the base every provider extends.
 *
 * Its contract: every operation defaults to "unsupported" (throws Tiger_Location_Exception) so a
 * concrete adapter overrides only what it can do; capabilities() defaults empty; supports() is
 * membership in capabilities(); label() defaults to the class-name suffix; and _cfg() reads the
 * adapter's own config slice with a default. We drive it through a do-nothing concrete subclass
 * (Tiger_Test_BareAdapter) — the network helper (_getJson) is the one live boundary and isn't unit-tested.
 */
#[CoversClass(Tiger_Location_Adapter_Abstract::class)]
final class AdapterAbstractTest extends UnitTestCase
{
    #[Test]
    public function capabilities_and_supports_default_to_nothing(): void
    {
        $a = new Tiger_Test_BareAdapter();
        $this->assertSame([], $a->capabilities());
        $this->assertFalse($a->supports(Tiger_Location_Adapter_Interface::CAP_SUGGEST));
        $this->assertSame([], $a->fields());
    }

    #[Test]
    public function label_defaults_to_the_class_name_suffix(): void
    {
        $this->assertSame('BareAdapter', (new Tiger_Test_BareAdapter())->label());
    }

    #[Test]
    public function cfg_reads_the_config_slice_with_a_default(): void
    {
        $a = new Tiger_Test_BareAdapter(['endpoint' => 'https://x', 'zero' => 0]);
        $m = new ReflectionMethod($a, '_cfg');
        $this->assertSame('https://x', $m->invoke($a, 'endpoint'));
        $this->assertSame(0, $m->invoke($a, 'zero', 'fallback'), 'a present-but-falsy value is returned, not the default');
        $this->assertSame('fallback', $m->invoke($a, 'missing', 'fallback'));
        $this->assertNull($m->invoke($a, 'missing'));
    }

    #[Test]
    public function every_operation_is_unsupported_by_default(): void
    {
        $a = new Tiger_Test_BareAdapter();

        foreach (['suggest' => ['q'], 'geocode' => ['q'], 'reverse' => [1.0, 2.0], 'ip' => ['8.8.8.8']] as $op => $args) {
            try {
                $a->$op(...$args);
                $this->fail("$op should throw when unsupported");
            } catch (Tiger_Location_Exception $e) {
                $this->assertStringContainsString('does not support', $e->getMessage());
                $this->assertStringContainsString($op, $e->getMessage());
            }
        }
    }
}
