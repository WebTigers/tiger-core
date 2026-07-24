<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Profile;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Profile_Service_Avatar;
use Tiger\Tests\Support\IntegrationTestCase;
use Tiger_Model_Option;
use Tiger_Model_User;
use Zend_Registry;

/**
 * Profile_Service_Avatar — self-service avatar upload/clear (/api).
 *
 * Wave-4 coverage of the reachable surface: the self-scope gate (a guest is refused on both actions),
 * the upload-guard rejections (no file present → error_upload), and remove() which forgets the
 * per-user `tiger.user.avatar` option (leaving the media row in place).
 *
 * NOTE: the upload HAPPY path is gated by is_uploaded_file() (a real multipart upload), which cannot
 * be satisfied from CLI/PHPUnit — see WAVE4-FINDINGS-profile.md. So the store→media→option-link body
 * is exercised in the browser, not here.
 */
#[CoversClass(Profile_Service_Avatar::class)]
final class AvatarServiceTest extends IntegrationTestCase
{
    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();
        Zend_Registry::set('tiger.auth.stateless', true);
        $this->userId = (new Tiger_Model_User())->insert(['email' => 'avatar@w4test.com', 'status' => 'active']);
        $this->login($this->userId, 'org-test', 'user');
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
        return (new Profile_Service_Avatar(['action' => $action] + $params))->getResponse();
    }

    #[Test]
    public function a_guest_is_refused_on_upload_and_remove(): void
    {
        $this->logout();
        $this->assertStringContainsString('not_allowed', json_encode($this->call('upload')->messages));
        $this->assertStringContainsString('not_allowed', json_encode($this->call('remove')->messages));
    }

    #[Test]
    public function an_upload_with_no_file_is_rejected(): void
    {
        unset($_FILES['file']);
        $res = $this->call('upload');
        $this->assertSame(0, (int) $res->result);
        $this->assertStringContainsString('avatar.error_upload', json_encode($res->messages));
    }

    #[Test]
    public function an_upload_with_a_non_uploaded_tmp_file_is_rejected(): void
    {
        // A tmp_name that isn't a genuine multipart upload → is_uploaded_file() fails → error_upload.
        $_FILES['file'] = ['name' => 'a.png', 'type' => 'image/png', 'tmp_name' => '/tmp/not-an-upload', 'error' => UPLOAD_ERR_OK, 'size' => 10];
        $res = $this->call('upload');
        $this->assertSame(0, (int) $res->result);
        $this->assertStringContainsString('avatar.error_upload', json_encode($res->messages));
    }

    #[Test]
    public function remove_forgets_the_avatar_option(): void
    {
        // Seed an avatar option, then clear it.
        $opt = new Tiger_Model_Option();
        $opt->set(Tiger_Model_Option::SCOPE_USER, $this->userId, Profile_Service_Avatar::OPTION_KEY, 'media-xyz');
        $this->assertSame('media-xyz', $opt->get(Tiger_Model_Option::SCOPE_USER, $this->userId, Profile_Service_Avatar::OPTION_KEY));

        $res = $this->call('remove');
        $this->assertSame(1, (int) $res->result);
        $this->assertStringContainsString('avatar.removed', json_encode($res->messages));
        $this->assertNull(
            $opt->get(Tiger_Model_Option::SCOPE_USER, $this->userId, Profile_Service_Avatar::OPTION_KEY),
            'the avatar option is forgotten'
        );
    }
}
