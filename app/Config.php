<?php
declare(strict_types=1);

/**
 * Central configuration.
 * Values can be overridden by config.local.php (array_replace_recursive)
 * and by rows in the `settings` database table (applied at bootstrap).
 */
final class Config
{
    private static array $data = [];
    private static array $overrides = [];

    public static function load(): void
    {
        $root = dirname(__DIR__);
        self::$data = [
            'app_name'  => 'PyHost Engine',
            'root'      => $root,
            'public'    => $root . '/public',
            'storage'   => $root . '/storage',
            'db_file'   => $root . '/storage/db/engine.sqlite',
            'sessions'  => $root . '/storage/sessions',
            'projects'  => $root . '/storage/projects',
            'venvs'     => $root . '/storage/venvs',
            'logs'      => $root . '/storage/logs',
            // Portable CPython lives under storage/ on purpose: a top-level
            // "runtime" directory in the web root shadows the /runtime route.
            'runtime'   => $root . '/storage/runtime',
            'timezone'  => 'UTC',
            'security'  => [
                'session_name'       => 'PYHOSTSESSID',
                'login_path'         => 'engine-login',   // /engine-login by default, editable in settings
                'login_captcha'      => true,
                'require_useragent'  => true,
                'blocked_useragents' => 'sqlmap,nmap,masscan,nikto,zgrab,dirbuster,gobuster,wfuzz,nuclei,acunetix',
                'csrf_enabled'       => true,
                'csrf_rotation'      => 'session',       // session|request
                'csrf_ttl'           => 3600,
                'hide_server_header' => true,
                'cookie_secure'      => false,      // true when served over HTTPS
                'cookie_samesite'    => 'Lax',
                'cookie_prefix'      => true,
                'session_bind_ua'    => true,
                'session_bind_ip'    => false,
                'session_rotate'     => 1800,
                'remember_days'      => 14,
                'session_idle'       => 7200,
                'login_max_attempts' => 5,
                'login_lockout'      => 900,
                'global_rpm'         => 120,
                'run_rpm'            => 20,
                'ip_whitelist'       => '',
                'execution_enabled'  => true,
                'max_upload'         => 26214400,   // 25 MB
                'max_files'          => 300,
                'max_output'         => 8388608,    // 8 MB
                'run_timeout'        => 120,
                'python_binary'      => '',         // empty = auto-detect
                // Pyodide runs CPython in the browser (WASM): no server process needed.
                'browser_python'     => true,
                'pyodide_version'    => '0.29.5',
                'pyodide_cdn'        => 'https://cdn.jsdelivr.net/pyodide/',
                // Extra hosts the browser runtime may call, comma separated, e.g.
                // "https://api.example.com". The target must allow CORS.
                'pyodide_connect_hosts' => '',
                // Public surface: /site/{project} serves static files (no process),
                // /cgi/{project} runs the entry point per request (needs proc_open).
                'site_enabled'       => true,
                'cgi_enabled'        => false,
                'cgi_rpm'            => 60,
            ],
            'telegram' => [
                'enabled'    => false,
                'bot_token'  => '',
                'chat_id'    => '',
                'doh'        => 'cloudflare',   // cloudflare|google|direct
                'events'     => 'login,failed_login,twofa,run,project,venv,runtime,security',
            ],
        ];

        $local = $root . '/config.local.php';
        if (is_file($local)) {
            $extra = require $local;
            if (is_array($extra)) {
                self::$data = array_replace_recursive(self::$data, $extra);
            }
        }
    }

    /** Dot-notation lookup: Config::get('security.run_timeout'). */
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$overrides[$key] ?? self::dig(self::$data, $key);
        return $value === null ? $default : $value;
    }

    public static function all(): array
    {
        return array_replace_recursive(self::$data, self::$overrides);
    }

    /** Values loaded from the settings table take precedence over files. */
    public static function applySettings(array $settings): void
    {
        foreach ($settings as $key => $value) {
            self::$overrides[$key] = $value;
        }
    }

    public static function setOverride(string $key, mixed $value): void
    {
        self::$overrides[$key] = $value;
    }

    private static function dig(array $array, string $key): mixed
    {
        $node = $array;
        foreach (explode('.', $key) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return null;
            }
            $node = $node[$part];
        }
        return $node;
    }
}
