<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Install — first-run bootstrap helpers.
 *
 * Creates the founding org + user + password + membership for a fresh install.
 * This is a SYSTEM/genesis operation: there's no logged-in actor, so the created
 * rows get created_by = NULL. Kept as a class (not inline in bin/tiger) so
 * create-project / a web installer can reuse it. bin/tiger `install:admin` gathers
 * input and calls createOwner().
 *
 * @api
 */
class Tiger_Install
{
    const MIN_PASSWORD = 8;

    /**
     * Create the founding org + owner user + password credential + membership.
     *
     * @param  string      $email
     * @param  string      $password
     * @param  string      $orgName
     * @param  string|null $orgSlug  derived from the org name if null
     * @param  string      $role     the membership role (default 'developer' = god,
     *                               because a fresh install's founder needs full access)
     * @param  string|null $username optional display username (email stays the login id)
     * @return array{org_id:string,user_id:string,org_user_id:string,role:string,email:string,username:?string,org:string,slug:string}
     * @throws RuntimeException on validation error or conflict (existing email/slug/username)
     */
    public static function createOwner($email, $password, $orgName, $orgSlug = null, $role = 'developer', $username = null)
    {
        $email   = trim(strtolower((string) $email));
        $orgName = trim((string) $orgName);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('A valid email is required.');
        }
        $violations = (new Tiger_Policy_Password())->validate((string) $password);
        if ($violations) {
            throw new RuntimeException('Password does not meet policy: ' . implode(', ', $violations));
        }
        if ($orgName === '') {
            throw new RuntimeException('An organization name is required.');
        }
        $slug = $orgSlug ? self::slugify($orgSlug) : self::slugify($orgName);

        $userModel = new Tiger_Model_User();
        if ($userModel->findByEmail($email)) {
            throw new RuntimeException("A user with email {$email} already exists.");
        }
        $orgModel = new Tiger_Model_Org();
        if ($orgModel->findBySlug($slug)) {
            throw new RuntimeException("An organization with slug '{$slug}' already exists.");
        }

        // Optional username — must be unique if given (email stays the login id).
        $username = ($username !== null) ? trim((string) $username) : '';
        if ($username !== '' && $userModel->fetchRow($userModel->activeSelect()->where('username = ?', $username))) {
            throw new RuntimeException("A user with username '{$username}' already exists.");
        }

        // Genesis rows (no actor -> created_by NULL).
        $orgId    = $orgModel->insert(['name' => $orgName, 'slug' => $slug]);
        $userData = ['email' => $email];
        if ($username !== '') { $userData['username'] = $username; }
        $userId   = $userModel->insert($userData);
        (new Tiger_Model_UserCredential())->setPassword($userId, (string) $password);
        $ouId = (new Tiger_Model_OrgUser())->insert([
            'org_id'  => $orgId,
            'user_id' => $userId,
            'role'    => $role,
        ]);

        return [
            'org_id'      => $orgId,
            'user_id'     => $userId,
            'org_user_id' => $ouId,
            'role'        => $role,
            'email'       => $email,
            'username'    => $username !== '' ? $username : null,
            'org'         => $orgName,
            'slug'        => $slug,
        ];
    }

    /**
     * Ensure the install's random secrets exist in local.ini — the app encryption key
     * (tiger.crypto.key) and the password/code pepper (tiger.security.pepper). Generates
     * and writes any that are missing/empty; leaves existing values untouched (never
     * rotates a live secret). Idempotent.
     *
     * This is the ONE place secrets are minted at install time: the CLI (`tiger
     * install:secrets`, and install:admin) and a web/cPanel setup form both call it right
     * after writing the DB creds, so the founding password is peppered from the very first
     * hash. Secrets live ONLY in local.ini (gitignored), never in the repo or the DB.
     *
     * @param  string|null $localIniPath  defaults to APPLICATION_PATH/configs/local.ini
     * @return string[] the config keys that were newly generated (empty = all already set)
     */
    public static function provisionSecrets($localIniPath = null)
    {
        $path = $localIniPath ?: (defined('APPLICATION_PATH') ? APPLICATION_PATH . '/configs/local.ini' : null);
        if (!$path) {
            throw new RuntimeException('provisionSecrets: no local.ini path (APPLICATION_PATH undefined).');
        }
        if (!is_file($path)) {
            file_put_contents($path, "[production]\n");   // a base section so Zend_Config_Ini can load it
        }
        $text      = (string) file_get_contents($path);
        $generated = [];
        $secrets   = [
            'tiger.crypto.key'      => ['Tiger_Crypto', 'generateKey'],
            'tiger.security.pepper' => ['Tiger_Security', 'generatePepper'],
        ];
        foreach ($secrets as $key => $generator) {
            if (self::_localKeyIsSet($text, $key)) {
                continue;
            }
            $text = self::_writeLocalKey($text, $key, (string) call_user_func($generator));
            $generated[] = $key;
        }
        if ($generated) {
            file_put_contents($path, $text);
        }
        return $generated;
    }

    /**
     * Create the writable runtime directories the framework needs (idempotent). Private
     * caches live under storage/; browser-served assets under public/. Called by
     * install:admin + the standalone `install:storage` command, and safe to re-run.
     *
     * Dirs are made 0775; the WEB SERVER user must be able to write them — on a single-user
     * host (cPanel) that's automatic, on a split root/www-user host chown/chgrp them to the
     * web group after. Returns the dirs actually created.
     *
     * @return string[] relative paths created (empty if all already existed)
     */
    public static function provisionStorage($appRoot = null)
    {
        $root = $appRoot ?: (defined('APPLICATION_ROOT') ? APPLICATION_ROOT : null);
        if (!$root) {
            throw new RuntimeException('provisionStorage: no application root (APPLICATION_ROOT undefined).');
        }
        $root = rtrim($root, '/');

        $dirs = [
            'storage',
            'storage/cache',
            'storage/cache/code',   // Tiger Code — compiled PHP bundles + inject manifests (private)
            'storage/media',        // Media — private files (streamed, outside the docroot)
            'public/_media',         // Media — public files (served)
            'public/_code',          // Tiger Code — css/js assets (served, browser-cached)
            'public/_modules',       // Module installer — published module assets (served)
        ];
        $made = [];
        foreach ($dirs as $rel) {
            $abs = $root . '/' . $rel;
            if (is_dir($abs)) {
                continue;   // also true for an existing symlink to a dir — never clobbered
            }
            if (!@mkdir($abs, 0775, true) && !is_dir($abs)) {
                throw new RuntimeException('provisionStorage: could not create ' . $abs);
            }
            $made[] = $rel;
        }
        return $made;
    }

    /**
     * (Re)create the webroot's default asset symlinks — `_tiger` (shared core public assets) and
     * `_theme` (the active theme's assets). This is the failsafe way to wire assets on ANY host:
     * recreate the links, never copy — so a framework/theme update is picked up with no re-publish.
     *
     * Works for both layouts because the target is computed from $root (absolute):
     *   - co-located (dev / VPS):  webroot = <root>/public
     *   - split (cPanel / shared): webroot = ~/public_html, app in <root> above the docroot
     * Idempotent: an existing symlink/file at the link path is replaced; a REAL directory there
     * is never clobbered (throws). Callable from `bin/tiger link:assets` and the web installer.
     *
     * @param string $webroot docroot dir where the links live (e.g. <root>/public or ~/public_html)
     * @param string $root     application root (holds vendor/)
     * @param string $theme    active theme whose assets `_theme` points at
     * @return array<string,string> link name => absolute target, for each (re)created link
     */
    public static function linkPublicAssets($webroot, $root, $theme = 'puma')
    {
        $webroot = rtrim((string) $webroot, '/');
        $root    = rtrim((string) $root, '/');
        if ($webroot === '' || !is_dir($webroot)) {
            throw new RuntimeException('linkPublicAssets: webroot not found: ' . $webroot);
        }
        $core = $root . '/vendor/webtigers/tiger-core';

        $links = [
            '_tiger' => $core . '/public',
            '_theme' => $core . '/themes/' . preg_replace('/[^a-z0-9_-]/i', '', (string) $theme) . '/assets',
        ];

        $made = [];
        foreach ($links as $name => $target) {
            if (!is_dir($target)) {
                throw new RuntimeException("linkPublicAssets: asset target not found: {$target}");
            }
            $link = $webroot . '/' . $name;
            if (is_link($link)) {
                @unlink($link);                       // replace an old/stale link
            } elseif (is_dir($link)) {
                throw new RuntimeException("linkPublicAssets: refusing to replace a real directory: {$link}");
            } elseif (file_exists($link)) {
                @unlink($link);
            }
            if (!@symlink($target, $link)) {
                throw new RuntimeException("linkPublicAssets: could not create symlink {$link} -> {$target}");
            }
            $made[$name] = $target;
        }
        return $made;
    }

    /**
     * Rotate a local.ini secret: move the CURRENT value into the retired list (deduped,
     * newest first) and set the current key to $newValue. Nothing is destroyed — the old
     * value stays available (retired) so in-flight verification/decryption keeps working
     * until the migration completes. Returns the previous value ('' if there was none).
     *
     * @return string the retired (previous) value
     */
    public static function rotateSecret($currentKey, $retiredKey, $newValue, $localIniPath = null)
    {
        $path = self::_localIniPath($localIniPath);
        if (!is_file($path)) {
            throw new RuntimeException('rotateSecret: local.ini not found at ' . $path);
        }
        $text = (string) file_get_contents($path);
        $old  = self::_readLocalValue($text, $currentKey);

        if ($old !== '') {
            $list = self::_readLocalValue($text, $retiredKey);
            $list = ($list === '') ? [] : array_map('trim', explode(',', $list));
            if (!in_array($old, $list, true)) {
                array_unshift($list, $old);           // newest retired first
            }
            $text = self::_writeLocalKey($text, $retiredKey, implode(',', $list));
        }
        $text = self::_writeLocalKey($text, $currentKey, (string) $newValue);
        file_put_contents($path, $text);
        return $old;
    }

    /** Clear a retired-secret list once migration is done (crypto | pepper | all). */
    public static function dropRetired($which = 'all', $localIniPath = null)
    {
        $path = self::_localIniPath($localIniPath);
        $text = (string) file_get_contents($path);
        $keys = [];
        if ($which === 'all' || $which === 'crypto') { $keys[] = 'tiger.crypto.key_retired'; }
        if ($which === 'all' || $which === 'pepper') { $keys[] = 'tiger.security.pepper_retired'; }
        foreach ($keys as $k) {
            $text = self::_writeLocalKey($text, $k, '');
        }
        file_put_contents($path, $text);
        return $keys;
    }

    /** Resolve the local.ini path (explicit arg, else APPLICATION_PATH default). */
    protected static function _localIniPath($localIniPath)
    {
        $path = $localIniPath ?: (defined('APPLICATION_PATH') ? APPLICATION_PATH . '/configs/local.ini' : null);
        if (!$path) {
            throw new RuntimeException('no local.ini path (APPLICATION_PATH undefined).');
        }
        return $path;
    }

    /** Read an ini key's value (unquoted) from raw text, or '' if absent/empty. */
    protected static function _readLocalValue($text, $key)
    {
        if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*=\s*"?([^"\r\n]*?)"?\s*$/m', $text, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    /** Is an ini key present with a NON-empty value in the raw text? */
    protected static function _localKeyIsSet($text, $key)
    {
        return (bool) preg_match('/^\s*' . preg_quote($key, '/') . '\s*=\s*["\']?\S/m', $text);
    }

    /** Write `key = "value"`: replace an empty declaration, else insert under [production]. */
    protected static function _writeLocalKey($text, $key, $value)
    {
        $line = $key . ' = "' . $value . '"';
        $q    = preg_quote($key, '/');
        if (preg_match('/^\s*' . $q . '\s*=.*$/m', $text)) {                       // present but empty -> replace
            return preg_replace('/^\s*' . $q . '\s*=.*$/m', $line, $text, 1);
        }
        if (preg_match('/^\[production\][^\n]*\n/m', $text, $m, PREG_OFFSET_CAPTURE)) {  // insert after [production]
            $pos = $m[0][1] + strlen($m[0][0]);
            return substr($text, 0, $pos) . $line . "\n" . substr($text, $pos);
        }
        return rtrim($text) . "\n" . $line . "\n";                                  // no section -> append
    }

    /** URL-safe slug from a name. */
    public static function slugify($value)
    {
        $value = strtolower(trim((string) $value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value);
        return trim($value, '-') ?: 'org';
    }
}
