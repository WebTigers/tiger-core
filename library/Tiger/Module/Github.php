<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Module_Github — read public GitHub repos over cURL (no auth, public only).
 *
 * The module installer never uses git or a token: it pulls `module.json` (the technical
 * manifest) and `TIGER.md` (the vendor's human description) as RAW files, resolves a pinned
 * release ref, and downloads the release tarball. Private repos simply 404 on raw — which the
 * installer treats as "not installable" (public code is the price of admission).
 *
 * @api
 */
class Tiger_Module_Github
{
    const RAW = 'https://raw.githubusercontent.com';
    const API = 'https://api.github.com';
    const UA  = 'Tiger-Module-Installer';

    /** Parse a GitHub repo URL/slug → ['org','repo'], or null. Accepts …/org/repo(.git)(/…). */
    public static function parseRepo($url)
    {
        $url = trim((string) $url);
        if (preg_match('~github\.com[:/]+([A-Za-z0-9._-]+)/([A-Za-z0-9._-]+?)(?:\.git)?(?:[/#?].*)?$~', $url, $m)
            || preg_match('~^([A-Za-z0-9._-]+)/([A-Za-z0-9._-]+)$~', $url, $m)) {
            return ['org' => $m[1], 'repo' => $m[2]];
        }
        return null;
    }

    /** Fetch a raw file from a public repo at a ref (branch/tag/sha). Content string, or null. */
    public static function fetchRaw($org, $repo, $ref, $path)
    {
        return self::_http(self::RAW . "/{$org}/{$repo}/{$ref}/" . ltrim((string) $path, '/'));
    }

    /** The latest RELEASE tag (preferred — pinnable), else the default branch. Null if neither. */
    public static function latestRef($org, $repo)
    {
        $rel = self::_http(self::API . "/repos/{$org}/{$repo}/releases/latest", true);
        if ($rel) {
            $d = json_decode($rel, true);
            if (!empty($d['tag_name'])) { return $d['tag_name']; }
        }
        $meta = self::_http(self::API . "/repos/{$org}/{$repo}", true);
        if ($meta) {
            $d = json_decode($meta, true);
            if (!empty($d['default_branch'])) { return $d['default_branch']; }
        }
        return null;
    }

    /** GitHub's codeload tarball URL for a ref (redirects; download() follows). */
    public static function tarballUrl($org, $repo, $ref)
    {
        return "https://github.com/{$org}/{$repo}/archive/" . rawurlencode((string) $ref) . '.tar.gz';
    }

    /** Download a URL to a local file. Returns bool. */
    public static function download($url, $destFile)
    {
        return (bool) self::_http($url, false, $destFile);
    }

    /** GET any public URL (e.g. the Vendor Registry index). Body string, or null. */
    public static function get($url)
    {
        return self::_http($url);
    }

    /** HTTP GET via cURL (public, follows redirects, UA set). Body string / true(to file) / null. */
    protected static function _http($url, $api = false, $toFile = null)
    {
        if (!function_exists('curl_init')) {
            $ctx  = stream_context_create(['http' => ['user_agent' => self::UA, 'timeout' => 30]]);
            $body = @file_get_contents($url, false, $ctx);
            if ($body === false) { return null; }
            if ($toFile) { return @file_put_contents($toFile, $body) !== false ? true : null; }
            return $body;
        }

        $ch = curl_init($url);
        $fh = null;
        $opts = [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_USERAGENT      => self::UA,
            CURLOPT_SSL_VERIFYPEER => true,
        ];
        if ($api) { $opts[CURLOPT_HTTPHEADER] = ['Accept: application/vnd.github+json']; }
        if ($toFile) {
            $fh = fopen($toFile, 'wb');
            $opts[CURLOPT_FILE] = $fh;
        } else {
            $opts[CURLOPT_RETURNTRANSFER] = true;
        }
        curl_setopt_array($ch, $opts);
        $res  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($fh) { fclose($fh); }

        if ($code < 200 || $code >= 300) {
            if ($toFile) { @unlink($toFile); }
            return null;
        }
        return $toFile ? true : $res;
    }
}
