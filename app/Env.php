<?php
declare(strict_types=1);

/** Runtime capability detection and diagnostics for the admin panel. */
final class Env
{
    /** @return array{cmd: array, bin: string, exe: string, version: string, source: string}|null */
    public static function python(): ?array
    {
        $candidates = [];

        $bundled = RuntimeManager::command();
        if ($bundled !== null) {
            $candidates[] = $bundled;
        }

        $configured = trim((string) Config::get('security.python_binary', ''));
        if ($configured !== '' && !preg_match('/[;&|`$<>]/', $configured)) {
            $candidates[] = ['cmd' => [$configured], 'bin' => $configured, 'source' => 'configured'];
        }

        foreach ([['python3'], ['python'], ['py', '-3']] as $base) {
            $candidates[] = ['cmd' => $base, 'bin' => implode(' ', $base), 'source' => 'system'];
        }
        if (DIRECTORY_SEPARATOR === '\\') {
            foreach (glob('C:\\Python3*\\python.exe') ?: [] as $p) {
                $candidates[] = ['cmd' => [$p], 'bin' => $p, 'source' => 'system'];
            }
        }

        foreach ($candidates as $candidate) {
            $res = Process::execute(
                array_merge($candidate['cmd'], ['-c', 'import sys; print(sys.version.split()[0]); print(chr(124)); print(sys.executable)']),
                null, [], 20, 4096
            );
            if ($res['code'] === 0 && str_contains($res['out'], '|')) {
                [$version, $exe] = array_pad(explode('|', trim($res['out']), 2), 2, $candidate['bin']);
                return [
                    'cmd'     => $candidate['cmd'],
                    'bin'     => $candidate['bin'],
                    'exe'     => trim($exe),
                    'version' => trim($version),
                    'source'  => $candidate['source'] ?? 'system',
                ];
            }
        }
        return null;
    }

    public static function diagnostics(): array
    {
        $checks = [];
        $checks[] = self::check('PHP', PHP_VERSION, true, 'PHP 8.0+ рекомендуется.');

        $display = (string) ini_get('display_errors');
        $checks[] = self::check(
            'Вывод ошибок в браузер',
            ($display === '' || $display === '0' || strtolower($display) === 'off') ? 'выключен' : 'включён',
            $display === '' || $display === '0' || strtolower($display) === 'off',
            'Ошибки и стек-трейсы должны попадать только в storage/logs, а не в ответ.'
        );

        $mode = Process::mode();
        $checks[] = self::check(
            'Запуск процессов',
            $mode ?? 'все функции отключены',
            $mode !== null,
            'Нужна хотя бы одна функция: proc_open, exec, shell_exec, popen, system или passthru.'
        );

        $pdo = class_exists('PDO') && in_array('sqlite', PDO::getAvailableDrivers(), true);
        $checks[] = self::check('PDO SQLite', $pdo ? 'доступен' : 'отсутствует', $pdo, 'База данных: storage/db/engine.sqlite.');

        $zip = class_exists('ZipArchive');
        $unzip = self::commandExists('unzip');
        $checks[] = self::check(
            'Распаковка ZIP',
            $zip ? 'ZipArchive' : ($unzip ? 'unzip (CLI)' : 'нет'),
            $zip || $unzip,
            'Нужна для загрузки архивов. Включите extension=zip или установите unzip.'
        );

        $runtime = RuntimeManager::status();
        $checks[] = self::check(
            'Встроенный Python',
            $runtime['installed'] ? $runtime['version'] . ' · ' . $runtime['exec_mode'] : ($runtime['supported'] ? 'не установлен' : 'платформа не поддерживается'),
            $runtime['installed'],
            'Установите переносной Python на странице «Среда выполнения» — он не требует Python на хостинге.'
        );

        $python = self::python();
        $checks[] = self::check(
            'Python (активный)',
            $python ? $python['version'] . ' · ' . $python['source'] . ' · ' . $python['exe'] : 'не найден',
            $python !== null,
            'Источник: встроенный, путь из настроек или системный.'
        );

        if ($python !== null) {
            $res = Process::execute(
                array_merge($python['cmd'], ['-c', 'import venv, ensurepip; print("ok")']),
                null, [], 30, 4096
            );
            $ok = $res['code'] === 0 && str_contains($res['out'], 'ok');
            $checks[] = self::check('Модуль venv', $ok ? 'доступен' : trim($res['out']), $ok, 'Нужен для создания виртуальных окружений.');
        }

        foreach (['projects', 'venvs', 'logs', 'sessions', 'db'] as $dir) {
            $path = Config::get('storage') . '/' . $dir;
            $writable = is_dir($path) ? is_writable($path) : is_writable(dirname($path));
            $checks[] = self::check('Каталог ' . $dir, $writable ? 'writable' : 'read-only', $writable, $path);
        }

        $sessions = (string) Config::get('sessions');
        $sessionFlags = ini_get('session.use_only_cookies') === '1' && ini_get('session.use_trans_sid') === '0';
        $checks[] = self::check(
            'Session ID только в cookie',
            $sessionFlags ? 'use_only_cookies=1, trans_sid=0' : 'trans_sid включён',
            $sessionFlags,
            'Иначе session ID можно подсунуть в URL.'
        );

        $permOk = is_dir($sessions) && (fileperms($sessions) & 0077) === 0;
        $checks[] = self::check(
            'Права каталога сессий',
            is_dir($sessions) ? substr(sprintf('%o', fileperms($sessions)), -4) : 'нет',
            $permOk,
            'Должен быть доступен только владельцу (0700), иначе соседи по хостингу смогут прочитать файлы сессий.'
        );

        $exposed = self::storageExposed();
        $checks[] = self::check(
            'Приватность storage/',
            $exposed ? 'доступен извне' : 'закрыт',
            !$exposed,
            'Убедитесь, что DocumentRoot указывает на public/, а .htaccess запрещает доступ к storage/.'
        );

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        $checks[] = self::check('HTTPS', $https ? 'да' : 'нет', $https, 'Включите cookie_secure и HSTS после перехода на HTTPS.');

        return $checks;
    }

    private static function storageExposed(): bool
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $url = $scheme . '://' . $host . '/storage/';
        $ctx = stream_context_create(['http' => ['timeout' => 2, 'method' => 'HEAD', 'ignore_errors' => true]]);
        $headers = @get_headers($url, true, $ctx);
        if (!is_array($headers)) return false;
        $status = 0;
        foreach ($headers as $k => $v) {
            if (is_int($k) && preg_match('/(\d{3})/', (string) $v, $m)) { $status = (int) $m[1]; break; }
        }
        return $status >= 200 && $status < 400;
    }

    private static function check(string $name, string $detail, bool $ok, string $hint): array
    {
        return ['name' => $name, 'detail' => $detail, 'status' => $ok ? 'ok' : 'fail', 'hint' => $hint];
    }

    public static function commandExists(string $cmd): bool
    {
        if (function_exists('shell_exec')) {
            $out = @shell_exec(DIRECTORY_SEPARATOR === '\\' ? "where {$cmd} 2>NUL" : "command -v {$cmd} 2>/dev/null");
            return is_string($out) && trim($out) !== '';
        }
        return false;
    }
}
