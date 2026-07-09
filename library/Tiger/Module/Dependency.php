<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Module_Dependency — lightweight, lazy inter-module dependency alerts.
 *
 * A module MAY declare what it relies on in configs/dependency.ini:
 *
 *   [requires]
 *   modules[] = "account"
 *   modules[] = "billing"
 *
 * These are CONVENIENCE ALERTS, never hard blocks:
 *   - on activate:   missing($slug)    -> required modules that aren't present/active
 *                    ("this module requires X, Y, Z to activate")
 *   - on deactivate: dependents($slug) -> active modules that list $slug as a requirement
 *                    ("X, Y still depend on this")
 *
 * Zero boot cost: nothing is read at bootstrap. The ini files are parsed ON DEMAND, only when an
 * admin toggles a module — a handful of tiny reads on a rare action, straight off the module
 * directories (via Tiger_Module_Discovery). No schema, no dependency graph, no runtime weight.
 *
 * @api
 */
class Tiger_Module_Dependency
{
    /** The slugs $slug declares it requires (its configs/dependency.ini [requires] modules[]). */
    public static function requires($slug)
    {
        $dir = self::_dir($slug);
        return $dir ? self::_readRequires($dir) : [];
    }

    /**
     * Of what $slug requires, the ones NOT currently usable — absent from disk OR deactivated.
     * The activate-time alert: "this module requires X, Y, Z to activate."
     */
    public static function missing($slug)
    {
        $present  = Tiger_Module_Discovery::all();
        $inactive = self::_inactiveSlugs();
        $out = [];
        foreach (self::requires($slug) as $dep) {
            if (!isset($present[$dep]) || in_array($dep, $inactive, true)) {
                $out[] = $dep;
            }
        }
        return $out;
    }

    /**
     * Active modules that list $slug as a requirement. The deactivate-time alert:
     * "X, Y still depend on this — deactivate anyway?" (surfaced, never a block).
     */
    public static function dependents($slug)
    {
        $slug     = strtolower(basename((string) $slug));
        $inactive = self::_inactiveSlugs();
        $out = [];
        foreach (array_keys(Tiger_Module_Discovery::all()) as $other) {
            if ($other === $slug || in_array($other, $inactive, true)) {
                continue;   // skip self + already-inactive modules
            }
            if (in_array($slug, self::requires($other), true)) {
                $out[] = $other;
            }
        }
        return $out;
    }

    /** Parse [requires] modules[] from a module dir's configs/dependency.ini (lowercased, deduped). */
    protected static function _readRequires($dir)
    {
        $f = $dir . '/configs/dependency.ini';
        if (!is_file($f)) {
            return [];
        }
        $ini = @parse_ini_file($f, true);
        $req = isset($ini['requires']['modules']) ? $ini['requires']['modules'] : [];
        if (is_string($req)) {
            $req = [$req];
        }
        $out = [];
        foreach ((array) $req as $s) {
            $s = strtolower(trim((string) $s));
            if ($s !== '' && !in_array($s, $out, true)) {
                $out[] = $s;
            }
        }
        return $out;
    }

    /** Locate a module's dir (app wins over core), or null. */
    protected static function _dir($slug)
    {
        $slug = basename((string) $slug);
        foreach ([
            defined('APPLICATION_PATH') ? APPLICATION_PATH . '/modules' : null,
            defined('TIGER_CORE_PATH')  ? TIGER_CORE_PATH  . '/modules' : null,
        ] as $root) {
            if ($root && is_dir($root . '/' . $slug)) {
                return $root . '/' . $slug;
            }
        }
        return null;
    }

    /** Deactivated slugs (empty on any failure — alerts degrade to "nothing depends"). */
    protected static function _inactiveSlugs()
    {
        try {
            return (new Tiger_Model_Module())->inactiveSlugs();
        } catch (Throwable $e) {
            return [];
        }
    }
}
