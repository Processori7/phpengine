<?php
declare(strict_types=1);

/** Cross-cutting security helpers. */
final class Security
{
    private const SAFE_EXTENSIONS = [
        'py', 'pyw', 'txt', 'md', 'json', 'jsonl', 'csv', 'tsv', 'xml', 'yaml', 'yml',
        'toml', 'ini', 'cfg', 'conf', 'env', 'log', 'sh', 'bat', 'sql', 'html', 'htm',
        'css', 'js', 'jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'ico', 'pdf', 'lock',
    ];

    public static function setHeaders(): void
    {
        header("Content-Security-Policy: default-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'; style-src 'self' 'unsafe-inline'; script-src 'self'; img-src 'self' data:; connect-src 'self'");
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), usb=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('X-Permitted-Cross-Domain-Policies: none');
        header('X-XSS-Protection: 1; mode=block');
        header('Cache-Control: no-store');
        if (Config::get('security.hide_server_header', true)) {
            header_remove('X-Powered-By');
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        if ($https) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    /** HTML-escape every dynamic value going into a template. */
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    public static function randomToken(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    public static function saltedHash(string $token, string $salt): string
    {
        return hash_hmac('sha256', $token, $salt);
    }

    private static ?string $requestToken = null;

    public static function csrfToken(): string
    {
        if (!self::csrfOn()) return '';
        if (self::$requestToken !== null) return self::$requestToken;

        $ttl = max(60, (int) Config::get('security.csrf_ttl', 3600));
        $expired = empty($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])
            || (int) ($_SESSION['_csrf_at'] ?? 0) + $ttl < time();
        $newRequest = Config::get('security.csrf_rotation', 'session') === 'request'
            && !empty($_SESSION['_csrf_rendered']);
        if ($expired || $newRequest) {
            self::rotateCsrf();
        }
        $_SESSION['_csrf_rendered'] = true;
        self::$requestToken = (string) $_SESSION['_csrf'];
        return self::$requestToken;
    }

    public static function rotateCsrf(): string
    {
        $_SESSION['_csrf'] = self::randomToken();
        $_SESSION['_csrf_at'] = time();
        self::$requestToken = $_SESSION['_csrf'];
        return self::$requestToken;
    }

    private static function csrfOn(): bool
    {
        return (bool) Config::get('security.csrf_enabled', true);
    }

    public static function csrfValid(?string $token): bool
    {
        if (!self::csrfOn()) return true;
        $ttl = max(60, (int) Config::get('security.csrf_ttl', 3600));
        return is_string($token)
            && !empty($_SESSION['_csrf'])
            && (int) ($_SESSION['_csrf_at'] ?? 0) + $ttl >= time()
            && hash_equals((string) $_SESSION['_csrf'], $token);
    }

    /** Aborts with 419 when the CSRF token is absent or invalid. */
    public static function requireCsrf(): void
    {
        if (!self::csrfOn()) return;
        $token = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (!self::csrfValid(is_string($token) ? $token : null)) {
            Security::audit('csrf_rejected');
            Telegram::notify('security', '⚠️ Отклонён запрос без валидного CSRF-токена (IP ' . Security::clientIp() . ')');
            http_response_code(419);
            exit('CSRF token mismatch. Please reload the page and try again.');
        }
        if (Config::get('security.csrf_rotation', 'session') === 'request') {
            self::rotateCsrf();
        }
    }

    /** Fixed-window rate limiter backed by the configured database. */
    public static function rateLimit(string $key, int $limit, int $window): bool
    {
        if (random_int(1, 100) === 1) {
            Database::run('DELETE FROM rate_limits WHERE reset_at < ?', [time() - 86400]);
        }
        $now  = time();
        $k    = Database::q('key');
        $row  = Database::one('SELECT hits, reset_at FROM rate_limits WHERE ' . $k . ' = ?', [$key]);
        if ($row !== null && (int) $row['reset_at'] <= $now) {
            Database::run('DELETE FROM rate_limits WHERE ' . $k . ' = ?', [$key]);
            $row = null;
        }
        if ($row === null) {
            Database::upsert('rate_limits', [
                'key'     => $key,
                'hits'    => 1,
                'reset_at'=> $now + $window,
            ], 'key');
            return true;
        }
        if ((int) $row['hits'] >= $limit) {
            return false;
        }
        Database::run('UPDATE rate_limits SET hits = hits + 1 WHERE ' . $k . ' = ?', [$key]);
        return true;
    }

    public static function clientIp(): string
    {
        // Deliberately ignore X-Forwarded-For: it is trivially spoofed.
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        return preg_match('/^[\d.:a-fA-F]{3,45}$/', $ip) ? $ip : 'unknown';
    }

    public static function userAgentAllowed(): bool
    {
        if (!Config::get('security.require_useragent', true)) return true;
        $ua = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if ($ua === '' || strlen($ua) > 512 || preg_match('/[\x00-\x1f\x7f]/', $ua)) return false;
        foreach (array_map('trim', explode(',', (string) Config::get('security.blocked_useragents', ''))) as $needle) {
            if ($needle !== '' && stripos($ua, $needle) !== false) return false;
        }
        return true;
    }

    public static function ipAllowed(): bool
    {
        $raw = trim((string) Config::get('security.ip_whitelist', ''));
        if ($raw === '') {
            return true;
        }
        $ip = self::clientIp();
        foreach (array_map('trim', explode(',', $raw)) as $entry) {
            if ($entry === '' || $entry === '*') {
                return true;
            }
            if ($entry === $ip) {
                return true;
            }
            // Basic IPv4 CIDR support (e.g. 192.168.1.0/24).
            if (str_contains($entry, '/') && str_contains($entry, '.') && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                [$network, $bits] = explode('/', $entry, 2);
                $bits = (int) $bits;
                if (filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && $bits >= 0 && $bits <= 32) {
                    $mask = $bits === 0 ? 0 : (-1 << (32 - $bits));
                    if ((ip2long($ip) & $mask) === (ip2long($network) & $mask)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /** Strips dangerous characters, keeps short unicode names readable. */
    public static function sanitizeFilename(string $name): string
    {
        $name = str_replace(['\\', "\0", "\r", "\n"], ['/', '', '', ''], basename(trim($name)));
        $name = preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', $name) ?? 'file';
        $name = trim($name, '. ');
        if ($name === '' || $name === '.' || $name === '..') {
            return 'file_' . self::randomToken(6);
        }
        return mb_substr($name, 0, 120);
    }

    public static function allowedExtension(string $name): bool
    {
        $base = pathinfo($name, PATHINFO_BASENAME);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === '' && str_starts_with($base, '.') && str_contains($base, '.')) {
            $ext = strtolower(substr($base, strrpos($base, '.') + 1));
        }
        return $ext !== '' && in_array($ext, self::SAFE_EXTENSIONS, true);
    }

    /** Resolves $relative inside $base and refuses to escape it (also rejects symlinks). */
    public static function safePath(string $base, string $relative): ?string
    {
        $baseReal = realpath($base);
        if ($baseReal === false) {
            return null;
        }
        $candidate = $base . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
        $real = realpath($candidate);
        if ($real === false) {
            // File may not exist yet: validate the lexical path and its parent.
            $parent = realpath(dirname($candidate));
            if ($parent === false || !self::isInside($baseReal, $parent)) {
                return null;
            }
            return $candidate;
        }
        if (!self::isInside($baseReal, $real) || is_link($real)) {
            return null;
        }
        return $real;
    }

    public static function isInside(string $base, string $path): bool
    {
        $base = rtrim(str_replace('\\', '/', $base), '/');
        $path = str_replace('\\', '/', $path);
        return $path === $base || str_starts_with($path, $base . '/');
    }

    public static function audit(string $action, string $detail = ''): void
    {
        Database::run(
            'INSERT INTO audit(user_id, action, detail, ip, created_at) VALUES (?, ?, ?, ?, ?)',
            [$_SESSION['user_id'] ?? null, $action, mb_substr($detail, 0, 500), self::clientIp(), time()]
        );
    }

    /** Removes control characters so user input cannot forge log lines or terminals. */
    public static function logSafe(string $value): string
    {
        $value = preg_replace('/[\x00-\x1f\x7f]/', ' ', $value) ?? '';
        return mb_substr($value, 0, 1000);
    }

    /** Rejects shell metacharacters before anything reaches proc_open. */
    public static function safeArgument(string $arg): bool
    {
        if ($arg === '' || strlen($arg) > 512 || preg_match('/[\x00-\x1f\x7f]/', $arg)) {
            return false;
        }
        // proc_open is called with an argv array (no shell), so command substitution is
        // impossible. These characters are still blocked as defence in depth and to
        // keep user input predictable; code passed to `python -c` keeps its brackets.
        return !preg_match('/[;&|`$<>]/', $arg);
    }

    /**
     * Validates a whole command line, allowing metacharacters only inside quotes.
     *
     * The command is tokenised into an argv array and every argument is quoted
     * again by Process::commandString(), so a `;` or `$` inside a quoted segment
     * is inert in every execution mode. Unquoted metacharacters stay forbidden,
     * which keeps `python app.py; rm -rf /` impossible.
     */
    public static function safeCommand(string $raw): bool
    {
        $raw = trim($raw);
        if ($raw === '' || strlen($raw) > 2048 || preg_match('/[\x00-\x1f\x7f]/', $raw)) {
            return false;
        }
        $quote = null;
        $len = strlen($raw);
        for ($i = 0; $i < $len; $i++) {
            $ch = $raw[$i];
            if ($quote !== null) {
                // A backslash escapes the next character inside double quotes.
                if ($ch === '\\' && $quote === '"') {
                    $i++;
                    continue;
                }
                if ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($ch === '"' || $ch === "'") {
                $quote = $ch;
                continue;
            }
            if (strpos(';&|`$<>', $ch) !== false) {
                return false;
            }
        }
        return $quote === null; // unbalanced quotes are rejected by tokenize() anyway
    }
}
