<?php
declare(strict_types=1);

/** Thin, prepared-statement-only PDO wrapper with automatic schema bootstrap. */
final class Database
{
    private static ?PDO $pdo = null;

    public static function init(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $file = (string) Config::get('db_file');
        $dir  = dirname($file);
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

        self::$pdo = $pdo;
        self::migrate($pdo);
        return $pdo;
    }

    public static function pdo(): PDO
    {
        return self::$pdo ?? self::init();
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
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', array_map(fn($c) => '"' . $c . '"', $columns)),
            implode(', ', array_fill(0, count($columns), '?'))
        );
        self::run($sql, array_values($data));
        return (int) self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $params = []): int
    {
        $sets = implode(', ', array_map(fn($c) => '"' . $c . '" = ?', array_keys($data)));
        $stmt = self::run("UPDATE {$table} SET {$sets} WHERE {$where}", array_merge(array_values($data), $params));
        return $stmt->rowCount();
    }

    /** Key/value settings, JSON-encoded so booleans/arrays survive a round-trip. */
    public static function settings(): array
    {
        $out = [];
        foreach (self::all('SELECT key, value FROM settings') as $row) {
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
        self::run(
            'INSERT INTO settings(key, value) VALUES (?, ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value',
            [$key, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
        );
    }

    private static function migrate(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS users (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    username        TEXT NOT NULL UNIQUE COLLATE NOCASE,
    password_hash   TEXT NOT NULL,
    salt            TEXT NOT NULL,
    remember_hash   TEXT,
    created_at      INTEGER NOT NULL,
    last_login_at   INTEGER,
    failed_attempts INTEGER NOT NULL DEFAULT 0,
    locked_until    INTEGER NOT NULL DEFAULT 0
);
SQL);
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS projects (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    slug       TEXT NOT NULL UNIQUE,
    dir        TEXT NOT NULL,
    entrypoint TEXT NOT NULL DEFAULT 'main.py',
    args       TEXT NOT NULL DEFAULT '',
    interpreter TEXT NOT NULL DEFAULT 'venv',
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
);
SQL);
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS runs (
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
);
SQL);
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS venvs (
    project_id INTEGER PRIMARY KEY,
    path       TEXT NOT NULL,
    python_bin TEXT NOT NULL,
    status     TEXT NOT NULL DEFAULT 'missing',
    created_at INTEGER NOT NULL,
    FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
);
SQL);
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS settings (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL
);
SQL);
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS rate_limits (
    key      TEXT PRIMARY KEY,
    hits     INTEGER NOT NULL DEFAULT 0,
    reset_at INTEGER NOT NULL
);
SQL);
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS audit (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER,
    action     TEXT NOT NULL,
    detail     TEXT NOT NULL DEFAULT '',
    ip         TEXT NOT NULL DEFAULT '',
    created_at INTEGER NOT NULL
);
SQL);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_runs_project ON runs(project_id, id DESC)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_audit_created ON audit(created_at DESC)');

        // Forward migrations for installations created by earlier versions.
        $columns = array_map(
            static fn($row) => $row['name'],
            $pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC)
        );
        if (!in_array('totp_secret', $columns, true)) $pdo->exec('ALTER TABLE users ADD COLUMN totp_secret TEXT');
        if (!in_array('totp_enabled', $columns, true)) $pdo->exec('ALTER TABLE users ADD COLUMN totp_enabled INTEGER NOT NULL DEFAULT 0');
        if (!in_array('remember_ua', $columns, true)) $pdo->exec('ALTER TABLE users ADD COLUMN remember_ua TEXT');
    }
}
