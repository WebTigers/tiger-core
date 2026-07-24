<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Identity;

use Identity_Service_Identity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_Config;
use Zend_Registry;

/**
 * Identity_Service_Identity — the /api service behind the Site Identity screen. It validates the form,
 * then writes the site name/tagline, the logo + favicon media references, and the social URLs to the
 * GLOBAL config tier inside a transaction. Wave-4 coverage: the ACL gate, the validate-and-persist happy
 * path (every KEYS entry + logo/favicon land in `config`), the media-id sanitizer (a UUID is kept, junk
 * clears to ''), and the reject paths (missing required name, malformed social URL) that write nothing.
 */
#[CoversClass(Identity_Service_Identity::class)]
final class IdentityServiceTest extends IntegrationTestCase
{
    private const UUID = '0191aabb-ccdd-7eff-8899-aabbccddeeff';

    protected function setUp(): void
    {
        parent::setUp();
        Zend_Registry::set('tiger.auth.stateless', true);
    }

    protected function tearDown(): void
    {
        $reg = Zend_Registry::getInstance();
        if ($reg->offsetExists('tiger.auth.stateless')) { $reg->offsetUnset('tiger.auth.stateless'); }
        parent::tearDown();
    }

    private function call(array $params = []): object
    {
        return (new Identity_Service_Identity(['action' => 'save'] + $params))->getResponse();
    }

    private function cfg(string $key): ?string
    {
        return (new Tiger_Model_Config())->get(Tiger_Model_Config::SCOPE_GLOBAL, '', $key);
    }

    // ----- ACL ----------------------------------------------------------------------------------

    #[Test]
    public function guest_is_denied(): void
    {
        $this->login('anon', 'org-test', 'guest');
        $res = $this->call(['site_name' => 'Acme']);
        $this->assertSame(0, (int) $res->result);
        $this->assertStringContainsString('not_allowed', json_encode($res->messages));
    }

    #[Test]
    public function a_plain_user_is_denied(): void
    {
        $this->loginAs('user');
        $res = $this->call(['site_name' => 'Acme']);
        $this->assertSame(0, (int) $res->result);
        $this->assertStringContainsString('not_allowed', json_encode($res->messages));
    }

    // ----- save ---------------------------------------------------------------------------------

    #[Test]
    public function save_persists_name_tagline_and_social_urls(): void
    {
        $this->loginAs('admin');
        $res = $this->call([
            'site_name'       => '  Acme Books  ',
            'tagline'         => 'We publish',
            'social_twitter'  => 'https://twitter.com/acme',
            'social_github'   => 'https://github.com/acme',
        ]);

        $this->assertSame(1, (int) $res->result);
        $this->assertSame('Acme Books', $this->cfg('tiger.site.name'), 'trimmed on save');
        $this->assertSame('We publish', $this->cfg('tiger.site.tagline'));
        $this->assertSame('https://twitter.com/acme', $this->cfg('tiger.seo.social.twitter'));
        $this->assertSame('https://github.com/acme', $this->cfg('tiger.seo.social.github'));
        // Unset social keys still get written (as '') — every KEYS entry is persisted.
        $this->assertSame('', $this->cfg('tiger.seo.social.facebook'));
    }

    #[Test]
    public function save_keeps_a_valid_media_uuid_and_clears_junk(): void
    {
        $this->loginAs('admin');
        $res = $this->call([
            'site_name'        => 'Acme',
            'logo_media_id'    => self::UUID,
            'favicon_media_id' => 'not-a-uuid',
        ]);

        $this->assertSame(1, (int) $res->result);
        $this->assertSame(self::UUID, $this->cfg('tiger.site.logo'), 'a valid UUID is kept');
        $this->assertSame('', $this->cfg('tiger.site.favicon'), 'a non-UUID clears the reference');
    }

    #[Test]
    public function save_defaults_media_references_to_empty(): void
    {
        $this->loginAs('admin');
        $res = $this->call(['site_name' => 'Acme']);   // no media ids posted
        $this->assertSame(1, (int) $res->result);
        $this->assertSame('', $this->cfg('tiger.site.logo'));
        $this->assertSame('', $this->cfg('tiger.site.favicon'));
    }

    // ----- rejects ------------------------------------------------------------------------------

    #[Test]
    public function a_missing_site_name_returns_form_errors_and_writes_nothing(): void
    {
        $this->loginAs('admin');
        $before = (int) $this->db->fetchOne('SELECT COUNT(*) FROM config');

        $res = $this->call(['site_name' => '', 'tagline' => 'x']);   // name is required

        $this->assertSame(0, (int) $res->result);
        $this->assertNotNull($res->form);
        $this->assertArrayHasKey('site_name', $res->form);
        $this->assertSame($before, (int) $this->db->fetchOne('SELECT COUNT(*) FROM config'), 'nothing written');
    }

    #[Test]
    public function a_malformed_social_url_is_rejected(): void
    {
        $this->loginAs('admin');
        // Present but not http(s):// → the lenient URL regex rejects it.
        $res = $this->call(['site_name' => 'Acme', 'social_twitter' => 'ftp://nope']);
        $this->assertSame(0, (int) $res->result);
        $this->assertNotNull($res->form);
        $this->assertArrayHasKey('social_twitter', $res->form);
    }
}
