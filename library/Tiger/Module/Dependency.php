<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Module_Dependency — lightweight, lazy inter-module dependency alerts.
 *
 * A module MAY declare what it relies on in configs/dependency.ini — a bare slug, or a slug + a
 * version constraint (space, `@`, or `:` separated):
 *
 *   [requires]
 *   modules[] = "account"
 *   modules[] = "billing >=0.5.0-beta"
 *
 * These are CONVENIENCE ALERTS, never hard blocks:
 *   - on activate:   missing($slug) / missingReport($slug) -> required modules absent, inactive, OR
 *                    present-but-too-old ("requires billing >=0.5, you have 0.4")
 *   - on deactivate: dependents($slug) -> active modules that list $slug as a requirement
 *                    ("X, Y still depend on this — deactivate anyway?")
 *
 * Zero boot cost: nothing is read at bootstrap. The ini files are parsed ON DEMAND, only when an
 * admin toggles a module — a handful of tiny reads on a rare action, straight off the module
 * directories (via Tiger_Module_Discovery). No schema, no dependency graph, no runtime weight.
 *
 * @api
 */
class Tiger_Module_Dependency
{
    /**
     * The slugs $slug declares it requires (its configs/dependency.ini [requires] modules[]).
     *
     * @param  string $slug the module slug to read requirements for
     * @return string[] the required module slugs (lowercased, deduped)
     */
    public static function requires($slug)
    {
        $out = [];
        foreach (self::requirements($slug) as $r) { $out[] = $r['slug']; }
        return $out;
    }

    /**
     * What $slug requires, WITH any declared version constraint. `[['slug'=>'billing','constraint'=>'>=0.5.0'], …]`
     * (constraint is '' when none was declared).
     *
     * @param  string $slug the module slug to read requirements for
     * @return array<int,array{slug:string,constraint:string}>
     */
    public static function requirements($slug)
    {
        $dir = self::_dir($slug);
        return $dir ? self::_readRequirements($dir) : [];
    }

    /**
     * Of what $slug requires, the ones NOT currently usable — absent, deactivated, OR present but a
     * version that doesn't satisfy the declared constraint. The activate-time alert (bare slugs; see
     * missingReport() for the reason + versions).
     *
     * @param  string $slug the module slug being activated
     * @return string[] the required slugs that are missing, inactive, or out of version range
     */
    public static function missing($slug)
    {
        $out = [];
        foreach (self::missingReport($slug) as $r) { $out[] = $r['slug']; }
        return $out;
    }

    /**
     * The detailed activate-time report: each unusable requirement with WHY (absent / inactive /
     * version) and the versions involved — so the UI can say "requires billing >=0.5 (you have 0.4)".
     * Advisory: an unknown installed version never triggers a version alarm.
     *
     * @param  string $slug the module slug being activated
     * @return array<int,array{slug:string,reason:string,need:string,have:?string}>
     */
    public static function missingReport($slug)
    {
        $present  = Tiger_Module_Discovery::all();
        $inactive = self::_inactiveSlugs();
        $out = [];
        foreach (self::requirements($slug) as $req) {
            $dep  = $req['slug'];
            $need = $req['constraint'];
            $have = isset($present[$dep]['version']) ? (string) $present[$dep]['version'] : null;
            if (!isset($present[$dep])) {
                $out[] = ['slug' => $dep, 'reason' => 'absent', 'need' => $need, 'have' => null];
            } elseif (in_array($dep, $inactive, true)) {
                $out[] = ['slug' => $dep, 'reason' => 'inactive', 'need' => $need, 'have' => $have];
            } elseif ($need !== '' && $have !== null && $have !== '' && !Tiger_Module_Compat::satisfies($have, $need)) {
                $out[] = ['slug' => $dep, 'reason' => 'version', 'need' => $need, 'have' => $have];
            }
        }
        return $out;
    }

    /**
     * Active modules that list $slug as a requirement. The deactivate-time alert:
     * "X, Y still depend on this — deactivate anyway?" (surfaced, never a block).
     *
     * @param  string $slug the module slug being deactivated
     * @return string[] the active module slugs that depend on $slug
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

    /**
     * Parse [requires] modules[] from a module dir's configs/dependency.ini into slug + constraint
     * pairs (lowercased slug, deduped by slug). Each entry is a bare slug or "slug <constraint>"
     * where the separator is whitespace, `@`, or `:` — e.g. "billing >=0.5.0-beta", "pay@^1.0".
     */
    protected static function _readRequirements($dir)
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
        $out  = [];
        $seen = [];
        foreach ((array) $req as $entry) {
            $entry = trim((string) $entry);
            if ($entry === '') { continue; }
            $parts      = preg_split('/[\s@:]+/', $entry, 2);
            $slug       = strtolower(trim($parts[0]));
            $constraint = isset($parts[1]) ? trim($parts[1]) : '';
            if ($slug === '' || isset($seen[$slug])) { continue; }
            $seen[$slug] = true;
            $out[] = ['slug' => $slug, 'constraint' => $constraint];
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
