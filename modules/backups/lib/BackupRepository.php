<?php
declare(strict_types=1);

final class BackupRepository
{
    public static function tableReady(): bool
    {
        return Database::tableExists('base_backup_runs');
    }

    /**
     * @param array<string,mixed> $state
     */
    public static function start(string $triggeredBy = 'cron'): int
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare(
            'INSERT INTO base_backup_runs (STARTED_AT, FINISHED_AT, STATUS, PHASE, STATE_JSON, BYTES, REMOTE_DIR, ERROR, TRIGGERED_BY)
             VALUES (?, 0, ?, ?, ?, 0, ?, ?, ?)'
        );
        $state = ['phase' => 'init'];
        $st->execute([
            time(),
            'running',
            'init',
            json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            '',
            '',
            $triggeredBy,
        ]);
        return (int)$pdo->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        if ($id < 1 || !self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare('SELECT * FROM base_backup_runs WHERE ID = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::hydrate($row) : null;
    }

    /** @return array<string,mixed>|null */
    public static function findRunning(): ?array
    {
        if (!self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->query(
            "SELECT * FROM base_backup_runs WHERE STATUS = 'running' ORDER BY ID DESC LIMIT 1"
        );
        $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
        return is_array($row) ? self::hydrate($row) : null;
    }

    /** @return array<string,mixed>|null */
    public static function findLastSuccess(): ?array
    {
        if (!self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->query(
            "SELECT * FROM base_backup_runs WHERE STATUS = 'done' ORDER BY FINISHED_AT DESC, ID DESC LIMIT 1"
        );
        $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
        return is_array($row) ? self::hydrate($row) : null;
    }

    /** @return array<string,mixed>|null */
    public static function findLast(): ?array
    {
        if (!self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->query('SELECT * FROM base_backup_runs ORDER BY ID DESC LIMIT 1');
        $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
        return is_array($row) ? self::hydrate($row) : null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function listRecent(int $limit = 30): array
    {
        if (!self::tableReady()) {
            return [];
        }
        $limit = max(1, min(200, $limit));
        $st = Database::pdo()->query(
            'SELECT * FROM base_backup_runs ORDER BY ID DESC LIMIT ' . $limit
        );
        $out = [];
        if ($st) {
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                if (is_array($row)) {
                    $out[] = self::hydrate($row);
                }
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $state
     */
    public static function update(int $id, string $phase, array $state, int $bytes = 0, string $remoteDir = '', string $error = ''): void
    {
        $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = '{}';
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_backup_runs SET PHASE = ?, STATE_JSON = ?, BYTES = ?, REMOTE_DIR = ?, ERROR = ? WHERE ID = ?'
        );
        $st->execute([$phase, $json, max(0, $bytes), $remoteDir, mb_substr($error, 0, 1024), $id]);
    }

    public static function markDone(int $id, int $bytes, string $remoteDir): void
    {
        $st = Database::pdo()->prepare(
            "UPDATE base_backup_runs SET STATUS = 'done', PHASE = 'done', FINISHED_AT = ?, BYTES = ?, REMOTE_DIR = ?, ERROR = '' WHERE ID = ?"
        );
        $st->execute([time(), max(0, $bytes), $remoteDir, $id]);
    }

    public static function markFailed(int $id, string $error, string $phase = ''): void
    {
        $st = Database::pdo()->prepare(
            "UPDATE base_backup_runs SET STATUS = 'error', PHASE = ?, FINISHED_AT = ?, ERROR = ? WHERE ID = ?"
        );
        $st->execute([
            $phase !== '' ? $phase : 'error',
            time(),
            mb_substr($error, 0, 1024),
            $id,
        ]);
    }

    public static function markCancelled(int $id): void
    {
        $st = Database::pdo()->prepare(
            "UPDATE base_backup_runs SET STATUS = 'cancelled', FINISHED_AT = ? WHERE ID = ? AND STATUS = 'running'"
        );
        $st->execute([time(), $id]);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function hydrate(array $row): array
    {
        $state = [];
        $raw = (string)($row['STATE_JSON'] ?? '');
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $state = $decoded;
            }
        }
        return [
            'id' => (int)($row['ID'] ?? 0),
            'started_at' => (int)($row['STARTED_AT'] ?? 0),
            'finished_at' => (int)($row['FINISHED_AT'] ?? 0),
            'status' => (string)($row['STATUS'] ?? ''),
            'phase' => (string)($row['PHASE'] ?? ''),
            'state' => $state,
            'bytes' => (int)($row['BYTES'] ?? 0),
            'remote_dir' => (string)($row['REMOTE_DIR'] ?? ''),
            'error' => (string)($row['ERROR'] ?? ''),
            'triggered_by' => (string)($row['TRIGGERED_BY'] ?? ''),
        ];
    }
}
