<?php
declare(strict_types=1);

/** RFC 6238 TOTP (SHA1, 6 digits, 30-second step) without external libraries. */
final class TOTP
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    public static function code(string $secret, ?int $timestamp = null): string
    {
        $counter = intdiv($timestamp ?? time(), 30);
        $key = self::base32Decode($secret);
        $packed = pack('N2', ($counter >> 32) & 0xffffffff, $counter & 0xffffffff);
        $hash = hash_hmac('sha1', $packed, $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $binary = (ord($hash[$offset]) & 0x7f) << 24
                | (ord($hash[$offset + 1]) & 0xff) << 16
                | (ord($hash[$offset + 2]) & 0xff) << 8
                | (ord($hash[$offset + 3]) & 0xff);
        return str_pad((string) ($binary % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /** Accepts ±1 step (90 seconds total) to tolerate clock drift. */
    public static function verify(string $secret, string $code, int $window = 1): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== 6) return false;
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::code($secret, time() + $i * 30), $code)) return true;
        }
        return false;
    }

    public static function provisioningUri(string $secret, string $label): string
    {
        $label = rawurlencode($label);
        return "otpauth://totp/{$label}?secret={$secret}&issuer=PyHostEngine&algorithm=SHA1&digits=6&period=30";
    }

    private static function base32Encode(string $data): string
    {
        $bits = '';
        foreach (unpack('C*', $data) ?: [] as $byte) {
            $bits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    private static function base32Decode(string $input): string
    {
        $input = strtoupper(str_replace([' ', '-', '='], '', $input));
        $bits = '';
        for ($i = 0, $n = strlen($input); $i < $n; $i++) {
            $pos = strpos(self::ALPHABET, $input[$i]);
            if ($pos === false) return '';
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) $bytes .= chr(bindec($chunk));
        }
        return $bytes;
    }
}
