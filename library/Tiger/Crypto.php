<?php
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
 * The KEY lives in config (`tiger.crypto.key`, base64 of 32 bytes) — set in local.ini
 * or a secrets manager, NEVER committed, and NEVER in the DB (that's where the
 * ciphertext is; co-locating the key would defeat the purpose). Rotating the key
 * invalidates stored secrets — users re-enroll TOTP; that's the intended blast radius.
 *
 * @api
 */
class Tiger_Crypto
{
    /** Encrypt a plaintext string; returns a base64 blob safe for a text/VARBINARY column. */
    public static function encrypt($plaintext)
    {
        $key    = self::key();
        $nonce  = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox((string) $plaintext, $nonce, $key);
        return base64_encode($nonce . $cipher);
    }

    /**
     * Decrypt a blob produced by encrypt(). Throws on a missing key, malformed input,
     * or a failed authentication tag (tampered/rotated key) — callers treat any throw
     * as "secret unusable" (e.g. TOTP verification fails closed).
     *
     * @throws RuntimeException
     */
    public static function decrypt($blob)
    {
        $key = self::key();
        $raw = base64_decode((string) $blob, true);
        $min = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES;
        if ($raw === false || strlen($raw) < $min) {
            throw new RuntimeException('Tiger_Crypto: malformed ciphertext');
        }
        $nonce  = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain  = sodium_crypto_secretbox_open($cipher, $nonce, $key);
        if ($plain === false) {
            throw new RuntimeException('Tiger_Crypto: decryption failed (bad key or tampered data)');
        }
        return $plain;
    }

    /** True when a usable key is configured — lets callers gate features (e.g. TOTP enrollment). */
    public static function isConfigured()
    {
        try {
            self::key();
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Mint a fresh base64 key for local.ini / a secrets manager. Handy for install/CLI. */
    public static function generateKey()
    {
        return base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    /**
     * The raw 32-byte key, decoded from `tiger.crypto.key`.
     *
     * @throws RuntimeException when unset or malformed — encryption must fail loudly,
     *         never silently store plaintext.
     */
    protected static function key()
    {
        $cfg = Zend_Registry::isRegistered('Zend_Config') ? Zend_Registry::get('Zend_Config') : null;
        $b64 = '';
        if ($cfg && $cfg->get('tiger') && $cfg->tiger->get('crypto')) {
            $b64 = (string) $cfg->tiger->crypto->get('key');
        }
        if ($b64 === '') {
            throw new RuntimeException('tiger.crypto.key is not configured (set a base64 32-byte key in local.ini)');
        }
        $key = base64_decode($b64, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('tiger.crypto.key must be base64 of exactly ' . SODIUM_CRYPTO_SECRETBOX_KEYBYTES . ' bytes');
        }
        return $key;
    }
}
