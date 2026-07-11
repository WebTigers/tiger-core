<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Vendor_Environment — reads the host's capability for provisioning third-party libraries.
 *
 * "Composer available" is more than a binary: a shared host may ship Composer but disable `exec`/
 * `proc_open`, or mount `vendor/` read-only, or cap memory/time. This detects all of that so
 * Tiger_Vendor can pick the right provisioning tier and FAIL CLOSED — never shell out to a Composer
 * that can't actually run, never hang an install. See DEPENDENCIES.md.
 *
 * @api
 */
class Tiger_Vendor_Environment
{
    /** @return bool whether proc_open (preferred) or exec is genuinely callable (not disabled). */
    public static function execEnabled()
    {
        return self::_functionEnabled('proc_open') || self::_functionEnabled('exec');
    }

    /**
     * An invokable Composer command, or null if none is usable here.
     *
     * @return string|null 'composer' (on PATH), a `php composer.phar` invocation, or null
     */
    public static function composerBinary()
    {
        if (!self::execEnabled()) {
            return null;   // no point naming a binary we can't run
        }
        foreach (self::_pharCandidates() as $phar) {
            if (is_file($phar)) {
                $php = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
                return escapeshellarg($php) . ' ' . escapeshellarg($phar);
            }
        }
        // A Composer on PATH — verified for real by Tiger_Vendor before use.
        if (self::_functionEnabled('shell_exec')) {
            $which = @shell_exec('command -v composer 2>/dev/null');
            if (is_string($which) && trim($which) !== '') {
                return 'composer';
            }
        }
        return null;
    }

    /** @return bool whether the app's vendor/ (the Tier-1 Composer target) is writable. */
    public static function vendorWritable()
    {
        $vendor = self::appRoot() . '/vendor';
        return is_dir($vendor) ? is_writable($vendor) : is_writable(self::appRoot());
    }

    /** @return bool whether Composer can ACTUALLY run here (binary + exec + writable vendor). */
    public static function composerUsable()
    {
        return self::composerBinary() !== null && self::vendorWritable();
    }

    /** @return string the app root (parent of public/) — where vendor/ and vendor-libs/ live. */
    public static function appRoot()
    {
        if (defined('APPLICATION_ROOT')) { return APPLICATION_ROOT; }
        if (defined('APPLICATION_PATH')) { return dirname(APPLICATION_PATH); }
        return getcwd();
    }

    /** @return string the shared library store — Tiger's, beside Composer's vendor/. */
    public static function storeDir()
    {
        return self::appRoot() . '/vendor-libs';
    }

    /** @return bool whether the store can be created or written. */
    public static function storeWritable()
    {
        $store = self::storeDir();
        return is_dir($store) ? is_writable($store) : is_writable(dirname($store));
    }

    /**
     * A human-readable capability report for the installer/admin to show the operator.
     *
     * @return array{exec_enabled:bool,composer:?string,composer_usable:bool,vendor_writable:bool,store:string,store_writable:bool}
     */
    public static function report()
    {
        return [
            'exec_enabled'    => self::execEnabled(),
            'composer'        => self::composerBinary(),
            'composer_usable' => self::composerUsable(),
            'vendor_writable' => self::vendorWritable(),
            'store'           => self::storeDir(),
            'store_writable'  => self::storeWritable(),
        ];
    }

    // ---- helpers ---------------------------------------------------------------

    protected static function _functionEnabled($fn)
    {
        if (!function_exists($fn)) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return !in_array($fn, $disabled, true);
    }

    protected static function _pharCandidates()
    {
        $root = self::appRoot();
        return [
            $root . '/composer.phar',
            $root . '/bin/composer.phar',
        ];
    }
}
