<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Crypto_Signature — detached Ed25519 signatures for artifact + message integrity.
 *
 * The verify half is the load-bearing one: before the Module Manager extracts a downloaded module
 * artifact it checks a detached signature (and, optionally, a SHA-256) against a publisher's public key,
 * so a tampered or MITM'd package is refused before a single file lands on disk. The sign/keypair half
 * backs the publisher tooling and makes the whole thing self-testable.
 *
 * This is INTEGRITY, not DRM: it proves an artifact is authentic and unmodified relative to the key you
 * chose to trust; it says nothing about entitlement (a separate, soft, licensing concern). Keys and
 * signatures are base64. Ed25519 via libsodium — bundled in PHP 8.1+, the same dependency Tiger_Crypto uses.
 *
 * @api
 * @see Tiger_Crypto
 */
class Tiger_Crypto_Signature
{
    /** The only algorithm this class speaks; named in signature envelopes so another can be added later. */
    const ALGO = 'ed25519';

    /**
     * Generate an Ed25519 keypair. The publisher keeps the secret key (never shipped) and publishes the
     * public key (in their vendor repo / a listing).
     *
     * @return array{public_key:string,secret_key:string} the base64-encoded keys
     */
    public static function generateKeypair(): array
    {
        $pair = sodium_crypto_sign_keypair();
        return [
            'public_key' => base64_encode(sodium_crypto_sign_publickey($pair)),
            'secret_key' => base64_encode(sodium_crypto_sign_secretkey($pair)),
        ];
    }

    /**
     * Detached-sign a message with a secret key.
     *
     * @param  string $message      the bytes to sign
     * @param  string $secretKeyB64 the base64 Ed25519 secret key
     * @return string the base64 detached signature
     * @throws InvalidArgumentException if the secret key is malformed
     */
    public static function sign(string $message, string $secretKeyB64): string
    {
        $sk = self::_decodeKey($secretKeyB64, SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, 'secret');
        return base64_encode(sodium_crypto_sign_detached($message, $sk));
    }

    /**
     * Verify a detached signature over a message. Fail-safe: any malformed input returns false and never
     * throws — a bad signature must be indistinguishable from a wrong one.
     *
     * @param  string $message      the signed bytes
     * @param  string $signatureB64 the base64 detached signature
     * @param  string $publicKeyB64 the base64 Ed25519 public key
     * @return bool true iff the signature is valid for this message and key
     */
    public static function verify(string $message, string $signatureB64, string $publicKeyB64): bool
    {
        $sig = base64_decode($signatureB64, true);
        $pk  = base64_decode($publicKeyB64, true);
        if ($sig === false || $pk === false
            || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES
            || strlen($pk)  !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }
        try {
            return sodium_crypto_sign_verify_detached($sig, $message, $pk);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Detached-sign a file's bytes. Convenience for the publisher tooling.
     *
     * @param  string $path         the file to sign
     * @param  string $secretKeyB64 the base64 secret key
     * @return string the base64 detached signature
     * @throws RuntimeException if the file can't be read
     */
    public static function signFile(string $path, string $secretKeyB64): string
    {
        return self::sign(self::_read($path), $secretKeyB64);
    }

    /**
     * Verify a file against a detached signature and, optionally, an expected SHA-256. Both must pass.
     * Fail-safe (unreadable file / malformed input → false).
     *
     * @param  string      $path         the file to verify
     * @param  string      $signatureB64 the base64 detached signature
     * @param  string      $publicKeyB64 the base64 public key
     * @param  string|null $expectSha256 optional expected lowercase-hex SHA-256 (defense in depth)
     * @return bool true iff the hash (when given) and the signature both verify
     */
    public static function verifyFile(string $path, string $signatureB64, string $publicKeyB64, ?string $expectSha256 = null): bool
    {
        if (!is_file($path) || !is_readable($path)) {
            return false;
        }
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            return false;
        }
        if ($expectSha256 !== null && !hash_equals(strtolower(trim($expectSha256)), hash('sha256', $bytes))) {
            return false;
        }
        return self::verify($bytes, $signatureB64, $publicKeyB64);
    }

    /**
     * The lowercase-hex SHA-256 of a file's bytes ('' if unreadable).
     *
     * @param  string $path the file
     * @return string the 64-char hex digest, or ''
     */
    public static function sha256File(string $path): string
    {
        return is_file($path) ? (string) hash_file('sha256', $path) : '';
    }

    /**
     * A short, human-comparable fingerprint of a public key (for a "trust this publisher?" prompt) — the
     * SHA-256 of the raw key, first 32 hex chars, grouped in 4s. '' for a malformed key.
     *
     * @param  string $publicKeyB64 the base64 public key
     * @return string e.g. "3A9F 27C1 ...", or ''
     */
    public static function fingerprint(string $publicKeyB64): string
    {
        $pk = base64_decode($publicKeyB64, true);
        if ($pk === false || strlen($pk) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return '';
        }
        return trim(implode(' ', str_split(strtoupper(substr(hash('sha256', $pk), 0, 32)), 4)));
    }

    // ---- helpers ---------------------------------------------------------------

    /** Decode + length-check a base64 key, or throw. */
    protected static function _decodeKey(string $b64, int $len, string $which)
    {
        $raw = base64_decode($b64, true);
        if ($raw === false || strlen($raw) !== $len) {
            throw new InvalidArgumentException("Malformed Ed25519 {$which} key.");
        }
        return $raw;
    }

    /** Read a file to sign, or throw. */
    protected static function _read(string $path): string
    {
        if (!is_file($path) || ($bytes = @file_get_contents($path)) === false) {
            throw new RuntimeException("Cannot read file to sign: {$path}");
        }
        return $bytes;
    }
}
