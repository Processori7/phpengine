<?php
declare(strict_types=1);

/**
 * Thin, prepared-statement-only PDO wrapper with automatic schema bootstrap.
 * Supports SQLite (default) and MySQL. Connection details live in a small
 * JSON file so they can be read before the database itself is available.
 */
final class Database
{
    private static ?PDO $pdo = null;
    private static string $driver = 'sqlite';

    public static function init(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $cfg = self::loadConnection();
        self::$driver = $cfg['driver'];

        if ($cfg['driver'] === 'sqlite') {
            $file = self::normalizeSqlitePath((string) $cfg['path']);
            $dir = dirname($file);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException("Cannot create database directory: {$dir}");
            }
            $pdo = new PDO('sqlite:' . $file, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = 5000');
        } else {
            if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
                throw new RuntimeException('PDO MySQL is not available. Enable pdo_mysql in PHP.');
            }
            if ($cfg['name'] === '') {
                throw new RuntimeException('MySQL database name is not configured.');
            }
            $dsn = 'mysql:host=' . $cfg['host'] . ';port=' . (int) $cfg['port']
                 . ';dbname=' . $cfg['name'] . ';charset=' . $cfg['charset'];
            $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 5,
            ]);
        }

        self::$pdo = $pdo;
        self::migrate($pdo);
        return $pdo;
    }

    public static function pdo(): PDO
    {
        return self::$pdo ?? self::init();
    }

    public static function driver(): string
    {
        return self::$driver;
    }

    public static function connectionPath(): string
    {
        return (string) Config::get('storage') . '/db/connection.json';
    }

    /** Full connection config with the password decrypted. Use only server-side. */
    public static function connection(): array
    {
        return self::loadConnection();
    }

    /** Safe version for the admin form: never exposes the password. */
    public static function connectionForForm(): array
    {
        $cfg = self::loadConnection();
        $passwordSet = $cfg['pass'] !== '';
        unset($cfg['pass']);
        $cfg['password_set'] = $passwordSet;
        return $cfg;
    }

    public static function saveConnection(array $cfg): void
    {
        $cfg = self::normalizeConfig($cfg);
        $stored = $cfg;
        $stored['pass'] = SecureStore::encrypt($cfg['pass']);
        $json = json_encode($stored, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Не удалось сериализовать настройки подключения.');
        }
        $path = self::connectionPath();
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Не удалось создать каталог для настроек БД.');
        }
        if (@file_put_contents($path, $json, LOCK_EX) === false) {
            throw new RuntimeException('Не удалось записать настройки БД. Проверьте права на storage/db/.');
        }
        if (DIRECTORY_SEPARATOR !== '\\') @chmod($path, 0600);
    }

    /** @return array{ok: bool, message: string} */
    public static function testConnection(array $cfg): array
    {
        try {
            $rawPath = (string) ($cfg['path'] ?? '');
            if (self::invalidWindowsPath($rawPath)) {
                return ['ok' => false, 'message' => 'Путь Windows-стиля (C:\\...) нельзя использовать на этом хостинге. Укажите путь, начинающийся с /, или оставьте значение по умолчанию.'];
            }
            $cfg = self::normalizeConfig($cfg);
            if ($cfg['driver'] === 'sqlite') {
                $file = self::normalizeSqlitePath((string) $cfg['path']);
                $dir = dirname($file);
                if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                    return ['ok' => false, 'message' => 'Не удалось создать каталог для файла SQLite.'];
                }
                if (is_file($file) && !is_writable($file)) {
                    return ['ok' => false, 'message' => 'Файл SQLite существует, но не доступен для записи.'];
                }
                $pdo = new PDO('sqlite:' . $file, null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);
                $pdo->query('SELECT 1');
                return ['ok' => true, 'message' => 'SQLite: подключение успешно (' . basename($file) . ').'];
            }

            if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
                return ['ok' => false, 'message' => 'Расширение PDO MySQL не установлено.'];
            }
            $dsn = 'mysql:host=' . $cfg['host'] . ';port=' . (int) $cfg['port']
                 . ';dbname=' . $cfg['name'] . ';charset=' . $cfg['charset'];
            $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);
            $pdo->query('SELECT 1');
            return ['ok' => true, 'message' => 'MySQL: подключение к ' . $cfg['host'] . '/' . $cfg['name'] . ' успешно.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => self::safeError($e)];
        }
    }

    public static function q(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
            throw new InvalidArgumentException('Invalid SQL identifier.');
        }
        return self::$driver === 'mysql' ? '`' . $identifier . '`' : '"' . $identifier . '"';
    }

    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute(array_values($params));
        return $stmt;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function scalar(string $sql, array $params = []): mixed
    {
        return self::run($sql, $params)->fetchColumn();
    }

    public static function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $quoted = array_map(static fn($c) => self::q((string) $c), $columns);
        $sql = 'INSERT INTO ' . self::q($table) . ' (' . implode(', ', $quoted) . ') VALUES ('
             . implode(', ', array_fill(0, count($columns), '?')) . ')';
        self::run($sql, array_values($data));
        return (int) self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $params = []): int
    {
        $sets = implode(', ', array_map(
            static fn($c) => self::q((string) $c) . ' = ?',
            array_keys($data)
        ));
        $stmt = self::run(
            'UPDATE ' . self::q($table) . ' SET ' . $sets . ' WHERE ' . $where,
            array_merge(array_values($data), $params)
        );
        return $stmt->rowCount();
    }

    /** Portable INSERT ... ON DUPLICATE KEY / ON CONFLICT UPDATE. */
    public static function upsert(string $table, array $data, string $conflict): void
    {
        $columns = array_keys($data);
        if ($columns === []) return;
        $quoted = array_map(static fn($c) => self::q((string) $c), $columns);
        $params = array_values($data);
        $updates = [];
        foreach ($columns as $column) {
            if ((string) $column === $conflict) continue;
            $updates[] = self::q((string) $column) . ' = ?';
            $params[] = $data[$column];
        }
        if ($updates === []) {
            $updates[] = self::q($conflict) . ' = ' . self::q($conflict);
            $params[] = $data[$conflict];
        }
        $tail = self::$driver === 'mysql'
            ? 'ON DUPLICATE KEY UPDATE ' . implode(', ', $updates)
            : 'ON CONFLICT(' . self::q($conflict) . ') DO UPDATE SET ' . implode(', ', $updates);
        self::run(
            'INSERT INTO ' . self::q($table) . ' (' . implode(', ', $quoted) . ') VALUES ('
            . implode(', ', array_fill(0, count($columns), '?')) . ') ' . $tail,
            $params
        );
    }

    /** Key/value settings, JSON-encoded so booleans/arrays survive a round-trip. */
    public static function settings(): array
    {
        $out = [];
        foreach (self::all('SELECT ' . self::q('key') . ', value FROM settings') as $row) {
            $decoded = json_decode($row['value'], true);
            $value = $decoded === null ? $row['value'] : $decoded;
            if (is_string($value) && preg_match('/token|secret|password/i', $row['key'])) {
                $value = SecureStore::decrypt($value);
            }
            $out[$row['key']] = $value;
        }
        return $out;
    }

    public static function setSetting(string $key, mixed $value): void
    {
        if (is_string($value) && $value !== '' && preg_match('/token|secret|password/i', $key)) {
            $value = SecureStore::encrypt($value);
        }
        self::upsert('settings', [
            'key'   => $key,
            'value' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ], 'key');
    }

    /* ---------- internals ---------- */

    private static function loadConnection(): array
    {
        $defaults = [
            'driver'  => 'sqlite',
            'path'    => (string) Config::get('db_file'),
            'host'    => '127.0.0.1',
            'port'    => 3306,
            'name'    => '',
            'user'    => '',
            'pass'    => '',
            'charset' => 'utf8mb4',
        ];
        $path = self::connectionPath();
        if (!is_file($path)) return $defaults;
        $raw = json_decode((string) file_get_contents($path), true);
        if (!is_array($raw)) return $defaults;
        $cfg = array_merge($defaults, $raw);
        if (self::invalidWindowsPath((string) $cfg['path'])) {
            // A connection file copied from a Windows machine must not create
            // a bogus "C:" directory on a Unix host and an empty database.
            $cfg['path'] = (string) Config::get('db_file');
        }
        if (str_starts_with((string) $cfg['pass'], 'enc1:')) {
            $cfg['pass'] = SecureStore::decrypt((string) $cfg['pass']);
        }
        return $cfg;
    }

    private static function normalizeConfig(array $cfg): array
    {
        $driver = (string) ($cfg['driver'] ?? 'sqlite');
        $driver = in_array($driver, ['sqlite', 'mysql'], true) ? $driver : 'sqlite';
        $charset = (string) ($cfg['charset'] ?? 'utf8mb4');
        $charset = in_array($charset, ['utf8mb4', 'utf8', 'latin1'], true) ? $charset : 'utf8mb4';
        $host = trim((string) ($cfg['host'] ?? '127.0.0.1'));
        $name = trim((string) ($cfg['name'] ?? ''));
        if ($driver === 'mysql') {
            if (!preg_match('/^[A-Za-z0-9_.:-]{1,255}$/', $host)) {
                throw new InvalidArgumentException('Некорректный адрес MySQL-сервера.');
            }
            if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
                throw new InvalidArgumentException('Некорректное имя базы данных MySQL.');
            }
        }
        $path = trim(str_replace("\0", '', (string) ($cfg['path'] ?? Config::get('db_file'))));
        if ($path === '' || self::invalidWindowsPath($path)) {
            $path = (string) Config::get('db_file');
        }
        return [
            'driver'  => $driver,
            'path'    => $path,
            'host'    => $host,
            'port'    => max(1, min(65535, (int) ($cfg['port'] ?? 3306))),
            'name'    => $name,
            'user'    => trim((string) ($cfg['user'] ?? '')),
            'pass'    => (string) ($cfg['pass'] ?? ''),
            'charset' => $charset,
        ];
    }

    private static function normalizeSqlitePath(string $path): string
    {
        $path = trim(str_replace("\0", '', $path));
        if ($path === '' || self::invalidWindowsPath($path)) {
            $path = (string) Config::get('db_file');
        }
        if (DIRECTORY_SEPARATOR === '\\') {
            $absolute = preg_match('#^([A-Za-z]:[\\\\/]|/|\\\\\\\\)#', $path) === 1;
        } else {
            $absolute = str_starts_with($path, '/');
        }
        if (!$absolute) {
            $path = rtrim((string) Config::get('root'), '/\\') . '/' . $path;
        }
        return $path;
    }

    /** A Windows drive path in a connection file would silently create an empty DB on Unix. */
    private static function invalidWindowsPath(string $path): bool
    {
        return DIRECTORY_SEPARATOR !== '\\' && preg_match('#^[A-Za-z]:[\\\\/]#', trim($path)) === 1;
    }

    private static function safeError(Throwable $e): string
    {
        $message = preg_replace('/\b(pass(word)?|pwd)=[^;\s]*/i', '$1=***', $e->getMessage());
        return mb_substr((string) $message, 0, 240);
    }

    private static function migrate(PDO $pdo): void
    {
        if (self::$driver === 'mysql') {
            $sqls = self::mysqlSchema();
        } else {
            $sqls = self::sqliteSchema();
        }
        foreach ($sqls as $sql) $pdo->exec($sql);

        self::ensureIndex($pdo, 'runs', 'idx_runs_project', 'project_id, id DESC');
        self::ensureIndex($pdo, 'audit', 'idx_audit_created', 'created_at DESC');

        // Forward migrations for installations created by earlier versions.
        $columns = self::columns($pdo, 'users');
        $int = self::$driver === 'mysql' ? 'INT NOT NULL DEFAULT 0' : 'INTEGER NOT NULL DEFAULT 0';
        $text = self::$driver === 'mysql' ? 'VARCHAR(191) DEFAULT NULL' : 'TEXT';
        if (!in_array('totp_secret', $columns, true)) $pdo->exec('ALTER TABLE ' . self::q('users') . ' ADD COLUMN totp_secret ' . $text);
        if (!in_array('totp_enabled', $columns, true)) $pdo->exec('ALTER TABLE ' . self::q('users') . ' ADD COLUMN totp_enabled ' . $int);
        if (!in_array('remember_ua', $columns, true)) $pdo->exec('ALTER TABLE ' . self::q('users') . ' ADD COLUMN remember_ua ' . $text);
    }

    private static function sqliteSchema(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS "users" (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                username        TEXT NOT NULL UNIQUE COLLATE NOCASE,
                password_hash   TEXT NOT NULL,
                salt            TEXT NOT NULL,
                remember_hash   TEXT,
                created_at      INTEGER NOT NULL,
                last_login_at   INTEGER,
                failed_attempts INTEGER NOT NULL DEFAULT 0,
                locked_until    INTEGER NOT NULL DEFAULT 0
            )',
            'CREATE TABLE IF NOT EXISTS "projects" (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                name        TEXT NOT NULL,
                slug        TEXT NOT NULL UNIQUE,
                dir         TEXT NOT NULL,
                entrypoint  TEXT NOT NULL DEFAULT \'main.py\',
                args        TEXT NOT NULL DEFAULT \'\',
                interpreter TEXT NOT NULL DEFAULT \'venv\',
                created_at  INTEGER NOT NULL,
                updated_at  INTEGER NOT NULL
            )',
            'CREATE TABLE IF NOT EXISTS "runs" (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id  INTEGER,
                user_id     INTEGER,
                command     TEXT NOT NULL,
                cwd         TEXT NOT NULL,
                status      TEXT NOT NULL,
                exit_code   INTEGER,
                output_file TEXT NOT NULL,
                bytes       INTEGER NOT NULL DEFAULT 0,
                started_at  INTEGER NOT NULL,
                finished_at INTEGER,
                FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE SET NULL,
                FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
            )',
            'CREATE TABLE IF NOT EXISTS "venvs" (
                project_id INTEGER PRIMARY KEY,
                path       TEXT NOT NULL,
                python_bin TEXT NOT NULL,
                status     TEXT NOT NULL DEFAULT \'missing\',
                created_at INTEGER NOT NULL,
                FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
            )',
            'CREATE TABLE IF NOT EXISTS "settings" (
                "key"   TEXT PRIMARY KEY,
                value   TEXT NOT NULL
            )',
            'CREATE TABLE IF NOT EXISTS "rate_limits" (
                "key"     TEXT PRIMARY KEY,
                hits      INTEGER NOT NULL DEFAULT 0,
                reset_at  INTEGER NOT NULL
            )',
            'CREATE TABLE IF NOT EXISTS "audit" (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id    INTEGER,
                action     TEXT NOT NULL,
                detail     TEXT NOT NULL DEFAULT \'\',
                ip         TEXT NOT NULL DEFAULT \'\',
                created_at INTEGER NOT NULL
            )',
        ];
    }

    private static function mysqlSchema(): array
    {
        $engine = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        return [
            "CREATE TABLE IF NOT EXISTS `users` (
                id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                username        VARCHAR(191) NOT NULL UNIQUE,
                password_hash   VARCHAR(255) NOT NULL,
                salt            VARCHAR(191) NOT NULL,
                remember_hash   VARCHAR(191) DEFAULT NULL,
                created_at      INT NOT NULL,
                last_login_at   INT DEFAULT NULL,
                failed_attempts INT NOT NULL DEFAULT 0,
                locked_until    INT NOT NULL DEFAULT 0
            ) {$engine}",
            "CREATE TABLE IF NOT EXISTS `projects` (
                id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                name        VARCHAR(255) NOT NULL,
                slug        VARCHAR(191) NOT NULL UNIQUE,
                dir         TEXT NOT NULL,
                entrypoint  VARCHAR(191) NOT NULL DEFAULT 'main.py',
                args        TEXT NOT NULL,
                interpreter VARCHAR(32) NOT NULL DEFAULT 'venv',
                created_at  INT NOT NULL,
                updated_at  INT NOT NULL
            ) {$engine}",
            "CREATE TABLE IF NOT EXISTS `runs` (
                id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                project_id  INT UNSIGNED DEFAULT NULL,
                user_id     INT UNSIGNED DEFAULT NULL,
                command     TEXT NOT NULL,
                cwd         TEXT NOT NULL,
                status      VARCHAR(32) NOT NULL,
                exit_code   INT DEFAULT NULL,
                output_file TEXT NOT NULL,
                bytes       INT NOT NULL DEFAULT 0,
                started_at  INT NOT NULL,
                finished_at INT DEFAULT NULL,
                FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE SET NULL,
                FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
            ) {$engine}",
            "CREATE TABLE IF NOT EXISTS `venvs` (
                project_id INT UNSIGNED NOT NULL PRIMARY KEY,
                path       TEXT NOT NULL,
                python_bin TEXT NOT NULL,
                status     VARCHAR(32) NOT NULL DEFAULT 'missing',
                created_at INT NOT NULL,
                FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
            ) {$engine}",
            "CREATE TABLE IF NOT EXISTS `settings` (
                `key`   VARCHAR(191) NOT NULL PRIMARY KEY,
                value   LONGTEXT NOT NULL
            ) {$engine}",
            "CREATE TABLE IF NOT EXISTS `rate_limits` (
                `key`     VARCHAR(191) NOT NULL PRIMARY KEY,
                hits      INT NOT NULL DEFAULT 0,
                reset_at  INT NOT NULL
            ) {$engine}",
            "CREATE TABLE IF NOT EXISTS `audit` (
                id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id    INT UNSIGNED DEFAULT NULL,
                action     VARCHAR(191) NOT NULL,
                detail     TEXT NOT NULL,
                ip         VARCHAR(64) NOT NULL DEFAULT '',
                created_at INT NOT NULL
            ) {$engine}",
        ];
    }

    private static function ensureIndex(PDO $pdo, string $table, string $index, string $columns): void
    {
        if (self::$driver === 'sqlite') {
            $pdo->exec('CREATE INDEX IF NOT EXISTS "' . $index . '" ON "' . $table . '" (' . $columns . ')');
            return;
        }
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
        );
        $stmt->execute([$table, $index]);
        if ((int) $stmt->fetchColumn() === 0) {
            $pdo->exec('CREATE INDEX `' . $index . '` ON `' . $table . '` (' . $columns . ')');
        }
    }

    private static function columns(PDO $pdo, string $table): array
    {
        if (self::$driver === 'sqlite') {
            $rows = $pdo->query('PRAGMA table_info("' . $table . '")')->fetchAll(PDO::FETCH_ASSOC);
            return array_map(static fn($r) => (string) $r['name'], $rows);
        }
        $rows = $pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn($r) => (string) $r['Field'], $rows);
    }
}
