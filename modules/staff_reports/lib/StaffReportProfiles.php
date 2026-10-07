<?php
declare(strict_types=1);

/** CRUD for base_staff_report_profiles. */
final class StaffReportProfiles
{
    public static function tableReady(): bool
    {
        return Database::tableExists('base_staff_report_profiles');
    }

    /** @return list<array{id:int,position_id:int,enabled:bool,config:array,position_name:string,dt_update:int}> */
    public static function listAll(): array
    {
        if (!self::tableReady()) {
            return [];
        }
        $rows = Database::pdo()->query(
            'SELECT ID, ID_POSITION, ENABLED, CONFIG, DT_UPDATE FROM base_staff_report_profiles ORDER BY ID_POSITION ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::hydrate($row);
        }
        return $out;
    }

    /** @return list<array{id:int,position_id:int,enabled:bool,config:array,position_name:string,dt_update:int}> */
    public static function listEnabled(): array
    {
        $all = self::listAll();
        return array_values(array_filter($all, static function (array $p): bool {
            return !empty($p['enabled']);
        }));
    }

    /** @return array{id:int,position_id:int,enabled:bool,config:array,position_name:string,dt_update:int}|null */
    public static function findByPosition(int $positionId): ?array
    {
        if ($positionId < 1 || !self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID, ID_POSITION, ENABLED, CONFIG, DT_UPDATE FROM base_staff_report_profiles WHERE ID_POSITION = ? LIMIT 1'
        );
        $st->execute([$positionId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? self::hydrate($row) : null;
    }

    /**
     * Enabled profile for employee (by base_users.ID_STATUS), or null.
     * @return array{id:int,position_id:int,enabled:bool,config:array,position_name:string,dt_update:int}|null
     */
    public static function forEmployee(int $employeeId): ?array
    {
        if ($employeeId < 1 || !Database::tableExists('base_users')) {
            return null;
        }
        $st = Database::pdo()->prepare('SELECT ID_STATUS FROM base_users WHERE id = ? LIMIT 1');
        $st->execute([$employeeId]);
        $pid = (int)$st->fetchColumn();
        if ($pid < 1) {
            return null;
        }
        $profile = self::findByPosition($pid);
        if ($profile === null || empty($profile['enabled'])) {
            return null;
        }
        return $profile;
    }

    /**
     * @param array<string,mixed> $config
     * @return array{id:int,position_id:int,enabled:bool,config:array,position_name:string,dt_update:int}
     */
    public static function save(int $positionId, bool $enabled, array $config): array
    {
        if ($positionId < 1) {
            throw new InvalidArgumentException('position');
        }
        if (!self::tableReady()) {
            throw new RuntimeException('table missing');
        }
        $config = StaffReportConfig::normalize($config);
        $json = json_encode($config, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $json = '{}';
        }
        $now = time();
        $existing = self::findByPosition($positionId);
        $pdo = Database::pdo();
        if ($existing !== null) {
            $st = $pdo->prepare(
                'UPDATE base_staff_report_profiles SET ENABLED = ?, CONFIG = ?, DT_UPDATE = ? WHERE ID = ?'
            );
            $st->execute([$enabled ? 1 : 0, $json, $now, (int)$existing['id']]);
        } else {
            $st = $pdo->prepare(
                'INSERT INTO base_staff_report_profiles (ID_POSITION, ENABLED, CONFIG, DT_UPDATE) VALUES (?,?,?,?)'
            );
            $st->execute([$positionId, $enabled ? 1 : 0, $json, $now]);
        }
        $saved = self::findByPosition($positionId);
        if ($saved === null) {
            throw new RuntimeException('save failed');
        }
        return $saved;
    }

    /**
     * Positions with enabled profiles (including audio-equivalent expansion when configured).
     * @return array<int,true> position_id => true
     */
    public static function enabledPositionSet(): array
    {
        $set = [];
        foreach (self::listEnabled() as $p) {
            $pid = (int)$p['position_id'];
            $set[$pid] = true;
            $cfg = $p['config'];
            if (!empty($cfg['analysis']['equivalent_via_audio_position'])) {
                foreach (self::equivalentPositions($pid) as $eq) {
                    $set[(int)$eq] = true;
                }
            }
        }
        return $set;
    }

    /** @return list<int> */
    public static function equivalentPositions(int $positionId): array
    {
        if ($positionId <= 0) {
            return [];
        }
        $statuses = [];
        if (Database::tableExists('base_admin_statuses')) {
            $cols = Database::columnExists('base_admin_statuses', 'ID_AUDIO_POSITION')
                ? 'ID, ID_AUDIO_POSITION' : 'ID';
            $st = Database::pdo()->query('SELECT ' . $cols . ' FROM base_admin_statuses');
            if ($st) {
                while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                    $statuses[(int)$row['ID']] = [
                        'ID_AUDIO_POSITION' => (int)($row['ID_AUDIO_POSITION'] ?? 0),
                    ];
                }
            }
        }
        $result = [$positionId => true];
        $queue = [$positionId];
        while ($queue !== []) {
            $cur = (int)array_shift($queue);
            $fwd = (int)($statuses[$cur]['ID_AUDIO_POSITION'] ?? 0);
            if ($fwd > 0 && empty($result[$fwd])) {
                $result[$fwd] = true;
                $queue[] = $fwd;
            }
            foreach ($statuses as $otherId => $otherData) {
                if ((int)($otherData['ID_AUDIO_POSITION'] ?? 0) === $cur && empty($result[(int)$otherId])) {
                    $result[(int)$otherId] = true;
                    $queue[] = (int)$otherId;
                }
            }
        }
        return array_map('intval', array_keys($result));
    }

    /** @param array<string,mixed> $row */
    private static function hydrate(array $row): array
    {
        $pid = (int)($row['ID_POSITION'] ?? 0);
        $raw = (string)($row['CONFIG'] ?? '');
        $decoded = $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($decoded)) {
            $decoded = [];
        }
        return [
            'id' => (int)($row['ID'] ?? 0),
            'position_id' => $pid,
            'enabled' => !empty($row['ENABLED']),
            'config' => StaffReportConfig::normalize($decoded),
            'position_name' => self::positionName($pid),
            'dt_update' => (int)($row['DT_UPDATE'] ?? 0),
        ];
    }

    private static function positionName(int $positionId): string
    {
        if ($positionId < 1 || !Database::tableExists('base_admin_statuses')) {
            return '';
        }
        $st = Database::pdo()->prepare('SELECT NAME FROM base_admin_statuses WHERE ID = ? LIMIT 1');
        $st->execute([$positionId]);
        $name = $st->fetchColumn();
        return $name !== false ? trim((string)$name) : '';
    }
}
