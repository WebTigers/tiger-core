<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Media;

use Media_AdminController;
use Media_CallbackController;
use Media_FileController;
use Media_IndexController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\ModuleControllerTestCase;
use Tiger_Model_Media;

/**
 * The four Media controllers:
 *   - Index    — the Media Library shell (uploader + DataTables), computes the client-thumbnail flag.
 *   - Admin    — the settings screen (obfuscation form prefill).
 *   - File     — streams PRIVATE objects through an ACL/org guard; the miss + cross-org branches 404/403
 *                before the byte stream (the stream itself needs a real disk, out of harness scope).
 *   - Callback — the SNS moderation endpoint; an empty/invalid body is a clean 400 (the signed-payload
 *                branches need a real request body, out of harness scope).
 */
#[CoversClass(Media_IndexController::class)]
#[CoversClass(Media_AdminController::class)]
#[CoversClass(Media_FileController::class)]
#[CoversClass(Media_CallbackController::class)]
final class ControllersTest extends ModuleControllerTestCase
{
    #[Test]
    public function index_renders_the_library_shell_with_thumbnail_config(): void
    {
        $this->loginAs('admin');
        $this->dispatchAction(Media_IndexController::class, 'index', [], 'GET');

        $view = $this->controller()->view;
        $this->assertStringContainsString('Media Library', (string) $view->title);
        $this->assertTrue((bool) $view->useDataTables);
        $this->assertSame(200, (int) $view->thumbPx);
        $this->assertIsBool($view->clientThumb);
    }

    #[Test]
    public function admin_settings_prefills_the_obfuscation_form(): void
    {
        $this->loginAs('admin');
        $this->dispatchAction(Media_AdminController::class, 'settings', [], 'GET');

        $view = $this->controller()->view;
        $this->assertStringContainsString('Media Settings', (string) $view->title);
        $this->assertInstanceOf(\Media_Form_Settings::class, $view->form);
    }

    #[Test]
    public function file_serve_404s_for_a_missing_id(): void
    {
        $res = $this->dispatchAction(Media_FileController::class, 'serve', ['id' => ''], 'GET');
        $this->assertSame(404, $res->getHttpResponseCode());
    }

    #[Test]
    public function file_serve_403s_a_cross_org_private_object(): void
    {
        // A private object owned by org-a; a guest (no org) hits the org-scope guard → 403.
        $id = (new Tiger_Model_Media())->insert([
            'org_id'      => 'org-a',
            'disk'        => 'local',
            'storage_key' => 'org-a/documents/secret.txt',
            'visibility'  => Tiger_Model_Media::VISIBILITY_PRIVATE,
            'kind'        => Tiger_Model_Media::KIND_DOCUMENT,
            'mime_type'   => 'text/plain',
            'extension'   => 'txt',
            'file_size'   => 5,
            'filename'    => 'secret.txt',
            'title'       => 'Secret',
        ]);

        $res = $this->dispatchAction(Media_FileController::class, 'serve', ['id' => $id], 'GET');
        $this->assertSame(403, $res->getHttpResponseCode());
    }

    #[Test]
    public function callback_400s_an_empty_body(): void
    {
        // CLI has no php://input body → json_decode('') is null → the "bad request" guard.
        $res = $this->dispatchAction(Media_CallbackController::class, 'index', [], 'POST');
        $this->assertSame(400, $res->getHttpResponseCode());
        $this->assertStringContainsString('bad request', $res->getBody());
    }
}
