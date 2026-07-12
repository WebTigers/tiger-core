<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Theme — read helpers for the ACTIVE theme's on-disk resources.
 *
 * The active theme's directory is resolved once at bootstrap (`_initTheme`) into the
 * `Tiger_ThemeDir` registry entry; these helpers read from there. They give the rest of the
 * platform a small, uniform way to reach a theme's **manifest** (`theme.json`) and its
 * **builder components** (`components/*.phtml`) without each caller re-deriving the path.
 *
 * A theme is otherwise resolved purely by path (see ARCHITECTURE §9a) — this class adds the
 * two data reads a theme needs to participate in the CMS: its manifest (asset base, canvas
 * CSS, skins) and its GrapesJS block library (THEMES.md Tier 2).
 *
 * @api
 */
class Tiger_Theme
{
    /**
     * The active theme's absolute directory, or '' if not resolved (e.g. a CLI run pre-boot).
     *
     * @return string
     */
    public static function dir()
    {
        return Zend_Registry::isRegistered('Tiger_ThemeDir') ? (string) Zend_Registry::get('Tiger_ThemeDir') : '';
    }

    /**
     * The theme's manifest (`theme.json`) as an array, or [] when absent/invalid.
     *
     * @return array<string,mixed>
     */
    public static function manifest()
    {
        $file = self::dir() . '/theme.json';
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    /**
     * The theme's public asset base URL (the `public/_<x>` symlink), e.g. `/_theme`. From the
     * manifest's `assetBase`, else the conventional `/_theme`.
     *
     * @return string
     */
    public static function assetBase()
    {
        $man = self::manifest();
        return (isset($man['assetBase']) && $man['assetBase'] !== '') ? (string) $man['assetBase'] : '/_theme';
    }

    /**
     * The theme's GrapesJS block components. Each `components/<id>.phtml` is one block: a leading
     * `<!-- tiger:block label="…" category="…" icon="…" -->` hint names it; the rest is the block's
     * HTML. Returned as `[{id,label,category,media,content}]` for the visual builder's palette.
     *
     * @return array<int,array<string,string>>
     */
    public static function components()
    {
        $out = [];
        foreach (glob(self::dir() . '/components/*.phtml') ?: [] as $file) {
            $raw  = (string) file_get_contents($file);
            $meta = self::hint($raw, 'tiger:block');
            $body = preg_replace('/^\s*<!--\s*tiger:block\b.*?-->\s*/s', '', $raw, 1);
            $id   = basename($file, '.phtml');
            $out[] = [
                'id'       => $id,
                'label'    => $meta['label']   ?? ucfirst(str_replace('-', ' ', $id)),
                'category' => $meta['category'] ?? 'Theme',
                'media'    => $meta['icon']     ?? '',
                'content'  => trim((string) $body),
            ];
        }
        return $out;
    }

    /**
     * Parse a leading `<!-- <tag> key="value" … -->` hint comment into an assoc array (empty if none).
     * The shared parser behind `tiger:page` (theme static pages) and `tiger:block` (components).
     *
     * @param  string $raw the file contents
     * @param  string $tag the hint tag (e.g. `tiger:page`, `tiger:block`)
     * @return array<string,string>
     */
    public static function hint($raw, $tag)
    {
        $meta = [];
        if (preg_match('/<!--\s*' . preg_quote($tag, '/') . '\b(.*?)-->/s', (string) $raw, $m)
            && preg_match_all('/(\w+)\s*=\s*"([^"]*)"/', $m[1], $kv, PREG_SET_ORDER)) {
            foreach ($kv as $pair) {
                $meta[$pair[1]] = $pair[2];
            }
        }
        return $meta;
    }
}
