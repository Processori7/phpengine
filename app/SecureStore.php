<?php
declare(strict_types=1);

/** Encrypts values that must never be readable from a leaked database file. */
final class SecureStore
{
    private static ?string $key = null;

    public static function encrypt(?string $plain): string
    {
        if ($plain === null || $plain === '') return '';
        if (!function_exists('openssl_encrypt')) return $plain;
        $key = self::key();
        if ($key === null) return $plain;
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) return $plain;
        return 'enc1:' . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $stored): string
    {
        if (!str_starts_with($stored, 'enc1:') || !function_exists('openssl_decrypt')) return $stored;
        $key = self::key();
        if ($key === null) return '';
        $raw = base64_decode(substr($stored, 5), true);
        if ($raw === false || strlen($raw) < 28) return '';
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? '' : $plain;
    }

    public static function keyPath(): string
    {
        return (string) Config::get('storage') . '/db/app.key';
    }

    private static function key(): ?string
    {
        if (self::$key !== null) return self::$key;
        $path = self::keyPath();
        if (!is_dir(dirname($path))) @mkdir(dirname($path), 0700, true);
        if (!is_file($path)) {
            if (@file_put_contents($path, base64_encode(random_bytes(32))) === false) return null;
        }
        @chmod($path, 0600);
        $raw = base64_decode(trim((string) file_get_contents($path)), true);
        if ($raw === false || strlen($raw) !== 32) return null;
        self::$key = $raw;
        return $raw;
    }
}
