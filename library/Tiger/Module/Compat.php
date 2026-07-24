<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Module_Compat — advisory "which Tiger versions was this module tested for?" metadata.
 *
 * Pure notification, never a gate (the WordPress model): a module MAY declare a tested version RANGE
 * in its manifest, and this class turns that + the running Tiger version into a friendly notice —
 * "This module has not been tested for Tiger X.Y.Z". It NEVER blocks install, activation, or update;
 * a module out of its tested range will most likely still run. It's a heads-up to keep a module in
 * step with the platform, nothing more.
 *
 *   "compat": { "tiger": { "min": "0.36.0-beta", "max": "0.40.0-beta" } }
 *
 * `requires.tiger` (a bare or constrained version) is honored as the legacy MIN if `compat` is absent.
 * The canonical running version is TIGER_VERSION (== tiger-core's Tiger_Version::VERSION — the
 * framework IS Tiger, so the two never drift). This class is also the ONE version comparator the
 * module system uses (`satisfies()`), reused by the dependency alerts and the installer.
 *
 * @api
 */
class Tiger_Module_Compat
{
    /** Within (or without) a declared range — no notice. */
    const OK        = 'ok';
    /** The running Tiger is NEWER than the module's tested-up-to `max` — "not tested for X". */
    const UNTESTED  = 'untested';
    /** The running Tiger is OLDER than the module's `min` — it may rely on features not here yet. */
    const BELOW_MIN = 'below_min';

    /**
     * Advisory compatibility verdict for a module manifest against the running Tiger version.
     *
     * @param  array       $manifest      the module manifest (reads `compat.tiger.{min,max}`, legacy `requires.tiger` as min)
     * @param  string|null $tigerVersion  the running version (defaults to TIGER_VERSION / Tiger_Version::VERSION) — inject for tests
     * @return array{status:string,ok:bool,message:string,min:?string,max:?string,tiger:string}
     */
    public static function check(array $manifest, $tigerVersion = null)
    {
        $running = (string) ($tigerVersion ?: self::runningVersion());
        $r       = self::_norm($running);

        $c      = (isset($manifest['compat']['tiger']) && is_array($manifest['compat']['tiger'])) ? $manifest['compat']['tiger'] : [];
        $minRaw = isset($c['min']) ? (string) $c['min'] : (string) ($manifest['requires']['tiger'] ?? '');
        $maxRaw = isset($c['max']) ? (string) $c['max'] : '';
        $min    = self::_norm($minRaw);
        $max    = self::_norm($maxRaw);

        $status  = self::OK;
        $message = '';
        if ($min !== '' && version_compare($r, $min, '<')) {
            $status  = self::BELOW_MIN;
            $message = 'This module is built for Tiger ' . $minRaw . ' or newer — this install is ' . $running
                     . '. It may rely on features that aren’t here yet.';
        } elseif ($max !== '' && version_compare($r, $max, '>')) {
            $status  = self::UNTESTED;
            $message = 'This module has not been tested for Tiger ' . $running . ' (tested up to ' . $maxRaw
                     . '). It will most likely still work.';
        }

        return [
            'status'  => $status,
            'ok'      => $status === self::OK,
            'message' => $message,
            'min'     => $minRaw !== '' ? $minRaw : null,
            'max'     => $maxRaw !== '' ? $maxRaw : null,
            'tiger'   => $running,
        ];
    }

    /**
     * Does a version satisfy a constraint? The one comparator the module system shares (installer
     * `requires`, dependency version pins). Ops: `>= <= > < = == ^ ~` (bare or `^`/`~` ⇒ `>=`); a
     * leading `v` and a `-beta`/`-alpha` suffix are handled by `version_compare`. Fail-open: an
     * unparseable constraint returns true (advisory — never a false alarm).
     *
     * @param  string $have        the version in hand (e.g. "0.40.0-beta")
     * @param  string $constraint  the requirement (e.g. ">=0.5.0-beta", "0.5", "^1.2")
     * @return bool                whether $have satisfies $constraint
     */
    public static function satisfies($have, $constraint)
    {
        $constraint = trim((string) $constraint);
        if ($constraint === '') { return true; }
        if (!preg_match('/^(>=|<=|>|<|==|=|\^|~)?\s*v?([0-9][0-9A-Za-z.\-]*)/', $constraint, $m)) {
            return true;
        }
        $op = $m[1] ?: '>=';
        $op = ($op === '^' || $op === '~') ? '>=' : (($op === '=') ? '==' : $op);
        return version_compare(self::_norm($have), self::_norm($m[2]), $op);
    }

    /** The running Tiger version (TIGER_VERSION if defined, else the class constant). */
    public static function runningVersion()
    {
        return defined('TIGER_VERSION') ? TIGER_VERSION : Tiger_Version::VERSION;
    }

    /** Strip a leading comparison operator + optional `v` so version_compare sees a bare version. */
    protected static function _norm($v)
    {
        return (string) preg_replace('/^\s*(>=|<=|>|<|==|=|\^|~)?\s*v?/i', '', trim((string) $v));
    }
}
