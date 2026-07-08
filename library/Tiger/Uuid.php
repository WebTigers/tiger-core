<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * UUID generation for Tiger primary keys.
 *
 * WHY UUIDs (not auto-increment integers) for the substrate PKs:
 *   - Multi-tenant / distributed friendliness: IDs can be minted anywhere (app,
 *     CLI, an import job, another service) with no DB round-trip and no collision
 *     risk. Auto-increment forces a round-trip and leaks row counts.
 *   - Safe to expose in URLs/APIs: reveals nothing (no "org #5" enumeration).
 *   - Stable across environments: copy a row between dev/staging/prod and its
 *     identity is preserved.
 *
 * WHY v7 IS THE DEFAULT (Tiger_Uuid::generate() -> v7):
 *   - Write performance / index locality. v4 is fully random, so every insert
 *     lands at a random spot in the PK B-tree -> page splits, fragmentation, poor
 *     cache behavior. v7's leading 48 bits are a millisecond timestamp, so inserts
 *     append near the right edge of the index (like auto-increment). You get UUID's
 *     benefits WITHOUT v4's write penalty.
 *   - Natural time-ordering. Rows sort chronologically by PK (ORDER BY id ~=
 *     ORDER BY created_at), and you can read a row's creation time off its ID
 *     (see timeOf()) with no join and no extra column.
 *
 * WHEN TO USE v4 INSTEAD:
 *   - v7 embeds (leaks) its creation time in those first 48 bits. For anything
 *     where timing should be OPAQUE — session/invite/reset tokens, API keys —
 *     use v4. Rule of thumb: entities -> v7, secrets/tokens -> v4.
 *
 * STORAGE: we store UUIDs as CHAR(36) canonical text (lowercase, hyphenated),
 * not BINARY(16). Text is portable across every MySQL/MariaDB version and is
 * human-readable in the console/logs (a big win when an AI or human is
 * debugging). bin2hex() below already emits lowercase — the RFC-4122 canonical
 * form — so IDs are lowercase by construction.
 *
 * @api
 */
class Tiger_Uuid
{
    /**
     * The default generator. Returns a v7 (time-ordered) UUID. Use this for
     * entity primary keys unless you specifically need opaque timing (then v4()).
     *
     * @return string 36-char lowercase canonical UUID
     */
    public static function generate()
    {
        return self::v7();
    }

    /**
     * RFC-9562 version-7 (time-ordered) UUID.
     *
     * Layout (128 bits):
     *   bits  0..47  : Unix time in milliseconds, big-endian (6 bytes)
     *   bits 48..51  : version = 0111 (7)
     *   bits 52..63  : rand_a (random)
     *   bits 64..65  : variant = 10
     *   bits 66..127 : rand_b (random)
     *
     * We fill the sub-millisecond bits with randomness (RFC "method 1"), which is
     * sufficient for index locality and to-the-millisecond ordering. We do NOT
     * guarantee strict monotonic ordering for two IDs minted within the same
     * millisecond — that would need a counter and isn't worth the complexity here.
     *
     * NOTE: assumes 64-bit PHP (universal on 8.1+); the ms timestamp fits in 48
     * bits until the year ~10889.
     *
     * @return string 36-char lowercase canonical UUID
     */
    public static function v7()
    {
        $ms = (int) floor(microtime(true) * 1000);

        // 48-bit big-endian timestamp: pack as 64-bit BE and drop the top 2 (zero) bytes.
        $timestamp = substr(pack('J', $ms), 2);      // 6 bytes
        $bytes     = $timestamp . random_bytes(10);  // + 10 random bytes = 16

        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x70); // version 7
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // variant 10

        return self::format($bytes);
    }

    /**
     * RFC-4122 version-4 (random) UUID. Use for tokens / IDs whose creation time
     * must not be inferable. Uses random_bytes() (CSPRNG) so values are unguessable.
     *
     * @return string 36-char lowercase canonical UUID
     */
    public static function v4()
    {
        $bytes = random_bytes(16);

        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // variant 10

        return self::format($bytes);
    }

    /**
     * Extract the creation time embedded in a v7 UUID. Only meaningful for v7
     * (v4 has no timestamp — you'll get garbage). Demonstrates v7's payoff: the
     * "when" is free, no created_at join required.
     *
     * @param  string $uuid a v7 UUID
     * @return float  Unix timestamp in seconds (millisecond precision)
     */
    public static function timeOf($uuid)
    {
        $hex = substr(str_replace('-', '', $uuid), 0, 12); // first 48 bits
        return hexdec($hex) / 1000;
    }

    /**
     * Loosely validate a canonical UUID string (any version). Handy for guarding
     * route params / API input before hitting the DB.
     */
    public static function isValid($value)
    {
        return is_string($value)
            && (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
    }

    /** Format 16 raw bytes as a lowercase, hyphenated canonical UUID. */
    private static function format($bytes)
    {
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
