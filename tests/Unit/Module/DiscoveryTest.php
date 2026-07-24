<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\Module;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Module_Discovery;

/**
 * Tiger_Module_Discovery — the on-disk module scan that CAPABILITY-DETECTS what each dir is, rather
 * than trusting a declared type. The design rule (see the module-taxonomy note): `type` is a mere
 * search label; what a module actually IS follows from what it ships —
 *   - a Bootstrap.php / controllers/ dir  ⇒ a ROUTED module  (type "module"),
 *   - a theme.json                        ⇒ a THEME           (type "theme", resolved by path),
 *   - a module.json + a snippets/ dir     ⇒ a CODE module     (type "code").
 *
 * These tests drive the real all() scan against fixture module dirs planted under APPLICATION_PATH's
 * modules/ (the bootstrap points APPLICATION_PATH at an otherwise-empty fixture app, so our fixtures
 * are the only APP modules present) and assert: each capability is detected, the app dir wins a slug
 * collision with a first-party core module, and advisory requires/compat pass straight through from the
 * manifest. The pure JSON-parse of _manifest() is checked directly through a subclass exposer.
 */
#[CoversClass(Tiger_Module_Discovery::class)]
final class DiscoveryTest extends UnitTestCase
{
    /** @var string[] fixture module dirs we created under APPLICATION_PATH/modules (removed in tearDown). */
    private array $created = [];
    private string $tmp = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/tiger_discovery_' . getmypid() . '_' . bin2hex(random_bytes(4));
        @mkdir($this->tmp, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $dir) { $this->rrmdir($dir); }
        // Prune the fixture scaffolding we may have minted (rmdir only removes them if now empty, so a
        // real app dir is never touched) — keeps the working tree clean between runs.
        @rmdir(APPLICATION_PATH . '/modules');
        @rmdir(APPLICATION_PATH);
        @rmdir(dirname(APPLICATION_PATH));
        $this->rrmdir($this->tmp);
        parent::tearDown();
    }

    /** Plant a fixture module dir under the app modules root and register it for cleanup. */
    private function plantAppModule(string $slug, array $files): string
    {
        $dir = APPLICATION_PATH . '/modules/' . $slug;
        @mkdir($dir, 0775, true);
        foreach ($files as $rel => $contents) {
            $full = $dir . '/' . $rel;
            @mkdir(dirname($full), 0775, true);
            file_put_contents($full, $contents);
        }
        $this->created[] = $dir;
        return $dir;
    }

    #[Test]
    public function detectsARoutedModuleByItsBootstrap(): void
    {
        $this->plantAppModule('fixrouted', [
            'Bootstrap.php' => "<?php\n",
            'module.json'   => json_encode(['slug' => 'fixrouted', 'name' => 'Fix Routed', 'version' => '1.0.0']),
        ]);

        $all = Tiger_Module_Discovery::all();
        $this->assertArrayHasKey('fixrouted', $all);
        $this->assertSame('module', $all['fixrouted']['type']);
        $this->assertSame('app', $all['fixrouted']['area']);
        $this->assertSame('Fix Routed', $all['fixrouted']['name']);
        $this->assertSame('1.0.0', $all['fixrouted']['version']);
        $this->assertTrue($all['fixrouted']['has_manifest']);
    }

    #[Test]
    public function detectsARoutedModuleWithControllersButNoManifest(): void
    {
        // A controllers/ dir alone is enough to be a routed module — no module.json required.
        $this->plantAppModule('fixctrl', [
            'controllers/IndexController.php' => "<?php\n",
        ]);

        $all = Tiger_Module_Discovery::all();
        $this->assertArrayHasKey('fixctrl', $all);
        $this->assertSame('module', $all['fixctrl']['type']);
        $this->assertFalse($all['fixctrl']['has_manifest'], 'no manifest was shipped');
        $this->assertSame('Fixctrl', $all['fixctrl']['name'], 'name falls back to ucfirst(slug)');
    }

    #[Test]
    public function detectsAThemeByThemeJson(): void
    {
        $this->plantAppModule('theme-fixaurora', [
            'theme.json' => json_encode(['key' => 'fixaurora', 'name' => 'Fix Aurora', 'assetBase' => '/_fixaurora']),
        ]);

        $all = Tiger_Module_Discovery::all();
        $this->assertArrayHasKey('theme-fixaurora', $all);
        $this->assertSame('theme', $all['theme-fixaurora']['type']);
        $this->assertSame('fixaurora', $all['theme-fixaurora']['key'], 'the theme key drives tiger.theme resolution');
        $this->assertSame('/_fixaurora', $all['theme-fixaurora']['asset_base']);
    }

    #[Test]
    public function themeKeyFallsBackToTheSlugMinusThemePrefix(): void
    {
        // A theme.json with no explicit key: the key is derived from the dir name (theme-<key>).
        $this->plantAppModule('theme-fixlumen', [
            'theme.json' => json_encode(['name' => 'Fix Lumen']),
        ]);

        $all = Tiger_Module_Discovery::all();
        $this->assertArrayHasKey('theme-fixlumen', $all);
        $this->assertSame('theme', $all['theme-fixlumen']['type']);
        $this->assertSame('fixlumen', $all['theme-fixlumen']['key']);
    }

    #[Test]
    public function detectsACodeModuleBySnippetsDir(): void
    {
        $this->plantAppModule('fixcode', [
            'module.json'      => json_encode(['slug' => 'fixcode', 'name' => 'Fix Code']),
            'snippets/slug.php' => "<?php\n",
        ]);

        $all = Tiger_Module_Discovery::all();
        $this->assertArrayHasKey('fixcode', $all);
        $this->assertSame('code', $all['fixcode']['type'], 'a snippets/ dir makes it a code module');
    }

    #[Test]
    public function anExplicitManifestTypeOverridesTheDetectedDefault(): void
    {
        // `type` in the manifest wins over the capability default — it's just a label.
        $this->plantAppModule('fixlabeled', [
            'module.json' => json_encode(['slug' => 'fixlabeled', 'name' => 'Fix Labeled', 'type' => 'plugin']),
            'Bootstrap.php' => "<?php\n",
        ]);

        $all = Tiger_Module_Discovery::all();
        $this->assertSame('plugin', $all['fixlabeled']['type']);
    }

    #[Test]
    public function appDirWinsOnASlugCollisionWithACoreModule(): void
    {
        // `agent` is a real first-party core module; an app module of the same slug must shadow it.
        $this->assertTrue(is_dir(TIGER_CORE_PATH . '/modules/agent'), 'precondition: a core "agent" module exists');
        $this->plantAppModule('agent', [
            'module.json' => json_encode(['slug' => 'agent', 'name' => 'App Agent Override']),
        ]);

        $all = Tiger_Module_Discovery::all();
        $this->assertArrayHasKey('agent', $all);
        $this->assertSame('app', $all['agent']['area'], 'the app dir must win the collision');
        $this->assertSame('App Agent Override', $all['agent']['name']);
    }

    #[Test]
    public function requiresAndCompatPassThroughFromTheManifest(): void
    {
        // Advisory compatibility metadata is carried verbatim for Tiger_Module_Compat to interpret later.
        $this->plantAppModule('fixcompat', [
            'module.json' => json_encode([
                'slug' => 'fixcompat', 'name' => 'Fix Compat',
                'requires' => ['tiger' => '0.40.0-beta', 'php' => '>=8.1'],
                'compat'   => ['tiger' => ['min' => '0.36.0-beta', 'max' => '0.40.0-beta']],
            ]),
            'Bootstrap.php' => "<?php\n",
        ]);

        $all = Tiger_Module_Discovery::all();
        $row = $all['fixcompat'];
        $this->assertSame(['tiger' => '0.40.0-beta', 'php' => '>=8.1'], $row['requires']);
        $this->assertSame(['tiger' => ['min' => '0.36.0-beta', 'max' => '0.40.0-beta']], $row['compat']);
    }

    #[Test]
    public function aBareDirWithNoModuleSignalsIsSkipped(): void
    {
        // A dir that is neither routed, a theme, nor carries a module.json is not a module at all.
        $this->plantAppModule('fixjunk', ['README.md' => "not a module\n"]);

        $all = Tiger_Module_Discovery::all();
        $this->assertArrayNotHasKey('fixjunk', $all);
    }

    // ---- _manifest (pure JSON parse) -------------------------------------------

    #[Test]
    public function manifestParsesValidJsonAndToleratesGarbage(): void
    {
        // Valid module.json → the decoded array.
        $good = $this->tmp . '/good';
        @mkdir($good, 0775, true);
        file_put_contents($good . '/module.json', json_encode(['slug' => 'x', 'name' => 'X']));
        $this->assertSame(['slug' => 'x', 'name' => 'X'], DiscoveryProbe::manifest($good));

        // module.json is preferred over a same-dir theme.json.
        $both = $this->tmp . '/both';
        @mkdir($both, 0775, true);
        file_put_contents($both . '/module.json', json_encode(['slug' => 'm']));
        file_put_contents($both . '/theme.json', json_encode(['key' => 't']));
        $this->assertSame(['slug' => 'm'], DiscoveryProbe::manifest($both));

        // Malformed JSON degrades to [] (never a fatal), and a manifest-less dir returns [].
        $broken = $this->tmp . '/broken';
        @mkdir($broken, 0775, true);
        file_put_contents($broken . '/module.json', '{ not json ');
        $this->assertSame([], DiscoveryProbe::manifest($broken));

        $bare = $this->tmp . '/bare';
        @mkdir($bare, 0775, true);
        $this->assertSame([], DiscoveryProbe::manifest($bare));
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) { return; }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') { continue; }
            $p = $dir . '/' . $item;
            (is_dir($p) && !is_link($p)) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}

/** Test seam: expose Discovery's protected manifest reader. */
final class DiscoveryProbe extends Tiger_Module_Discovery
{
    public static function manifest(string $dir): array
    {
        return self::_manifest($dir);
    }
}
