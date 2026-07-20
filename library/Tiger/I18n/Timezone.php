<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_I18n_Timezone — the IANA timezone list, enriched for a searchable picker.
 *
 * `DateTimeZone::listIdentifiers()` gives bare IANA ids (`America/New_York`) — fine to store, poor to
 * pick from: a user can't find their zone by the things they actually know (the current UTC offset, or
 * an abbreviation like `EST`). This helper builds a label that folds all three search axes into the
 * option TEXT — `America/New_York (EST, UTC-05:00)` — so a plain substring match (TigerCombo) finds a
 * zone whether the user types the city, `EST`, or `-05:00`. Options are ordered by current offset
 * (UTC-12 → UTC+14) then id, so the list reads like a globe.
 *
 * The offset/abbreviation are computed for "now", so they follow DST (a zone shows `EST` in winter,
 * `EDT` in summer) — correct for a human choosing their zone today. The stored value is always the
 * bare IANA id; the label is display + search sugar only.
 *
 * @api
 */
class Tiger_I18n_Timezone
{
    /**
     * The searchable option map: IANA id → display label (`America/New_York (EST, UTC-05:00)`),
     * ordered by current UTC offset then id.
     *
     * @return array<string,string> id => label
     */
    public static function options(): array
    {
        $rows = [];
        foreach (DateTimeZone::listIdentifiers() as $id) {
            try {
                $tz  = new DateTimeZone($id);
                $now = new DateTime('now', $tz);
            } catch (Throwable $e) {
                continue;
            }
            $offsetSecs = $tz->getOffset($now);
            $rows[] = [
                'id'      => $id,
                'offset'  => $offsetSecs,
                'label'   => self::_build($id, $now, $offsetSecs),
            ];
        }

        usort($rows, static function ($a, $b) {
            return $a['offset'] <=> $b['offset'] ?: strcmp($a['id'], $b['id']);
        });

        $out = [];
        foreach ($rows as $r) { $out[$r['id']] = $r['label']; }
        return $out;
    }

    /**
     * The display label for one IANA id (`America/New_York (EST, UTC-05:00)`), or the bare id if it
     * isn't a valid zone.
     *
     * @param  string $id an IANA timezone id
     * @return string
     */
    public static function label(string $id): string
    {
        try {
            $tz  = new DateTimeZone($id);
            $now = new DateTime('now', $tz);
        } catch (Throwable $e) {
            return $id;
        }
        $offsetSecs = $tz->getOffset($now);
        return self::_build($id, $now, $offsetSecs);
    }

    /**
     * Compose one label: `id (ABBR, UTC±HH:MM)` when the zone has a real abbreviation, else just
     * `id (UTC±HH:MM)` (no duplicated offset). Both forms keep the id + offset searchable.
     */
    private static function _build(string $id, DateTime $now, int $offsetSecs): string
    {
        $utc  = 'UTC' . self::_offsetHhmm($offsetSecs);
        $abbr = $now->format('T');
        // Zones without a named abbreviation format 'T' as a numeric offset (e.g. "+0530") — drop it
        // so we don't print the offset twice; the UTC±HH:MM already carries it.
        $named = ($abbr !== '' && !preg_match('/^[+-]?\d/', $abbr));
        return $id . ' (' . ($named ? $abbr . ', ' : '') . $utc . ')';
    }

    /**
     * Seconds → a signed HH:MM offset string (`-05:00`, `+05:30`, `+00:00`).
     */
    private static function _offsetHhmm(int $offsetSecs): string
    {
        $sign = $offsetSecs < 0 ? '-' : '+';
        $abs  = abs($offsetSecs);
        return $sign . sprintf('%02d:%02d', intdiv($abs, 3600), intdiv($abs % 3600, 60));
    }
}
