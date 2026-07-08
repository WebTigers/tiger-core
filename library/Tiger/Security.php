<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Security — the application PEPPER (a keyed secret mixed into hashes).
 *
 * SALTS are already handled per-record: bcrypt (password_hash) mints a random salt per
 * password. A PEPPER is different — one secret, shared by the whole install, kept OUT of
 * the database (local.ini / a secrets manager), and HMAC'd into a value before it's
 * hashed. The payoff: a stolen `user`/`user_credential` table is useless on its own —
 * without the pepper you can't even start cracking, because every stored hash was keyed
 * by a secret the DB never held. It also lifts short, low-entropy codes (a 6-digit OTP,
 * a 10-char recovery code) out of offline brute-force range.
 *
 * ROTATION. Because a hash is ONE-WAY you can't re-pepper it without the plaintext, so
 * the pepper rotates LAZILY: the CURRENT pepper is `tiger.security.pepper`; the previous
 * one(s) go to `tiger.security.pepper_retired` (comma-separated). New hashes use the
 * current pepper; VERIFY tries current-then-retired(-then-legacy-raw) and re-hashes to the
 * current pepper on any non-current match — so passwords migrate as users sign in, and the
 * retired pepper can be dropped once traffic has turned everyone over (force-reset any
 * stragglers). Contrast Tiger_Crypto, whose reversible data is re-encrypted eagerly.
 *
 * With NO pepper configured every method degrades to exactly the legacy behavior
 * (`password_hash($p)` / `hash('sha256', $code)`), so existing installs are unaffected.
 *
 * @api
 */
class Tiger_Security
{
    /**
     * Prepare a password for password_hash(): HMAC-then-base64 with the CURRENT pepper,
     * else the raw password (no-pepper path). base64 of the 32-byte HMAC is 44 chars —
     * under bcrypt's 72-byte limit and NUL-safe (so long passwords aren't truncated).
     */
    public static function prehashPassword($plain)
    {
        $pepper = self::current();
        if ($pepper === '') {
            return (string) $plain;
        }
        return self::_prehash($plain, $pepper);
    }

    /**
     * Every password form to try on VERIFY, in order: current pepper, each retired
     * pepper, then legacy raw. The caller matches against the stored bcrypt hash and,
     * if the match is NOT the first (current) form, re-hashes to the current scheme.
     *
     * @return string[]
     */
    public static function passwordVerifiers($plain)
    {
        $out = [];
        foreach (self::peppers() as $pepper) {
            $out[] = self::_prehash($plain, $pepper);
        }
        $out[] = (string) $plain;   // legacy: hashed with no pepper
        return $out;
    }

    /**
     * Keyed hash for short secret CODES (OTP / reset / recovery), using the CURRENT
     * pepper (per-context subkey), else plain sha256. `$context` domain-separates so the
     * same code hashes differently as a recovery code vs a login challenge.
     *
     * @return string 64-char hex (same shape/length as the legacy sha256).
     */
    public static function hashCode($code, $context = '')
    {
        $pepper = self::current();
        if ($pepper === '') {
            return hash('sha256', (string) $code);
        }
        return self::_codeHash($code, $context, $pepper);
    }

    /**
     * Constant-time check of a code against a stored hash, trying the current pepper, each
     * retired pepper, then the legacy sha256 — so a code issued before a rotation still
     * redeems. (Transient codes could just expire, but recovery codes are longer-lived.)
     */
    public static function codeMatches($code, $context, $storedHash)
    {
        $storedHash = (string) $storedHash;
        $matched = false;
        foreach (self::peppers() as $pepper) {
            // hash_equals every candidate (no early return) to avoid timing leaks.
            $matched = hash_equals($storedHash, self::_codeHash($code, $context, $pepper)) || $matched;
        }
        $matched = hash_equals($storedHash, hash('sha256', (string) $code)) || $matched;
        return $matched;
    }

    /** Is a pepper configured? (Drives the graceful-migration fallbacks in the callers.) */
    public static function hasPepper()
    {
        return self::current() !== '';
    }

    /** Mint a fresh pepper for local.ini / a secrets manager (install + rotate). */
    public static function generatePepper()
    {
        return base64_encode(random_bytes(32));
    }

    // ----- internals ---------------------------------------------------------

    /** HMAC-then-base64 of a password under a specific pepper. */
    protected static function _prehash($plain, $pepper)
    {
        return base64_encode(hash_hmac('sha256', (string) $plain, $pepper, true));
    }

    /** Per-context keyed hash of a code under a specific pepper (HKDF-style split). */
    protected static function _codeHash($code, $context, $pepper)
    {
        $subkey = hash_hmac('sha256', 'code:' . $context, $pepper, true);
        return hash_hmac('sha256', (string) $code, $subkey);
    }

    /** The current pepper ('' when unset). */
    protected static function current()
    {
        return self::_cfg('pepper');
    }

    /** All peppers in try-order: current first, then retired. */
    protected static function peppers()
    {
        $out = [];
        foreach (array_merge([self::current()], self::_cfgList('pepper_retired')) as $pepper) {
            if ($pepper !== '') {
                $out[] = $pepper;
            }
        }
        return $out;
    }

    /** A single tiger.security.* value ('' if unset). */
    protected static function _cfg($name)
    {
        $cfg = Zend_Registry::isRegistered('Zend_Config') ? Zend_Registry::get('Zend_Config') : null;
        if ($cfg && $cfg->get('tiger') && $cfg->tiger->get('security')) {
            return (string) $cfg->tiger->security->get($name);
        }
        return '';
    }

    /** A comma-separated tiger.security.* value as a trimmed, non-empty list. */
    protected static function _cfgList($name)
    {
        $val = self::_cfg($name);
        return $val === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $val))));
    }
}
