<?php

namespace VRchessIndo\Logic;

/**
 * Minimal RFC 6238 TOTP code generator (HMAC-SHA1, 30s step, 6 digits).
 * Used to complete VRChat's authenticator-app 2FA challenge without pulling
 * in a third-party dependency for a ~20-line algorithm.
 */
class Totp
{
    public static function generate(string $base32Secret, ?int $timestamp = null, int $period = 30, int $digits = 6): string
    {
        $timestamp ??= time();
        $key = self::base32Decode($base32Secret);
        $counter = pack('N*', 0) . pack('N*', intdiv($timestamp, $period));

        $hash = hash_hmac('sha1', $counter, $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;

        $truncated = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        $code = $truncated % (10 ** $digits);
        return str_pad((string) $code, $digits, '0', STR_PAD_LEFT);
    }

    private static function base32Decode(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = strtoupper((string) preg_replace('/[^A-Za-z2-7]/', '', $secret));

        $bits = '';
        for ($i = 0; $i < strlen($secret); $i++) {
            $bits .= str_pad(decbin((int) strpos($alphabet, $secret[$i])), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        for ($i = 0; $i + 8 <= strlen($bits); $i += 8) {
            $bytes .= chr((int) bindec(substr($bits, $i, 8)));
        }

        return $bytes;
    }
}
