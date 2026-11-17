<?php
declare(strict_types=1);

/**
 * Telegram Bot API notifications.
 * api.telegram.org is resolved through DNS-over-HTTPS and pinned via
 * CURLOPT_RESOLVE, so the host does not need working system DNS.
 */
final class Telegram
{
    private const API_HOST = 'api.telegram.org';

    public static function enabled(): bool
    {
        return (bool) Config::get('telegram.enabled', false)
            && (string) Config::get('telegram.bot_token', '') !== ''
            && (string) Config::get('telegram.chat_id', '') !== '';
    }

    public static function notify(string $event, string $text): bool
    {
        if (!self::enabled()) return false;
        $events = array_map('trim', explode(',', (string) Config::get('telegram.events', '')));
        if (!in_array($event, $events, true) && !in_array('*', $events, true)) return false;
        return self::send($text)['ok'];
    }

    /** @return array{ok: bool, message: string} */
    public static function send(string $text): array
    {
        if (!self::enabled()) {
            return ['ok' => false, 'message' => 'Telegram не настроен: укажите токен и chat ID.'];
        }
        $token = (string) Config::get('telegram.bot_token');
        $chat  = (string) Config::get('telegram.chat_id');
        if (!preg_match('/^\d+:[A-Za-z0-9_-]{30,}$/', $token)) {
            return ['ok' => false, 'message' => 'Неверный формат токена бота.'];
        }
        if (!preg_match('/^-?\d{5,}$|^@[A-Za-z0-9_]{5,}$/', $chat)) {
            return ['ok' => false, 'message' => 'Неверный формат chat ID.'];
        }

        $text = mb_strcut($text, 0, 4000);
        $fields = http_build_query([
            'chat_id' => $chat,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ]);
        $url = 'https://' . self::API_HOST . '/bot' . $token . '/sendMessage';
        $ip = self::resolve(self::API_HOST);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $opts = [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $fields,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => 'PyHostEngine/1.0',
            ];
            if ($ip !== null) {
                // Resolves the host without touching system DNS; TLS SNI stays correct.
                $opts[CURLOPT_RESOLVE] = [self::API_HOST . ':443:' . $ip];
            }
            curl_setopt_array($ch, $opts);
            $body = curl_exec($ch);
            $error = curl_error($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($body === false) {
                return ['ok' => false, 'message' => 'cURL: ' . $error];
            }
            $data = json_decode((string) $body, true);
            if ($status === 200 && is_array($data) && !empty($data['ok'])) {
                return ['ok' => true, 'message' => 'Сообщение отправлено' . ($ip ? ' через DoH (' . $ip . ')' : '') . '.'];
            }
            return ['ok' => false, 'message' => 'Telegram API: ' . self::errorText($data, $status)];
        }

        // No curl: direct HTTPS request (system DNS is used).
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\nUser-Agent: PyHostEngine/1.0\r\n",
            'content' => $fields,
            'timeout' => 8,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        $data = json_decode((string) $body, true);
        if (is_array($data) && !empty($data['ok'])) return ['ok' => true, 'message' => 'Сообщение отправлено.'];
        return ['ok' => false, 'message' => 'Telegram API: ' . self::errorText($data, 0)];
    }

    public static function test(): array
    {
        return self::send('PyHost Engine: test notification. doh=' . (string) Config::get('telegram.doh', 'cloudflare'));
    }

    /** Resolve api.telegram.org via DNS-over-HTTPS with a one-hour cache. */
    private static function resolve(string $host): ?string
    {
        $provider = (string) Config::get('telegram.doh', 'cloudflare');
        if ($provider === 'direct') return null;

        $cacheDir = (string) Config::get('storage') . '/cache';
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
        $cacheFile = $cacheDir . '/telegram_doh.json';
        if (is_file($cacheFile)) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached) && ($cached['host'] ?? '') === $host && (int) ($cached['expires'] ?? 0) > time()
                && filter_var($cached['ip'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return (string) $cached['ip'];
            }
        }

        $url = $provider === 'google'
            ? 'https://dns.google/resolve?name=' . rawurlencode($host) . '&type=A'
            : 'https://cloudflare-dns.com/dns-query?name=' . rawurlencode($host) . '&type=A';
        $ctx = stream_context_create(['http' => [
            'timeout' => 6,
            'header' => "Accept: application/dns-json\r\nUser-Agent: PyHostEngine/1.0\r\n",
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        $data = json_decode((string) $body, true);
        $ip = null;
        foreach (($data['Answer'] ?? []) as $answer) {
            if (($answer['type'] ?? 0) === 1 && filter_var($answer['data'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $ip = $answer['data'];
                break;
            }
        }
        if ($ip !== null) {
            @file_put_contents($cacheFile, json_encode([
                'host' => $host, 'ip' => $ip, 'expires' => time() + 3600,
            ]));
        }
        return $ip;
    }

    private static function errorText(?array $data, int $status): string
    {
        if (is_array($data)) {
            return (string) ($data['description'] ?? ($data['message'] ?? json_encode($data, JSON_UNESCAPED_UNICODE)));
        }
        return 'HTTP ' . $status;
    }
}
