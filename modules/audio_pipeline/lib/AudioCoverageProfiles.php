<?php
declare(strict_types=1);

/**
 * Per-status (ID_POSITION) or per-staff (ID_ADMIN) coverage overrides.
 */
final class AudioCoverageProfiles
{
    public static function tableReady(): bool
    {
        return Database::tableExists('base_records_coverage_profiles');
    }

    /**
     * @return array{source:string,config:array<string,mixed>}|null
     */
    public static function resolvedForAdmin(int $adminId): ?array
    {
        if ($adminId < 1 || !self::tableReady() || !Database::tableExists('base_users')) {
            return null;
        }
        $user = self::findByAdmin($adminId);
        if ($user !== null && !empty($user['enabled'])) {
            return ['source' => 'admin', 'config' => $user['config']];
        }
        $st = Database::pdo()->prepare('SELECT ID_STATUS FROM base_users WHERE id = ? LIMIT 1');
        $st->execute([$adminId]);
        $positionId = (int)$st->fetchColumn();
        if ($positionId < 1) {
            return null;
        }
        $pos = self::findByPosition($positionId);
        if ($pos !== null && !empty($pos['enabled'])) {
            return ['source' => 'position', 'config' => $pos['config']];
        }

        return null;
    }

    /** @return array{id:int,position_id:int,admin_id:int,enabled:bool,config:array}|null */
    public static function findByPosition(int $positionId): ?array
    {
        if ($positionId < 1 || !self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID, ID_POSITION, ID_ADMIN, ENABLED, CONFIG
             FROM base_records_coverage_profiles
             WHERE ID_POSITION = ? AND ID_ADMIN = 0 LIMIT 1'
        );
        $st->execute([$positionId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ? self::hydrate($row) : null;
    }

    /** @return array{id:int,position_id:int,admin_id:int,enabled:bool,config:array}|null */
    public static function findByAdmin(int $adminId): ?array
    {
        if ($adminId < 1 || !self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID, ID_POSITION, ID_ADMIN, ENABLED, CONFIG
             FROM base_records_coverage_profiles
             WHERE ID_ADMIN = ? LIMIT 1'
        );
        $st->execute([$adminId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ? self::hydrate($row) : null;
    }

    /**
     * @param array<string,mixed> $config
     * @return array{id:int,position_id:int,admin_id:int,enabled:bool,config:array}
     */
    public static function savePosition(int $positionId, bool $enabled, array $config): array
    {
        return self::saveScope($positionId, 0, $enabled, $config);
    }

    /**
     * @param array<string,mixed> $config
     * @return array{id:int,position_id:int,admin_id:int,enabled:bool,config:array}
     */
    public static function saveAdmin(int $adminId, bool $enabled, array $config): array
    {
        $positionId = 0;
        if (Database::tableExists('base_users')) {
            $st = Database::pdo()->prepare('SELECT ID_STATUS FROM base_users WHERE id = ? LIMIT 1');
            $st->execute([$adminId]);
            $positionId = (int)$st->fetchColumn();
        }

        return self::saveScope($positionId, $adminId, $enabled, $config);
    }

    /**
     * @param array<string,mixed> $config
     * @return array{id:int,position_id:int,admin_id:int,enabled:bool,config:array}
     */
    private static function saveScope(int $positionId, int $adminId, bool $enabled, array $config): array
    {
        if (!self::tableReady()) {
            throw new RuntimeException('base_records_coverage_profiles missing');
        }
        if ($adminId < 1 && $positionId < 1) {
            throw new InvalidArgumentException('scope');
        }
        $config = self::normalizeConfig($config);
        $json = json_encode($config, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $json = '{}';
        }
        $now = time();
        $pdo = Database::pdo();
        if ($adminId > 0) {
            $existing = self::findByAdmin($adminId);
        } else {
            $existing = self::findByPosition($positionId);
        }
        if ($existing !== null) {
            $st = $pdo->prepare(
                'UPDATE base_records_coverage_profiles
                 SET ID_POSITION = ?, ID_ADMIN = ?, ENABLED = ?, CONFIG = ?, DT_UPDATE = ?
                 WHERE ID = ?'
            );
            $st->execute([
                $positionId,
                $adminId,
                $enabled ? 1 : 0,
                $json,
                $now,
                (int)$existing['id'],
            ]);
        } else {
            $st = $pdo->prepare(
                'INSERT INTO base_records_coverage_profiles
                 (ID_POSITION, ID_ADMIN, ENABLED, CONFIG, DT_UPDATE) VALUES (?,?,?,?,?)'
            );
            $st->execute([$positionId, $adminId, $enabled ? 1 : 0, $json, $now]);
        }
        $saved = $adminId > 0 ? self::findByAdmin($adminId) : self::findByPosition($positionId);
        if ($saved === null) {
            throw new RuntimeException('coverage profile save failed');
        }

        return $saved;
    }

    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    public static function normalizeConfig(array $config): array
    {
        $d = AudioCoverageConfig::defaults();

        return [
            'disable_outside_coverage' => array_key_exists('disable_outside_coverage', $config)
                ? !empty($config['disable_outside_coverage'])
                : $d['disable_outside_coverage'],
            'match_procedures' => array_key_exists('match_procedures', $config)
                ? !empty($config['match_procedures'])
                : true,
            'procedure_buffer_sec' => max(0, (int)($config['procedure_buffer_sec'] ?? $d['procedure_buffer_sec'])),
            'min_procedure_sec' => max(0, (int)($config['min_procedure_sec'] ?? $d['min_procedure_sec'])),
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array{id:int,position_id:int,admin_id:int,enabled:bool,config:array}
     */
    private static function hydrate(array $row): array
    {
        $cfg = [];
        $raw = (string)($row['CONFIG'] ?? '');
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $cfg = self::normalizeConfig($decoded);
            }
        }

        return [
            'id' => (int)$row['ID'],
            'position_id' => (int)$row['ID_POSITION'],
            'admin_id' => (int)$row['ID_ADMIN'],
            'enabled' => (int)$row['ENABLED'] > 0,
            'config' => $cfg !== [] ? $cfg : self::normalizeConfig([]),
        ];
    }
}
