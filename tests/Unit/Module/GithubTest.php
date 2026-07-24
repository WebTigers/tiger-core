<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\Module;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Module_Github;

/**
 * Tiger_Module_Github — the public-repo cURL reader the installer uses (no git, no token). Two halves:
 *
 *   - PURE URL logic: parseRepo() (URL/slug → org+repo, the input an installer trusts to derive a target)
 *     and tarballUrl() (the codeload URL, with the ref rawurlencoded). parseRepo is a light security edge —
 *     a garbage or path-traversal-shaped input must parse to null, never a bogus org/repo.
 *   - The HTTP boundary (_http): exercised through its ERROR path against a dead local endpoint (connection
 *     refused, no real network), so download()/get() return their documented null/false without hitting
 *     GitHub. The success + JSON-parse bodies of fetchRaw()/latestRef() are genuine network calls and are
 *     left to integration/live coverage — pinning them here would mean hammering github.com from a unit run.
 */
#[CoversClass(Tiger_Module_Github::class)]
final class GithubTest extends UnitTestCase
{
    /** A closed local port — a connect here is refused instantly, so no test touches the real network. */
    private const DEAD = 'http://127.0.0.1:9';

    private string $tmp = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/tiger_gh_' . getmypid() . '_' . bin2hex(random_bytes(4));
        @mkdir($this->tmp, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmp);
        parent::tearDown();
    }

    // ---- parseRepo -------------------------------------------------------------

    #[Test]
    public function parsesTheManyShapesOfARepoReference(): void
    {
        $cases = [
            'https://github.com/WebTigers/TigerDocs'         => ['WebTigers', 'TigerDocs'],
            'https://github.com/WebTigers/TigerDocs.git'     => ['WebTigers', 'TigerDocs'],
            'https://github.com/WebTigers/TigerDocs/tree/main' => ['WebTigers', 'TigerDocs'],
            'http://github.com/acme/thing#readme'            => ['acme', 'thing'],
            'git@github.com:WebTigers/tiger-core.git'        => ['WebTigers', 'tiger-core'],
            'WebTigers/tiger-core'                           => ['WebTigers', 'tiger-core'],
            '  WebTigers/tiger-core  '                       => ['WebTigers', 'tiger-core'],   // trimmed
        ];
        foreach ($cases as $input => [$org, $repo]) {
            $r = Tiger_Module_Github::parseRepo($input);
            $this->assertNotNull($r, "should parse: {$input}");
            $this->assertSame($org, $r['org'], "org of {$input}");
            $this->assertSame($repo, $r['repo'], "repo of {$input}");
        }
    }

    #[Test]
    public function unrecognizedReferencesParseToNull(): void
    {
        foreach (['', 'not a url', 'https://gitlab.com/a/b', 'justonesegment', 'https://example.com/', 'a/b/c/d/e/f'] as $bad) {
            $this->assertNull(Tiger_Module_Github::parseRepo($bad), 'should not parse: ' . var_export($bad, true));
        }
    }

    // ---- tarballUrl ------------------------------------------------------------

    #[Test]
    public function tarballUrlBuildsCodeloadWithAnEncodedRef(): void
    {
        $this->assertSame(
            'https://github.com/WebTigers/TigerDocs/archive/v1.2.3-beta.tar.gz',
            Tiger_Module_Github::tarballUrl('WebTigers', 'TigerDocs', 'v1.2.3-beta')
        );
        // A ref with a slash (a branch like feature/x) is rawurlencoded so the URL stays well-formed.
        $this->assertSame(
            'https://github.com/o/r/archive/feature%2Fx.tar.gz',
            Tiger_Module_Github::tarballUrl('o', 'r', 'feature/x')
        );
    }

    // ---- the HTTP boundary (error path, no real network) -----------------------

    #[Test]
    public function getReturnsNullWhenTheEndpointIsUnreachable(): void
    {
        $this->assertNull(Tiger_Module_Github::get(self::DEAD . '/anything'));
    }

    #[Test]
    public function downloadReturnsFalseAndLeavesNoFileWhenTheUrlFails(): void
    {
        $dest = $this->tmp . '/out.bin';
        $this->assertFalse(Tiger_Module_Github::download(self::DEAD . '/pkg.tar.gz', $dest));
        // The failed-download temp file is cleaned up by _http (no truncated artifact left behind).
        $this->assertFileDoesNotExist($dest, 'a failed download must not leave a partial file');
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
