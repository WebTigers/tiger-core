<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Integration\Profile;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Profile_IndexController;
use Profile_OrgController;
use Tiger\Tests\Support\ModuleControllerTestCase;

/**
 * The two Profile admin-shell controllers — /profile (the signed-in user's own account) and
 * /profile/org (the admin's own-org profile). Both are thin: init() sets the admin layout, indexAction
 * assembles the tabbed shell's view model (forms, contacts/addresses, locales, avatar/logo) from the
 * signed-in identity's user/org row. The harness dispatches each action with rendering off and asserts
 * the view model the action built — covering the branch logic without a theme render.
 */
#[CoversClass(Profile_IndexController::class)]
#[CoversClass(Profile_OrgController::class)]
final class ControllersTest extends ModuleControllerTestCase
{
    #[Test]
    public function user_profile_index_builds_the_tabbed_view_model_for_the_signed_in_user(): void
    {
        $this->loginAs('user');
        $this->dispatchAction(Profile_IndexController::class, 'index', [], 'GET');

        $view = $this->controller()->view;
        $this->assertStringContainsString('Profile', (string) $view->title);
        $this->assertTrue((bool) $view->useIntlTel);
        $this->assertTrue((bool) $view->useAddress);

        $model = $view->model;
        $this->assertIsArray($model);
        $this->assertInstanceOf(\Profile_Form_UserProfile::class, $model['form']);
        $this->assertInstanceOf(\Profile_Form_Password::class, $model['passwordForm']);
        $this->assertInstanceOf(\Profile_Form_Contact::class, $model['contactForm']);
        // Locales come from tiger.i18n.locales ('en,es') mapped to display names.
        $this->assertSame(['en' => 'English', 'es' => 'Español'], $model['locales']);
        $this->assertSame('US', $model['phoneDefault']);
        $this->assertIsArray($model['countries']);
        $this->assertIsArray($model['timezones']);
    }

    #[Test]
    public function user_profile_index_falls_back_to_english_when_no_locales_configured(): void
    {
        // No tiger.i18n node → the controller defaults the supported set to ['en'].
        $this->tiger(['profile' => ['phone' => ['default_country' => 'CA']]]);
        $this->loginAs('user');
        $this->dispatchAction(Profile_IndexController::class, 'index', [], 'GET');

        $model = $this->controller()->view->model;
        $this->assertSame(['en' => 'English'], $model['locales']);
        $this->assertSame('CA', $model['phoneDefault']);
    }

    #[Test]
    public function user_profile_index_runs_for_a_guest_with_no_identity(): void
    {
        // No login → the empty-userId branch: no user row, blank collections, still a full view model.
        $this->dispatchAction(Profile_IndexController::class, 'index', [], 'GET');

        $model = $this->controller()->view->model;
        $this->assertSame('', $model['username']);
        $this->assertSame('', $model['email']);
        $this->assertSame([], $model['contacts']);
        $this->assertSame([], $model['addresses']);
        $this->assertSame('', $model['displayName']);
    }

    #[Test]
    public function org_profile_index_builds_the_org_view_model(): void
    {
        $this->loginAs('admin');
        $this->dispatchAction(Profile_OrgController::class, 'index', [], 'GET');

        $view = $this->controller()->view;
        $this->assertStringContainsString('Organization', (string) $view->title);
        $this->assertTrue((bool) $view->useIntlTel);

        $model = $view->model;
        $this->assertInstanceOf(\Profile_Form_OrgProfile::class, $model['form']);
        // The org twin drives the shared collection views to the org-scoped services by exact name.
        $this->assertSame('OrgContact', $model['contactSvc']);
        $this->assertSame('OrgAddress', $model['addressSvc']);
        $this->assertSame('US', $model['phoneDefault']);
        $this->assertIsArray($model['countries']);
    }
}
