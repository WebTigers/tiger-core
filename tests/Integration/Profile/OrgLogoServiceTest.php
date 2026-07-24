<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Profile;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Profile_Service_OrgLogo;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_Option;
use Zend_Registry;

/**
 * Profile_Service_OrgLogo — admin-gated org logo upload/clear (/api).
 *
 * The org twin of Profile_Service_Avatar. Wave-4 coverage of the reachable surface: the admin gate
 * (guest + plain user refused on both actions), the upload-guard rejection (no file → error_upload),
 * and remove() which forgets the per-org `tiger.org.logo` option.
 *
 * NOTE: the upload HAPPY path is gated by is_uploaded_file() and is not reachable from CLI/PHPUnit —
 * see WAVE4-FINDINGS-profile.md.
 */
#[CoversClass(Profile_Service_OrgLogo::class)]
final class OrgLogoServiceTest extends IntegrationTestCase
{
    private string $orgId = 'org-w4-logo';

    protected function setUp(): void
    {
        parent::setUp();
        Zend_Registry::set('tiger.auth.stateless', true);
        $this->login('admin-actor', $this->orgId, 'admin');
    }

    protected function tearDown(): void
    {
        unset($_FILES['file']);
        $reg = Zend_Registry::getInstance();
        if ($reg->offsetExists('tiger.auth.stateless')) { $reg->offsetUnset('tiger.auth.stateless'); }
        parent::tearDown();
    }

    private function call(string $action, array $params = []): object
    {
        return (new Profile_Service_OrgLogo(['action' => $action] + $params))->getResponse();
    }

    #[Test]
    public function guest_and_plain_user_are_denied_on_upload_and_remove(): void
    {
        $this->login('anon', $this->orgId, 'guest');
        $this->assertStringContainsString('not_allowed', json_encode($this->call('upload')->messages));
        $this->assertStringContainsString('not_allowed', json_encode($this->call('remove')->messages));

        $this->loginAs('user');
        $this->assertSame(0, (int) $this->call('upload')->result, 'a plain user is not an org admin');
        $this->assertSame(0, (int) $this->call('remove')->result);
    }

    #[Test]
    public function an_upload_with_no_file_is_rejected(): void
    {
        unset($_FILES['file']);
        $res = $this->call('upload');
        $this->assertSame(0, (int) $res->result);
        $this->assertStringContainsString('logo.error_upload', json_encode($res->messages));
    }

    #[Test]
    public function an_upload_with_a_non_uploaded_tmp_file_is_rejected(): void
    {
        $_FILES['file'] = ['name' => 'l.png', 'type' => 'image/png', 'tmp_name' => '/tmp/not-an-upload', 'error' => UPLOAD_ERR_OK, 'size' => 10];
        $res = $this->call('upload');
        $this->assertSame(0, (int) $res->result);
        $this->assertStringContainsString('logo.error_upload', json_encode($res->messages));
    }

    #[Test]
    public function remove_forgets_the_logo_option(): void
    {
        $opt = new Tiger_Model_Option();
        $opt->set(Tiger_Model_Option::SCOPE_ORG, $this->orgId, Profile_Service_OrgLogo::OPTION_KEY, 'media-logo-1');
        $this->assertSame('media-logo-1', $opt->get(Tiger_Model_Option::SCOPE_ORG, $this->orgId, Profile_Service_OrgLogo::OPTION_KEY));

        $res = $this->call('remove');
        $this->assertSame(1, (int) $res->result);
        $this->assertStringContainsString('logo.removed', json_encode($res->messages));
        $this->assertNull(
            $opt->get(Tiger_Model_Option::SCOPE_ORG, $this->orgId, Profile_Service_OrgLogo::OPTION_KEY),
            'the logo option is forgotten'
        );
    }
}
