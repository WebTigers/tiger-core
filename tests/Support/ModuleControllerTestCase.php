<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Support;

use Zend_Config;
use Zend_Controller_Action_HelperBroker;
use Zend_Registry;
use Zend_Session;
use Zend_Translate;

/**
 * ModuleControllerTestCase — a ControllerTestCase specialized for the module admin/index controllers
 * (Wave 6 sweep). Those controllers extend `Tiger_Controller_Admin_Action`, whose init() wires a
 * FlashMessenger (needs an array-backed session under CLI) and reads `Zend_Config`/`Zend_Translate`
 * from the registry — and several actions read config/translate DIRECTLY (unguarded). So this base
 * turns on the CLI session, and stashes + registers a working `Zend_Config` + `Zend_Translate` for the
 * duration of a test, restoring the prior registry state in tearDown.
 *
 * A test tweaks the config for its case via `setConfig(['tiger' => [...]])` (or the `tiger()` shorthand);
 * the default config is enough for a bare admin render.
 *
 * @internal test infrastructure.
 */
abstract class ModuleControllerTestCase extends ControllerTestCase
{
    private bool $priorUnitTestMode = false;
    private bool $hadConfig = false;
    private $priorConfig = null;
    private bool $hadTranslate = false;
    private $priorTranslate = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Array-backed session so the FlashMessenger + Zend_Session_Namespace run under CLI.
        $this->priorUnitTestMode = Zend_Session::$_unitTestEnabled;
        Zend_Session::$_unitTestEnabled = true;
        $_SESSION = [];

        // A controller `_redirect()` otherwise calls exit() (kills the PHPUnit process). Turn it off so
        // gotoUrl() just sets the Location header — assertable via redirectLocation().
        Zend_Controller_Action_HelperBroker::getStaticHelper('redirector')->setExit(false);

        $reg = Zend_Registry::getInstance();
        $this->hadConfig    = $reg->offsetExists('Zend_Config');
        $this->priorConfig  = $this->hadConfig ? Zend_Registry::get('Zend_Config') : null;
        $this->hadTranslate = $reg->offsetExists('Zend_Translate');
        $this->priorTranslate = $this->hadTranslate ? Zend_Registry::get('Zend_Translate') : null;

        $this->tiger([
            'site'      => ['name' => 'Tiger', 'tagline' => 'Test tagline'],
            'i18n'      => ['locales' => 'en,es'],
            'profile'   => ['phone' => ['default_country' => 'US']],
            'media'     => ['variants' => ['server' => '1', 'thumbnail' => '200']],
            'analytics' => ['enabled' => '0', 'exclude_signed_in' => '1', 'ga4' => ['measurement_id' => '']],
            'backup'    => ['components' => 'database,media', 'disk' => 'local'],
        ]);
        $this->registerTranslate();
    }

    protected function tearDown(): void
    {
        $reg = Zend_Registry::getInstance();

        if ($this->hadConfig) {
            Zend_Registry::set('Zend_Config', $this->priorConfig);
        } elseif ($reg->offsetExists('Zend_Config')) {
            $reg->offsetUnset('Zend_Config');
        }
        if ($this->hadTranslate) {
            Zend_Registry::set('Zend_Translate', $this->priorTranslate);
        } elseif ($reg->offsetExists('Zend_Translate')) {
            $reg->offsetUnset('Zend_Translate');
        }

        $_SESSION = [];
        Zend_Session::$_unitTestEnabled = $this->priorUnitTestMode;
        parent::tearDown();
    }

    /** Register a `Zend_Config` whose top node is `tiger` (the shape every Tiger controller reads). */
    protected function tiger(array $tiger): void
    {
        $this->setConfig(['tiger' => $tiger]);
    }

    /** Register an arbitrary `Zend_Config` from an array. */
    protected function setConfig(array $data): void
    {
        Zend_Registry::set('Zend_Config', new Zend_Config($data));
    }

    /**
     * Register a `Zend_Translate` array adapter. Untranslated keys fall through to the key itself (the
     * platform's own behavior), so a controller that translates a title gets a usable string either way.
     */
    protected function registerTranslate(): void
    {
        $translate = new Zend_Translate([
            'adapter' => 'array',
            'content' => [
                'profile.title'     => 'Profile',
                'profile.org.title' => 'Organization',
            ],
            'locale'  => 'en',
        ]);
        Zend_Registry::set('Zend_Translate', $translate);
    }
}
