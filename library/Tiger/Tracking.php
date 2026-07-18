<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Tracking — a process-wide registry of the tracking/analytics scripts an install runs.
 *
 * Any feature that loads a third-party tracker (Google Analytics, a pixel, a heatmap, …) registers
 * itself here from its Bootstrap. The registry is the seam between *trackers* and *consent*: the
 * GDPR cookie-consent feature reads it so its "auto" mode can tell whether any tracking is present
 * (and, later, to render a per-category cookie list). Registering is declarative metadata only —
 * it does NOT inject anything; each tracker still emits its own script (gated by Tiger_Consent).
 *
 * Like Tiger_Dashboard / Tiger_Sitemap, this is core so declaring a tracker never depends on the
 * consent module being installed.
 *
 * @api
 */
class Tiger_Tracking
{
    /** category => tracker rows (['key','label','category','active']), keyed by tracker key. */
    protected static $_trackers = [];

    /**
     * Register (or update) a tracker.
     *
     * @param  string $key  a stable id, e.g. 'ga4'
     * @param  array  $meta label, category ('analytics'|'marketing'|'functional'|…), active (bool)
     * @return void
     */
    public static function register($key, array $meta = [])
    {
        $key = (string) $key;
        if ($key === '') {
            return;
        }
        self::$_trackers[$key] = [
            'key'      => $key,
            'label'    => (string) ($meta['label'] ?? ucfirst($key)),
            'category' => (string) ($meta['category'] ?? 'analytics'),
            'active'   => !empty($meta['active']),
        ];
    }

    /**
     * All registered trackers (optionally filtered to a category).
     *
     * @param  string|null $category limit to one category, or null for all
     * @return array                 the tracker rows
     */
    public static function all($category = null)
    {
        $rows = array_values(self::$_trackers);
        if ($category === null) {
            return $rows;
        }
        return array_values(array_filter($rows, static function ($t) use ($category) {
            return $t['category'] === (string) $category;
        }));
    }

    /**
     * Is at least one ACTIVE tracker registered (optionally in a category)? Drives consent "auto" mode.
     *
     * @param  string|null $category limit to one category, or null for any
     * @return bool
     */
    public static function hasActive($category = null)
    {
        foreach (self::$_trackers as $t) {
            if ($t['active'] && ($category === null || $t['category'] === (string) $category)) {
                return true;
            }
        }
        return false;
    }
}
