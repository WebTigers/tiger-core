<?php
/**
 * Tiger_Auth_Totp — RFC 6238 time-based one-time passwords (the "authenticator app"
 * factor), dependency-free.
 *
 * Hand-rolled rather than pulled from Composer for the same reason the SES SigV4
 * signer is: TOTP is small, frozen (the RFC hasn't moved since 2011), and universally
 * interoperable (Google Authenticator, Authy, 1Password, Microsoft Authenticator all
 * speak the same SHA-1 / 6-digit / 30-second defaults). Owning ~80 lines beats owning
 * a dependency.
 *
 * The shared secret is base32 (RFC 4648) — that's what authenticator apps expect in
 * the `otpauth://` URI and in manual entry. Storage/encryption of the secret is NOT
 * this class's job (see Tiger_Crypto + Tiger_Model_UserCredential); this is pure math.
 *
 * @api
 */
class Tiger_Auth_Totp
{
    /** Authenticator-app defaults. Changing these breaks compatibility with issued secrets. */
    const PERIOD = 30;      // seconds per code
    const DIGITS = 6;       // code length
    const ALGO   = 'sha1';  // HMAC algorithm (the near-universal default)

    /** RFC 4648 base32 alphabet (no padding used for secrets). */
    const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * A fresh random shared secret, base32-encoded. 20 bytes = 160 bits = the RFC 4226
     * recommended HMAC-SHA1 key length; encodes to 32 base32 chars.
     */
    public static function generateSecret($bytes = 20)
    {
        return self::base32Encode(random_bytes((int) $bytes));
    }

    /**
     * The `otpauth://totp/...` provisioning URI an authenticator app consumes (via QR
     * or manual paste). `issuer` and `account` are shown to the user in their app.
     */
    public static function uri($secret, $account, $issuer)
    {
        // Label is "Issuer:account", each component percent-encoded (RFC spec).
        $label = rawurlencode((string) $issuer) . ':' . rawurlencode((string) $account);
        $query = http_build_query([
            'secret'    => (string) $secret,
            'issuer'    => (string) $issuer,
            'algorithm' => strtoupper(self::ALGO),
            'digits'    => self::DIGITS,
            'period'    => self::PERIOD,
        ]);
        return 'otpauth://totp/' . $label . '?' . $query;
    }

    /**
     * Verify a user-entered code against the secret, tolerating +/- $window steps of
     * clock drift (1 = accept the previous, current, and next 30s code — the common
     * default). Constant-time compared.
     *
     * @param  string   $secret base32 shared secret
     * @param  string   $code   the 6 digits the user typed
     * @param  int      $window drift tolerance in periods
     * @param  int|null $at     unix time to evaluate at (null = now; for tests)
     * @return bool
     */
    public static function verify($secret, $code, $window = 1, $at = null)
    {
        $code = preg_replace('/\D/', '', (string) $code);
        if (strlen($code) !== self::DIGITS) {
            return false;
        }
        $at      = ($at === null) ? time() : (int) $at;
        $counter = intdiv($at, self::PERIOD);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::codeAt($secret, $counter + $i), $code)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The HOTP value for a given counter (RFC 4226 §5.3 dynamic truncation), as a
     * zero-padded DIGITS-length string.
     */
    public static function codeAt($secret, $counter)
    {
        $key = self::base32Decode($secret);
        // 8-byte big-endian counter. Realistic counters fit in 32 bits, so the high
        // word is zero — avoids relying on pack('J') (64-bit-only) for portability.
        $bin  = "\0\0\0\0" . pack('N', (int) $counter);
        $hash = hash_hmac(self::ALGO, $bin, $key, true);

        $offset = ord($hash[strlen($hash) - 1]) & 0x0f;      // low nibble of last byte
        $value  = (unpack('N', substr($hash, $offset, 4))[1]) & 0x7fffffff;  // 31-bit
        $otp    = $value % (10 ** self::DIGITS);
        return str_pad((string) $otp, self::DIGITS, '0', STR_PAD_LEFT);
    }

    /** Encode raw bytes to base32 (no padding). */
    public static function base32Encode($raw)
    {
        $out = '';
        $buffer = 0;
        $bits = 0;
        $len = strlen($raw);
        for ($i = 0; $i < $len; $i++) {
            $buffer = ($buffer << 8) | ord($raw[$i]);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $out .= self::B32[($buffer >> $bits) & 0x1f];
            }
        }
        if ($bits > 0) {
            $out .= self::B32[($buffer << (5 - $bits)) & 0x1f];   // pad the final partial group
        }
        return $out;
    }

    /** Decode a base32 string to raw bytes (ignores spaces/padding/case). */
    public static function base32Decode($b32)
    {
        $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', (string) $b32));
        $map = array_flip(str_split(self::B32));
        $out = '';
        $buffer = 0;
        $bits = 0;
        $len = strlen($b32);
        for ($i = 0; $i < $len; $i++) {
            $buffer = ($buffer << 5) | $map[$b32[$i]];
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $out .= chr(($buffer >> $bits) & 0xff);
            }
        }
        return $out;
    }
}
