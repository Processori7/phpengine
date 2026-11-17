<?php
declare(strict_types=1);

/**
 * Public web surface of a project.
 *
 * - serveStatic(): publishes HTML/CSS/JS from the project as a real site. Works on
 *   any host, because PHP only reads files — no process is involved.
 * - runCgi(): runs the project script once per request, CGI style, so a Python
 *   backend can live on shared hosting. Requires proc_open on the host.
 */
final class WebApp
{
    /** Extensions a public site may serve. Anything else is a 404. */
    private const STATIC_TYPES = [
        'html' => 'text/html; charset=utf-8',
        'htm'  => 'text/html; charset=utf-8',
        'css'  => 'text/css; charset=utf-8',
        'js'   => 'text/javascript; charset=utf-8',
        'mjs'  => 'text/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'map'  => 'application/json; charset=utf-8',
        'xml'  => 'application/xml; charset=utf-8',
        'txt'  => 'text/plain; charset=utf-8',
        'svg'  => 'image/svg+xml',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'ico'  => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'  => 'font/ttf',
        'otf'  => 'font/otf',
        'pdf'  => 'application/pdf',
        'wasm' => 'application/wasm',
        'webmanifest' => 'application/manifest+json',
        'mp4'  => 'video/mp4',
        'webm' => 'video/webm',
        'mp3'  => 'audio/mpeg',
    ];

    public static function serveStatic(string $slug, string $path): void
    {
        if (!Config::get('security.site_enabled', true)) self::notFound();
        $project = self::project($slug);
        if ($project === null) self::notFound();

        $root = self::siteRoot($project);
        if ($root === null) self::notFound();

        $relative = self::normalise($path);
        if ($relative === null) self::notFound();

        if ($relative === '' || str_ends_with($relative, '/')) {
            // Directory request: only index.html is a valid answer. Redirecting
            // again here would loop, because the route already saw the slash.
            $file = Security::safePath($root, $relative . 'index.html');
            if ($file === null || !is_file($file)) self::notFound();
        } else {
            $file = Security::safePath($root, $relative);
            if ($file === null || !is_file($file)) {
                // A directory without a trailing slash gets one, like a real server.
                $dir = Security::safePath($root, $relative);
                if ($dir !== null && is_dir($dir)) {
                    $target = implode('/', array_map('rawurlencode', explode('/', $relative))) . '/';
                    header('Location: ' . $target, true, 301);
                    exit;
                }
                self::notFound();
            }
        }

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!isset(self::STATIC_TYPES[$ext])) self::notFound();

        // This is the visitor's site, not the panel: drop the panel's hardening
        // headers so the site can use its own inline scripts and be embedded.
        foreach (['Content-Security-Policy', 'Cross-Origin-Opener-Policy', 'Cross-Origin-Resource-Policy', 'X-Frame-Options', 'Cache-Control'] as $name) {
            header_remove($name);
        }
        header('Content-Type: ' . self::STATIC_TYPES[$ext]);
        header('Content-Length: ' . (string) filesize($file));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=60');
        readfile($file);
    }

    /**
     * CGI: the request goes to the script on stdin and in the environment, the
     * script answers with "Status/Content-Type headers, blank line, body".
     */
    public static function runCgi(string $slug, string $path): void
    {
        if (!Config::get('security.cgi_enabled', false)) self::notFound();
        if (!Config::get('security.execution_enabled', true)) {
            self::fail(503, 'Выполнение кода отключено в настройках.');
        }
        if (Process::mode() !== 'proc_open') {
            self::fail(
                503,
                'CGI-режим требует proc_open: на этом хостинге запуск процессов запрещён. ' .
                'Статические сайты через /site/{project} работают и без него.'
            );
        }
        $project = self::project($slug);
        if ($project === null) self::notFound();

        $python = Env::python();
        if ($python === null) self::fail(503, 'Python не найден. Установите его на странице «Среда выполнения».');

        $script = self::script($project);
        if ($script === null) self::fail(503, 'В проекте нет точки входа для CGI.');

        $limit = max(1, (int) Config::get('security.cgi_rpm', 60));
        if (!Security::rateLimit('cgi:' . Security::clientIp(), $limit, 60)) {
            self::fail(429, 'Слишком много запросов. Повторите через минуту.');
        }

        $maxBody = (int) Config::get('security.max_upload', 26214400);
        $body = (string) file_get_contents('php://input', false, null, 0, $maxBody);
        $timeout = max(5, (int) Config::get('security.run_timeout', 120));
        $maxOut = (int) Config::get('security.max_output', 8388608);

        $res = Process::execute(
            array_merge($python['cmd'], [$script]),
            (string) $project['dir'],
            self::cgiEnv($path, $body),
            $timeout,
            $maxOut,
            $body
        );

        $parsed = self::parseCgi($res['out']);
        if ($parsed === null) {
            // No "headers, blank line" block: the script died before it could
            // answer. Log the raw output and show nothing of it to the visitor.
            self::logCgi($project, $path, $res);
            self::fail(
                500,
                'Скрипт не вернул CGI-заголовки (код ' . $res['code'] . '). ' .
                'Скорее всего он упал при старте — подробности в storage/logs/cgi.log.'
            );
        }
        [$status, $headers, $payload] = $parsed;

        http_response_code($status);
        // The answer belongs to the project's app, not to the panel: its own
        // CSP/frame policy must not be overwritten by the panel's defaults.
        foreach (['Content-Security-Policy', 'Cross-Origin-Opener-Policy', 'Cross-Origin-Resource-Policy', 'X-Frame-Options', 'Cache-Control'] as $name) {
            header_remove($name);
        }
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value, true);
        }
        echo $payload;
    }

    /* ---------- helpers ---------- */

    private static function project(string $slug): ?array
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $slug) !== 1) return null;
        return Database::one('SELECT * FROM projects WHERE slug = ?', [$slug]);
    }

    /** A project may expose ./public (preferred) or its own root. */
    private static function siteRoot(array $project): ?string
    {
        $base = (string) $project['dir'];
        foreach (['public', 'www', 'site'] as $candidate) {
            $dir = $base . '/' . $candidate;
            if (is_dir($dir)) return $dir;
        }
        return is_dir($base) ? $base : null;
    }

    /** Rejects traversal and dotfiles before anything touches the filesystem. */
    private static function normalise(string $path): ?string
    {
        $path = rawurldecode($path);
        if ($path === '') return '';
        if (str_contains($path, "\0") || str_contains($path, '..')) return null;
        if (preg_match('/[\x00-\x1f\x7f]/', $path) === 1) return null;
        $path = ltrim(str_replace('\\', '/', $path), '/');
        foreach (explode('/', $path) as $segment) {
            if ($segment === '') continue;
            if ($segment[0] === '.') return null;
        }
        return $path;
    }

    private static function script(array $project): ?string
    {
        $base = (string) $project['dir'];
        foreach (['app.py', 'wsgi.py', 'cgi.py'] as $name) {
            if (is_file($base . '/' . $name)) return $base . '/' . $name;
        }
        $entry = (string) ($project['entrypoint'] ?? '');
        if ($entry !== '' && is_file($base . '/' . $entry)) return $base . '/' . $entry;
        return null;
    }

    /** @return array<string,string> */
    private static function cgiEnv(string $path, string $body): array
    {
        $env = [
            'GATEWAY_INTERFACE' => 'CGI/1.1',
            'REQUEST_METHOD'    => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            'PATH_INFO'         => $path === '' ? '/' : $path,
            'QUERY_STRING'      => (string) ($_SERVER['QUERY_STRING'] ?? ''),
            'CONTENT_TYPE'      => (string) ($_SERVER['CONTENT_TYPE'] ?? ''),
            'CONTENT_LENGTH'    => (string) strlen($body),
            'SERVER_PROTOCOL'   => $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1',
            'SERVER_NAME'       => (string) ($_SERVER['SERVER_NAME'] ?? 'localhost'),
            'REMOTE_ADDR'       => Security::clientIp(),
            'PYTHONUNBUFFERED'  => '1',
            'PYTHONIOENCODING'  => 'utf-8',
        ];
        foreach ($_SERVER as $key => $value) {
            if (!is_string($value)) continue;
            if (str_starts_with($key, 'HTTP_')) {
                $env[$key] = $value;
            } elseif (in_array($key, ['REMOTE_PORT', 'SERVER_PORT'], true)) {
                $env[$key] = $value;
            }
        }
        return $env;
    }

    /**
     * Splits "headers, blank line, body". Returns null when the output has no
     * header block at all, which means the script never produced a response.
     *
     * @return array{0:int,1:array<string,string>,2:string}|null
     */
    private static function parseCgi(string $raw): ?array
    {
        // Windows text mode turns "\r\n" into "\r\r\n", so the header/body
        // separator can arrive doubled. Match the loose form and keep the body
        // bytes untouched (an image response must not be normalised).
        if (preg_match("/\r?\r?\n\r?\r?\n/", $raw, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        $offset  = (int) $m[0][1];
        $head    = substr($raw, 0, $offset);
        $payload = substr($raw, $offset + strlen((string) $m[0][0]));

        $status = 200;
        $headers = [];
        foreach (preg_split("/\r?\r?\n/", $head) ?: [] as $line) {
            if (!str_contains($line, ':')) continue;
            [$name, $value] = explode(':', $line, 2);
            $name = trim($name);
            $value = trim($value);
            if (strcasecmp($name, 'Status') === 0) {
                $status = (int) preg_replace('/\D.*$/', '', $value) ?: 200;
                continue;
            }
            if ($name === '' || preg_match('/^[A-Za-z0-9-]+$/', $name) !== 1) continue;
            if (in_array(strtolower($name), ['content-length', 'connection', 'transfer-encoding'], true)) continue;
            $headers[$name] = $value;
        }
        if (!isset($headers['Content-Type'])) {
            $headers['Content-Type'] = 'text/html; charset=utf-8';
        }
        return [$status, $headers, $payload];
    }

    private static function logCgi(array $project, string $path, array $res): void
    {
        $dir = (string) Config::get('logs');
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        @file_put_contents(
            $dir . '/cgi.log',
            json_encode([
                'at'      => date('c'),
                'project' => $project['slug'] ?? '',
                'path'    => $path,
                'method'  => $_SERVER['REQUEST_METHOD'] ?? '',
                'code'    => $res['code'],
                'ip'      => Security::clientIp(),
                'output'  => mb_substr((string) $res['out'], 0, 4000),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    private static function fail(int $code, string $message): void
    {
        http_response_code($code);
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
        exit;
    }

    private static function notFound(): void
    {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo '404 Not Found';
        exit;
    }
}
