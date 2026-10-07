<?php
declare(strict_types=1);

final class Database
{
    private static $pdo = null;

    /** @var array<string,bool> */
    private static $tableCache = [];

    /** @var array<string,bool> */
    private static $columnCache = [];

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        $host = (string)Config::get('connect', 'host', '127.0.0.1');
        $db = (string)Config::get('connect', 'dbase', 'likespa');
        $user = (string)Config::get('connect', 'user_db', 'root');
        $pass = (string)Config::get('connect', 'pass_db', '');
        $socket = (string)Config::get('connect', 'socket', '');

        if ($socket !== '') {
            $dsn = sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $socket, $db);
        } else {
            $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $db);
        }

        self::$pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return self::$pdo;
    }

    /** Drop cached PDO (e.g. after sleep / "No such file or directory" socket errors). */
    public static function reset(): void
    {
        self::$pdo = null;
    }

    /** Ping or reconnect. Returns fresh PDO. */
    public static function ensureConnected(): PDO
    {
        try {
            if (self::$pdo instanceof PDO) {
                self::$pdo->query('SELECT 1');
                return self::$pdo;
            }
        } catch (Throwable $e) {
            self::$pdo = null;
        }
        return self::pdo();
    }

    public static function tableExists(string $table): bool
    {
        if (array_key_exists($table, self::$tableCache)) {
            return self::$tableCache[$table];
        }
        $st = self::pdo()->prepare(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
        );
        $st->execute([$table]);
        return self::$tableCache[$table] = (bool)$st->fetchColumn();
    }

    public static function columnExists(string $table, string $column): bool
    {
        $key = $table . '.' . $column;
        if (array_key_exists($key, self::$columnCache)) {
            return self::$columnCache[$key];
        }
        $st = self::pdo()->prepare(
            'SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
        );
        $st->execute([$table, $column]);
        return self::$columnCache[$key] = (bool)$st->fetchColumn();
    }

    /**
     * True for missing table/column SQLSTATE (42S02 / 42S22) — schema migrate needed.
     */
    public static function isMissingSchemaError(Throwable $e): bool
    {
        if ($e instanceof PDOException) {
            $state = (string)($e->errorInfo[0] ?? $e->getCode());
            if ($state === '42S02' || $state === '42S22') {
                return true;
            }
        }
        $msg = $e->getMessage();
        return stripos($msg, 'Unknown column') !== false
            || stripos($msg, "doesn't exist") !== false
            || stripos($msg, 'Base table or view not found') !== false;
    }

    /** Clear table/column existence caches (call after schema migrations). */
    public static function clearSchemaCache(): void
    {
        self::$tableCache = [];
        self::$columnCache = [];
    }
}
