<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Identity;

use Identity_AdminController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tiger\Tests\Support\ModuleControllerTestCase;

/**
 * Identity_AdminController — the Site Identity screen (name, tagline, logo, favicon, social links). Thin:
 * index prefills the form from the live config and passes the two media references (logo/favicon) to the
 * view. The harness dispatches index (rendering off) and asserts the prefilled view model.
 */
#[CoversClass(Identity_AdminController::class)]
final class AdminControllerTest extends ModuleControllerTestCase
{
    #[Test]
    public function index_prefills_the_identity_form_from_config(): void
    {
        $this->tiger([
            'site' => ['name' => 'Acme Books', 'tagline' => 'We publish', 'logo' => 'media-logo', 'favicon' => 'media-fav'],
            'seo'  => ['social' => ['twitter' => 'acme', 'github' => 'acme-oss']],
        ]);
        $this->loginAs('admin');
        $this->dispatchAction(Identity_AdminController::class, 'index', [], 'GET');

        $view = $this->controller()->view;
        $this->assertStringContainsString('Site Identity', (string) $view->title);
        $this->assertInstanceOf(\Identity_Form_Identity::class, $view->form);
        $this->assertSame('media-logo', (string) $view->logoId);
        $this->assertSame('media-fav', (string) $view->faviconId);

        $values = $view->form->getValues();
        $this->assertSame('Acme Books', $values['site_name']);
        $this->assertSame('acme', $values['social_twitter']);
        $this->assertSame('acme-oss', $values['social_github']);
    }

    #[Test]
    public function index_defaults_the_site_name_when_config_is_bare(): void
    {
        // No tiger.site node → the site_name falls back to 'Tiger'; logo/favicon blank.
        $this->tiger([]);
        $this->loginAs('admin');
        $this->dispatchAction(Identity_AdminController::class, 'index', [], 'GET');

        $view = $this->controller()->view;
        $this->assertSame('Tiger', $view->form->getValues()['site_name']);
        $this->assertSame('', (string) $view->logoId);
    }
}
