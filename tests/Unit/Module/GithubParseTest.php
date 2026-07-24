<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Unit\Module;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\UnitTestCase;
use Tiger_Module_Github;

/**
 * Tiger_Module_Github — the pure, network-free surface: parseRepo (every URL/slug shape it must accept or
 * reject) and tarballUrl (the codeload archive URL it builds, ref-encoded). The HTTP methods (fetchRaw /
 * latestRef / download / get / _http) are live-network territory and are exercised only in integration.
 */
#[CoversClass(Tiger_Module_Github::class)]
final class GithubParseTest extends UnitTestCase
{
    #[Test]
    public function parse_repo_accepts_an_owner_slash_repo_slug(): void
    {
        $this->assertSame(['org' => 'WebTigers', 'repo' => 'tiger-core'], Tiger_Module_Github::parseRepo('WebTigers/tiger-core'));
    }

    #[Test]
    public function parse_repo_accepts_full_https_urls_with_and_without_git_suffix(): void
    {
        $this->assertSame(['org' => 'acme', 'repo' => 'widget'], Tiger_Module_Github::parseRepo('https://github.com/acme/widget'));
        $this->assertSame(['org' => 'acme', 'repo' => 'widget'], Tiger_Module_Github::parseRepo('https://github.com/acme/widget.git'));
        $this->assertSame(['org' => 'acme', 'repo' => 'widget'], Tiger_Module_Github::parseRepo('https://github.com/acme/widget/tree/main'));
        $this->assertSame(['org' => 'acme', 'repo' => 'widget'], Tiger_Module_Github::parseRepo('git@github.com:acme/widget.git'));
    }

    #[Test]
    public function parse_repo_returns_null_for_garbage(): void
    {
        $this->assertNull(Tiger_Module_Github::parseRepo(''));
        $this->assertNull(Tiger_Module_Github::parseRepo('not a repo'));
        $this->assertNull(Tiger_Module_Github::parseRepo('https://example.com/foo/bar'));
    }

    #[Test]
    public function tarball_url_builds_a_ref_encoded_codeload_archive_url(): void
    {
        $this->assertSame(
            'https://github.com/acme/widget/archive/v1.2.0.tar.gz',
            Tiger_Module_Github::tarballUrl('acme', 'widget', 'v1.2.0')
        );
        // A ref with a slash (a branch like release/1.x) is percent-encoded.
        $this->assertSame(
            'https://github.com/acme/widget/archive/release%2F1.x.tar.gz',
            Tiger_Module_Github::tarballUrl('acme', 'widget', 'release/1.x')
        );
    }
}
