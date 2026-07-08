<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Crypto — authenticated symmetric encryption for reversible secrets at rest.
 *
 * Some auth factors store a secret that must be RECOVERED, not just verified: a TOTP
 * shared secret (needed to compute the expected code) and OAuth refresh tokens. A
 * password hash won't do — these have to come back out. So they're encrypted with an
 * app-held key before hitting `user_credential.secret`.
 *
 * Uses libsodium's `crypto_secretbox` (XSalsa20-Poly1305) — bundled in PHP 8.1+, no
 * extension to install. Output is authenticated (tamper-evident) and carries a random
 * per-message nonce, base64-wrapped as `nonce . ciphertext` for text-column storage.
 *
 * KEY ROTATION (see also Tiger_Security for the pepper): the CURRENT key is
 * `tiger.crypto.key`; during a rotation you keep the old key(s) in
 * `tiger.crypto.key_retired` (comma-separated). Encryption always uses the current key;
 * decryption tries the current key then each retired key, so nothing breaks mid-rotation.
 * `tiger crypto:rekey` then re-encrypts every stored secret under the current key
 * (reencrypt()), after which the retired key can be removed. Because this data is
 * REVERSIBLE, rotation is lossless — unlike the pepper, which migrates lazily on login.
 *
 * Keys live ONLY in local.ini / a secrets manager, NEVER the DB (that's where the
 * ciphertext is) and NEVER the repo.
 *
 * @api
 */
class Tiger_Crypto
{
    /** Encrypt a plaintext string under the CURRENT key; base64 blob safe for a text column. */
    public static function encrypt($plaintext)
    {
        $nonce  = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox((string) $plaintext, $nonce, self::primaryKey());
        return base64_encode($nonce . $cipher);
    }

    /**
     * Decrypt a blob from encrypt(), trying the current key then any retired keys (so a
     * rotation window Just Works). Throws on a missing key, malformed input, or when no
     * configured key authenticates — callers treat any throw as "secret unusable".
     *
     * @throws RuntimeException
     */
    public static function decrypt($blob)
    {
        $raw = base64_decode((string) $blob, true);
        $min = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES;
        if ($raw === false || strlen($raw) < $min) {
            throw new RuntimeException('Tiger_Crypto: malformed ciphertext');
        }
        $nonce  = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        foreach (self::keys() as $key) {
            $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
            if ($plain !== false) {
                return $plain;
            }
        }
        throw new RuntimeException('Tiger_Crypto: decryption failed (no configured key matched)');
    }

    /** Re-encrypt a blob under the CURRENT key (decrypting via current-or-retired). For rekey. */
    public static function reencrypt($blob)
    {
        return self::encrypt(self::decrypt($blob));
    }

    /** True when a usable current key is configured — lets callers gate features (TOTP enrollment). */
    public static function isConfigured()
    {
        try {
            self::primaryKey();
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Mint a fresh base64 key for local.ini / a secrets manager. Handy for install/rotate. */
    public static function generateKey()
    {
        return base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    /** The current key (used for encryption). @throws RuntimeException when unset/malformed. */
    protected static function primaryKey()
    {
        return self::keys()[0];
    }

    /**
     * All usable raw keys in try-order: current first, then retired. Malformed entries are
     * skipped; an empty set is a hard error (encryption must never silently no-op).
     *
     * @throws RuntimeException
     */
    protected static function keys()
    {
        $out = [];
        foreach (array_merge([self::_cfg('key')], self::_cfgList('key_retired')) as $b64) {
            if ($b64 === '') {
                continue;
            }
            $key = base64_decode($b64, true);
            if ($key !== false && strlen($key) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                $out[] = $key;
            }
        }
        if (!$out) {
            throw new RuntimeException('tiger.crypto.key is not configured (set a base64 32-byte key in local.ini)');
        }
        return $out;
    }

    /** A single tiger.crypto.* value ('' if unset). */
    protected static function _cfg($name)
    {
        $cfg = Zend_Registry::isRegistered('Zend_Config') ? Zend_Registry::get('Zend_Config') : null;
        if ($cfg && $cfg->get('tiger') && $cfg->tiger->get('crypto')) {
            return (string) $cfg->tiger->crypto->get($name);
        }
        return '';
    }

    /** A comma-separated tiger.crypto.* value as a trimmed, non-empty list. */
    protected static function _cfgList($name)
    {
        $val = self::_cfg($name);
        return $val === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $val))));
    }
}
