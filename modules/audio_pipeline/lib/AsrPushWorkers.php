<?php
declare(strict_types=1);

/**
 * Registry of push (inbound HTTPS) ASR workers — base_asr_push_workers.
 */
final class AsrPushWorkers
{
    public static function available(): bool
    {
        return Database::tableExists('base_asr_push_workers');
    }

    /** @return list<array<string,mixed>> */
    public static function all(): array
    {
        if (!self::available()) {
            return [];
        }
        $st = Database::pdo()->query('SELECT * FROM base_asr_push_workers ORDER BY ID ASC');
        return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    /** @return list<array<string,mixed>> */
    public static function enabled(): array
    {
        return array_values(array_filter(self::all(), static fn (array $w): bool => (int)$w['ENABLED'] === 1));
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        if ($id < 1 || !self::available()) {
            return null;
        }
        $st = Database::pdo()->prepare('SELECT * FROM base_asr_push_workers WHERE ID = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function newSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** @return array{id:int,secret:string} */
    public static function create(string $name, string $baseUrl, string $pin, int $maxInflight): array
    {
        $secret = self::newSecret();
        Database::pdo()->prepare(
            'INSERT INTO base_asr_push_workers (NAME, BASE_URL, SECRET, PIN_SHA256, ENABLED, MAX_INFLIGHT, DT_CREATED)
             VALUES (?, ?, ?, ?, 0, ?, ?)'
        )->execute([
            mb_substr(trim($name), 0, 100),
            rtrim(trim($baseUrl), '/'),
            $secret,
            trim($pin),
            self::clampInflight($maxInflight),
            time(),
        ]);

        return ['id' => (int)Database::pdo()->lastInsertId(), 'secret' => $secret];
    }

    public static function update(int $id, string $name, string $baseUrl, string $pin, int $maxInflight, bool $enabled): void
    {
        Database::pdo()->prepare(
            'UPDATE base_asr_push_workers
                SET NAME = ?, BASE_URL = ?, PIN_SHA256 = ?, MAX_INFLIGHT = ?, ENABLED = ?, FAILS = 0, BACKOFF_UNTIL = 0
              WHERE ID = ?'
        )->execute([
            mb_substr(trim($name), 0, 100),
            rtrim(trim($baseUrl), '/'),
            trim($pin),
            self::clampInflight($maxInflight),
            $enabled ? 1 : 0,
            $id,
        ]);
    }

    /** Step 1 of rotation: worker must accept SECRET_NEXT (push.secret_next) before promote. */
    public static function rotateNext(int $id): string
    {
        $next = self::newSecret();
        Database::pdo()->prepare('UPDATE base_asr_push_workers SET SECRET_NEXT = ? WHERE ID = ?')->execute([$next, $id]);
        return $next;
    }

    public static function promoteNext(int $id): bool
    {
        $st = Database::pdo()->prepare(
            "UPDATE base_asr_push_workers SET SECRET = SECRET_NEXT, SECRET_NEXT = ''
              WHERE ID = ? AND SECRET_NEXT <> ''"
        );
        $st->execute([$id]);
        return $st->rowCount() === 1;
    }

    public static function delete(int $id): void
    {
        AsrLease::releaseWorker($id);
        Database::pdo()->prepare('DELETE FROM base_asr_push_workers WHERE ID = ?')->execute([$id]);
    }

    /** @param array<string,mixed> $status */
    public static function noteSuccess(int $id, array $status): void
    {
        Database::pdo()->prepare(
            "UPDATE base_asr_push_workers
                SET LAST_SEEN = ?, LAST_STATUS = ?, LAST_ERROR = '', FAILS = 0, BACKOFF_UNTIL = 0
              WHERE ID = ?"
        )->execute([time(), json_encode($status, JSON_UNESCAPED_UNICODE), $id]);
    }

    /** Backoff ladder 5m → 10m → 30m → 60m; leases expire meanwhile so other channels take over. */
    public static function noteFailure(int $id, string $error): int
    {
        $w = self::find($id);
        $fails = (int)($w['FAILS'] ?? 0) + 1;
        $ladder = [300, 600, 1800, 3600];
        $delay = $ladder[min($fails, count($ladder)) - 1];
        Database::pdo()->prepare(
            'UPDATE base_asr_push_workers SET LAST_ERROR = ?, FAILS = ?, BACKOFF_UNTIL = ? WHERE ID = ?'
        )->execute([mb_substr($error, 0, 500), $fails, time() + $delay, $id]);
        return $delay;
    }

    public static function noteProbeError(int $id, string $error): void
    {
        Database::pdo()->prepare('UPDATE base_asr_push_workers SET LAST_ERROR = ? WHERE ID = ?')
            ->execute([mb_substr($error, 0, 500), $id]);
    }

    public static function mask(string $secret): string
    {
        return $secret === '' ? '' : substr($secret, 0, 4) . '…' . substr($secret, -4);
    }

    private static function clampInflight(int $n): int
    {
        return max(1, min(200, $n > 0 ? $n : 20));
    }
}
