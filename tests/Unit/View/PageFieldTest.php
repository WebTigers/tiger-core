<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\View;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_View_Helper_PageField;

/**
 * Tiger_View_Helper_PageField — read a custom-field value (Tiger_Fields) off a page on the front end:
 * `<?= $this->escape($this->pageField($page, 'listing.price')) ?>`. It's the render half of custom
 * fields — the editor writes `page.meta.fields.<group>.<field>`, this reads it back raw (escape at
 * output). A pure delegator over Tiger_Fields::value, so it needs no DB: a plain page array with a
 * `meta` JSON string (or array) is enough.
 */
#[CoversClass(Tiger_View_Helper_PageField::class)]
final class PageFieldTest extends UnitTestCase
{
    private function helper(): Tiger_View_Helper_PageField
    {
        return new Tiger_View_Helper_PageField();
    }

    private function page(array $fields): array
    {
        return ['page_id' => 'p1', 'meta' => json_encode(['fields' => $fields])];
    }

    #[Test]
    public function it_reads_a_group_dot_field_value_from_a_page(): void
    {
        $page = $this->page(['listing' => ['price' => '9.99', 'featured' => '1']]);
        $this->assertSame('9.99', $this->helper()->pageField($page, 'listing.price'));
        $this->assertSame('1', $this->helper()->pageField($page, 'listing.featured'));
    }

    #[Test]
    public function it_returns_the_default_for_a_missing_field(): void
    {
        $page = $this->page(['listing' => ['price' => '9.99']]);
        $this->assertSame('', $this->helper()->pageField($page, 'listing.subtitle'));
        $this->assertSame('n/a', $this->helper()->pageField($page, 'listing.subtitle', 'n/a'));
    }

    #[Test]
    public function it_returns_the_default_for_a_missing_group(): void
    {
        $page = $this->page(['listing' => ['price' => '9.99']]);
        $this->assertNull($this->helper()->pageField($page, 'seo.title', null));
    }

    #[Test]
    public function it_reads_from_a_page_with_no_meta_at_all(): void
    {
        $this->assertSame('fallback', $this->helper()->pageField(['page_id' => 'p1'], 'listing.price', 'fallback'));
    }
}
