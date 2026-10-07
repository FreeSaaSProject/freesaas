<?php
declare(strict_types=1);

/**
 * Current (ephemeral) 1C ticket ↔ client binding.
 * History of visits stays in base_fullstats / base_procedures.
 */
final class ClientOnecBindingsRepository
{
    public static function tableReady(): bool
    {
        return Database::tableExists('base_client_onec_bindings');
    }

    /** @return array{client_id:int,onec_id:string,dt_valid:int,updated_at:int}|null */
    public static function findByClientId(int $clientId, bool $onlyValid = false): ?array
    {
        if ($clientId < 1 || !self::tableReady()) {
            return null;
        }
        $sql = 'SELECT client_id, onec_id, dt_valid, updated_at
                FROM base_client_onec_bindings WHERE client_id = ?';
        $params = [$clientId];
        if ($onlyValid) {
            $sql .= ' AND dt_valid >= ?';
            $params[] = time();
        }
        $sql .= ' LIMIT 1';
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return [
            'client_id' => (int)$row['client_id'],
            'onec_id' => (string)$row['onec_id'],
            'dt_valid' => (int)$row['dt_valid'],
            'updated_at' => (int)$row['updated_at'],
        ];
    }

    /** @return array{client_id:int,onec_id:string,dt_valid:int,updated_at:int}|null */
    public static function findByOnecId(string $onecId, bool $onlyValid = true): ?array
    {
        $onecId = trim($onecId);
        if ($onecId === '' || !self::tableReady()) {
            return null;
        }
        $sql = 'SELECT client_id, onec_id, dt_valid, updated_at
                FROM base_client_onec_bindings WHERE onec_id = ?';
        $params = [$onecId];
        if ($onlyValid) {
            $sql .= ' AND dt_valid >= ?';
            $params[] = time();
        }
        $sql .= ' ORDER BY updated_at DESC LIMIT 1';
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return [
            'client_id' => (int)$row['client_id'],
            'onec_id' => (string)$row['onec_id'],
            'dt_valid' => (int)$row['dt_valid'],
            'updated_at' => (int)$row['updated_at'],
        ];
    }

    public static function bind(int $clientId, string $onecId, int $dtValid): void
    {
        if ($clientId < 1) {
            throw new InvalidArgumentException('Invalid client id');
        }
        $onecId = trim($onecId);
        if ($onecId === '') {
            throw new InvalidArgumentException(t('terminals.err.onec_id_required'));
        }
        if (strlen($onecId) > 60) {
            throw new InvalidArgumentException(t('terminals.err.onec_id_too_long'));
        }
        if (!self::tableReady()) {
            throw new RuntimeException('base_client_onec_bindings missing');
        }
        $now = time();
        $pdo = Database::pdo();
        // One active ticket id → one client (legacy shopusers.1C_ID uniqueness).
        $pdo->prepare(
            'DELETE FROM base_client_onec_bindings WHERE onec_id = ? AND client_id <> ?'
        )->execute([$onecId, $clientId]);
        $pdo->prepare(
            'INSERT INTO base_client_onec_bindings (client_id, onec_id, dt_valid, updated_at)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE onec_id = VALUES(onec_id), dt_valid = VALUES(dt_valid), updated_at = VALUES(updated_at)'
        )->execute([$clientId, $onecId, max(0, $dtValid), $now]);
    }

    public static function unbind(int $clientId): void
    {
        if ($clientId < 1 || !self::tableReady()) {
            return;
        }
        Database::pdo()->prepare(
            'DELETE FROM base_client_onec_bindings WHERE client_id = ?'
        )->execute([$clientId]);
    }

    /** @return int deleted rows */
    public static function expireStale(?int $now = null): int
    {
        if (!self::tableReady()) {
            return 0;
        }
        $now = $now ?? time();
        $st = Database::pdo()->prepare(
            'DELETE FROM base_client_onec_bindings WHERE dt_valid > 0 AND dt_valid < ?'
        );
        $st->execute([$now]);
        return $st->rowCount();
    }
}
