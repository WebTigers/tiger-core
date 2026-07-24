<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.

namespace Tiger\Tests\Support;

use PHPUnit\Framework\TestCase;
use Zend_Config;
use Zend_Registry;

/**
 * Base for unit tests that need no external service (no DB, no network).
 *
 * Its one job is to keep the process-global state that ZF1/Tiger reach for — chiefly the
 * `Zend_Config` in `Zend_Registry` that `Tiger_Crypto`/`Tiger_Security`/etc. read — isolated
 * between tests. Every test starts from a clean registry and gets it torn down after, so ordering
 * never leaks a key, a pepper, or a config value from one case into the next.
 */
abstract class UnitTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Zend_Registry::_unsetInstance();
    }

    protected function tearDown(): void
    {
        Zend_Registry::_unsetInstance();
        parent::tearDown();
    }

    /**
     * Install a `Zend_Config` into the registry from a nested array, exactly where the platform
     * expects it (`Zend_Registry::get('Zend_Config')`). Pass e.g.
     * `['tiger' => ['crypto' => ['key' => $b64]]]`.
     */
    protected function setConfig(array $data): Zend_Config
    {
        $config = new Zend_Config($data, true);
        Zend_Registry::set('Zend_Config', $config);
        return $config;
    }

    /** Convenience: register a valid test crypto key + security pepper in one call. */
    protected function setCryptoConfig(?string $key = null, ?string $pepper = null): void
    {
        $this->setConfig([
            'tiger' => [
                'crypto'   => ['key' => $key ?? base64_encode(str_repeat("\x11", 32))],
                'security' => ['pepper' => $pepper ?? base64_encode(str_repeat("\x22", 32))],
            ],
        ]);
    }
}
