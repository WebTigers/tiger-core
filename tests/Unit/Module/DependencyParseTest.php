<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\Module;

use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Module_Dependency;

/** Exposes the protected versioned-requirements parser against a fixture dir. */
final class ExposedDependency extends Tiger_Module_Dependency
{
    public static function parse($dir): array { return static::_readRequirements($dir); }
}

/**
 * Tiger_Module_Dependency — the versioned inter-module requirement parser: a bare slug, or
 * "slug <constraint>" (space / @ / : separated), read from configs/dependency.ini. Deduped by slug,
 * lowercased. (The missing/dependents graph logic is integration — it reads live discovery + state.)
 */
final class DependencyParseTest extends UnitTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/tgr-dep-' . substr(md5(static::class), 0, 8);
        @mkdir($this->dir . '/configs', 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/configs/dependency.ini');
        @rmdir($this->dir . '/configs');
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function writeIni(string $body): void
    {
        file_put_contents($this->dir . '/configs/dependency.ini', $body);
    }

    #[Test]
    public function bareSlugsAndVersionedEntriesParseWithSeparators(): void
    {
        $this->writeIni(
            "[requires]\n" .
            "modules[] = \"account\"\n" .
            "modules[] = \"billing >=0.5.0-beta\"\n" .
            "modules[] = \"pay@^1.0\"\n" .
            "modules[] = \"shop:>=2\"\n"
        );
        $reqs = ExposedDependency::parse($this->dir);
        $this->assertSame(
            [
                ['slug' => 'account', 'constraint' => ''],
                ['slug' => 'billing', 'constraint' => '>=0.5.0-beta'],
                ['slug' => 'pay',     'constraint' => '^1.0'],
                ['slug' => 'shop',    'constraint' => '>=2'],
            ],
            $reqs
        );
    }

    #[Test]
    public function slugsAreLowercasedAndDedupedBySlug(): void
    {
        $this->writeIni("[requires]\nmodules[] = \"Billing >=0.5\"\nmodules[] = \"billing >=0.9\"\n");
        $reqs = ExposedDependency::parse($this->dir);
        $this->assertCount(1, $reqs);                         // deduped by slug — first wins
        $this->assertSame('billing', $reqs[0]['slug']);
        $this->assertSame('>=0.5', $reqs[0]['constraint']);
    }

    #[Test]
    public function noFileOrNoRequiresIsEmpty(): void
    {
        $this->assertSame([], ExposedDependency::parse($this->dir));   // no dependency.ini
        $this->writeIni("[requires]\n");
        $this->assertSame([], ExposedDependency::parse($this->dir));   // present but no modules[]
    }
}
