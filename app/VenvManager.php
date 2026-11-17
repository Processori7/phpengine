<?php
declare(strict_types=1);

/** Virtual environment lifecycle: create, pip install, inspect, remove. */
final class VenvManager
{
    public static function path(array $project): string
    {
        return (string) Config::get('venvs') . '/' . $project['slug'];
    }

    public static function forProject(array $project): ?array
    {
        return Database::one('SELECT * FROM venvs WHERE project_id = ?', [$project['id']]);
    }

    public static function exists(array $project): bool
    {
        return is_file(self::path($project) . '/pyvenv.cfg');
    }

    public static function create(array $project): array
    {
        $python = Env::python();
        if ($python === null) {
            return ['ok' => false, 'message' => 'Python не найден. Укажите путь в настройках.'];
        }
        $path = self::path($project);
        $base = (string) Config::get('venvs');
        if (!is_dir($base)) mkdir($base, 0775, true);
        if (file_exists($path)) self::removeDirectory($path);

        $log = (string) Config::get('logs') . '/venv_' . $project['slug'] . '.log';
        $tokens = array_merge($python['cmd'], ['-m', 'venv', $path]);
        $result = Runner::execute($tokens, (string) Config::get('root'), [], 300, $log);

        Database::upsert('venvs', [
            'project_id' => $project['id'],
            'path'       => $path,
            'python_bin' => $python['bin'],
            'status'     => $result['status'] === 'success' ? 'ready' : 'failed',
            'created_at' => time(),
        ], 'project_id');
        Security::audit('venv_created', $project['name'] . ' -> ' . $result['status']);
        Telegram::notify('venv', '🧪 venv проекта <b>' . Security::e($project['name']) . '</b>: ' . $result['status']);
        return [
            'ok'      => self::exists($project),
            'message' => self::exists($project) ? 'Виртуальное окружение создано.' : 'Не удалось создать venv. Смотрите лог.',
            'log'     => $log,
        ];
    }

    public static function pip(array $project): ?array
    {
        if (!self::exists($project)) return null;
        $bin = Runner::venvBinDir(self::path($project));
        foreach (['pip', 'pip3', 'pip.exe', 'pip3.exe'] as $name) {
            if (is_file($bin . '/' . $name)) return [$bin . '/' . $name];
        }
        return null;
    }

    public static function installRequirements(array $project): array
    {
        if (!self::exists($project)) {
            return ['ok' => false, 'message' => 'Сначала создайте venv.'];
        }
        $file = Security::safePath($project['dir'], 'requirements.txt');
        if ($file === null || !is_file($file)) {
            return ['ok' => false, 'message' => 'В проекте нет requirements.txt.'];
        }
        $pip = self::pip($project) ?? self::pythonFallback($project);
        $log = (string) Config::get('logs') . '/pip_' . $project['slug'] . '.log';
        $result = Runner::execute(
            array_merge($pip, ['install', '-r', 'requirements.txt']),
            $project['dir'],
            Runner::venvEnv(self::path($project)),
            max(300, (int) Config::get('security.run_timeout', 120)),
            $log
        );
        Security::audit('pip_requirements', $project['name'] . ' -> ' . $result['status']);
        return [
            'ok' => $result['status'] === 'success',
            'message' => $result['status'] === 'success' ? 'Зависимости установлены.' : 'Установка завершилась с ошибкой. Смотрите лог.',
            'log' => $log,
        ];
    }

    public static function installPackage(array $project, string $package): array
    {
        if (!self::exists($project)) {
            return ['ok' => false, 'message' => 'Сначала создайте venv.'];
        }
        $package = trim($package);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.\-]{0,80}([=<>~!]=?[A-Za-z0-9_.\-]+)?$/', $package)) {
            return ['ok' => false, 'message' => 'Недопустимое имя пакета. Разрешены только имена PyPI и версии, например requests==2.31.0.'];
        }
        $pip = self::pip($project) ?? self::pythonFallback($project);
        $log = (string) Config::get('logs') . '/pip_' . $project['slug'] . '.log';
        $result = Runner::execute(
            array_merge($pip, ['install', $package]),
            $project['dir'],
            Runner::venvEnv(self::path($project)),
            max(300, (int) Config::get('security.run_timeout', 120)),
            $log
        );
        Security::audit('pip_install', $package . ' -> ' . $result['status']);
        return [
            'ok' => $result['status'] === 'success',
            'message' => $result['status'] === 'success' ? "Пакет {$package} установлен." : 'Ошибка установки. Смотрите лог.',
            'log' => $log,
        ];
    }

    public static function packages(array $project): string
    {
        if (!self::exists($project)) return '';
        $pip = self::pip($project) ?? self::pythonFallback($project);
        $res = Process::execute(
            array_merge($pip, ['list', '--format=freeze']),
            $project['dir'],
            Runner::venvEnv(self::path($project)),
            60,
            1048576
        );
        return $res['out'];
    }

    public static function removeByProjectId(int $id): void
    {
        $row = Database::one('SELECT path FROM venvs WHERE project_id = ?', [$id]);
        if ($row !== null) self::removeDirectory($row['path']);
        Database::run('DELETE FROM venvs WHERE project_id = ?', [$id]);
    }

    public static function remove(array $project): void
    {
        self::removeByProjectId((int) $project['id']);
        Security::audit('venv_removed', $project['name']);
    }

    private static function pythonFallback(array $project): array
    {
        $python = Env::python();
        return $python === null ? ['python', '-m', 'pip'] : array_merge($python['cmd'], ['-m', 'pip']);
    }

    private static function removeDirectory(string $path): void
    {
        $base = realpath((string) Config::get('venvs'));
        $real = realpath($path);
        if ($real === false || $base === false || !Security::isInside($base, $real)) return;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            if ($item->isLink() || $item->isFile()) {
                @unlink($item->getPathname());
            } else {
                @rmdir($item->getPathname());
            }
        }
        @rmdir($real);
    }
}
