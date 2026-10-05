<?php
declare(strict_types=1);

final class SummariesRepository
{
    public static function tableReady(): bool
    {
        return Database::tableExists('base_records_summarys');
    }

    /** @return array<string,mixed> */
    public static function defaultConfig(): array
    {
        return [
            'cards' => ['volume', 'score', 'criteria', 'scripts', 'script_score', 'tracking', 'leaders'],
            'criteria_type_ids' => [],
            'script_ids' => [],
            'tracking_dict_ids' => [],
            'detail_criteria_ids' => [],
            'detail_tracking_dict_ids' => [],
            'detail_scripts_mode' => 'sum',
            'detail_show_score' => 1,
            'detail_show_pass' => 1,
            'detail_show_keys' => 1,
            'detail_show_script_score' => 1,
            'tracking_cooldown_sec' => 0,
            'score_pass_threshold' => 1.0,
            'benchmark_min_days' => 5,
            'color_thresholds' => ['red_max' => 40, 'yellow_max' => 70],
            // Reserved for likespa-audio-reports (accepted, not executed in core)
            'external_sources' => [],
            'computed_metrics' => [],
        ];
    }

    /** @return array<string,string> */
    public static function defaultLabels(): array
    {
        return [
            'volume' => '',
            'score' => '',
            'criteria' => '',
            'scripts' => '',
            'script_score' => '',
            'tracking' => '',
            'leaders' => '',
        ];
    }

    /**
     * @param mixed $config
     * @return array<string,mixed>
     */
    public static function normalizeConfig($config): array
    {
        $base = self::defaultConfig();
        if (!is_array($config)) {
            $config = [];
        }
        $out = array_merge($base, $config);

        foreach (['criteria_type_ids', 'script_ids', 'tracking_dict_ids', 'detail_criteria_ids', 'detail_tracking_dict_ids', 'cards'] as $k) {
            if ($k === 'cards') {
                $src = isset($config['cards']) && is_array($config['cards']) ? $config['cards'] : $base['cards'];
                $cards = [];
                foreach ($src as $c) {
                    $c = trim((string)$c);
                    if ($c !== '') {
                        $cards[] = $c;
                    }
                }
                $out['cards'] = $cards !== [] ? $cards : $base['cards'];
                continue;
            }
            $raw = isset($out[$k]) && is_array($out[$k]) ? $out[$k] : $base[$k];
            $ids = array_values(array_unique(array_map('intval', $raw)));
            $out[$k] = array_values(array_filter($ids, static function (int $v): bool {
                return $v > 0;
            }));
        }

        $out['score_pass_threshold'] = isset($out['score_pass_threshold']) ? (float)$out['score_pass_threshold'] : 1.0;
        $out['benchmark_min_days'] = isset($out['benchmark_min_days']) ? max(1, (int)$out['benchmark_min_days']) : 5;
        $mode = isset($out['detail_scripts_mode']) ? trim((string)$out['detail_scripts_mode']) : 'sum';
        if (!in_array($mode, ['sum', 'separate', 'both'], true)) {
            $mode = 'sum';
        }
        $out['detail_scripts_mode'] = $mode;
        $out['detail_show_score'] = !empty($out['detail_show_score']) ? 1 : 0;
        $out['detail_show_pass'] = !empty($out['detail_show_pass']) ? 1 : 0;
        $out['detail_show_keys'] = !empty($out['detail_show_keys']) ? 1 : 0;
        $out['detail_show_script_score'] = !empty($out['detail_show_script_score']) ? 1 : 0;
        $out['tracking_cooldown_sec'] = isset($out['tracking_cooldown_sec']) ? max(0, (int)$out['tracking_cooldown_sec']) : 0;
        if (!isset($out['color_thresholds']) || !is_array($out['color_thresholds'])) {
            $out['color_thresholds'] = $base['color_thresholds'];
        }
        // Keep opaque; core runner ignores them
        $out['external_sources'] = isset($config['external_sources']) && is_array($config['external_sources'])
            ? $config['external_sources'] : [];
        $out['computed_metrics'] = isset($config['computed_metrics']) && is_array($config['computed_metrics'])
            ? $config['computed_metrics'] : [];

        return $out;
    }

    /**
     * @param mixed $labels
     * @return array<string,string>
     */
    public static function normalizeLabels($labels): array
    {
        $base = self::defaultLabels();
        if (!is_array($labels)) {
            return $base;
        }
        $out = $base;
        foreach ($labels as $k => $v) {
            $out[(string)$k] = (string)$v;
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public static function list(bool $enabledOnly = false): array
    {
        if (!self::tableReady()) {
            return [];
        }
        $sql = 'SELECT * FROM base_records_summarys';
        if ($enabledOnly) {
            $sql .= ' WHERE ENABLED = 1';
        }
        $sql .= ' ORDER BY IS_DEFAULT DESC, ID_POSITION ASC, NAME ASC, ID ASC';
        $rows = Database::pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::hydrate($row);
        }
        return $out;
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare('SELECT * FROM base_records_summarys WHERE ID = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? self::hydrate($row) : null;
    }

    public static function findDefault(): ?array
    {
        if (!self::tableReady()) {
            return null;
        }
        $row = Database::pdo()->query(
            "SELECT * FROM base_records_summarys WHERE ENABLED = 1 AND IS_DEFAULT = 1 ORDER BY ID ASC LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $row = Database::pdo()->query(
                "SELECT * FROM base_records_summarys WHERE ENABLED = 1 ORDER BY ID ASC LIMIT 1"
            )->fetch(PDO::FETCH_ASSOC);
        }
        return $row ? self::hydrate($row) : null;
    }

    /**
     * @param array{NAME?:string,ID_POSITION?:int,CONFIG_JSON?:string|array,LABELS_JSON?:string|array,IS_DEFAULT?:int,ENABLED?:int,ID_CREATOR?:int} $data
     */
    public static function create(array $data): int
    {
        if (!self::tableReady()) {
            throw new RuntimeException('base_records_summarys missing');
        }
        $now = time();
        $config = self::encodeJson(self::normalizeConfig(
            is_array($data['CONFIG_JSON'] ?? null) ? $data['CONFIG_JSON'] : self::decodeJson((string)($data['CONFIG_JSON'] ?? '{}'))
        ));
        $labels = self::encodeJson(self::normalizeLabels(
            is_array($data['LABELS_JSON'] ?? null) ? $data['LABELS_JSON'] : self::decodeJson((string)($data['LABELS_JSON'] ?? '{}'))
        ));
        $isDefault = !empty($data['IS_DEFAULT']) ? 1 : 0;
        $st = Database::pdo()->prepare(
            'INSERT INTO base_records_summarys
             (NAME, ID_POSITION, CONFIG_JSON, LABELS_JSON, IS_DEFAULT, ENABLED, ID_CREATOR, DT_CREATE, DT_UPDATE)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            trim((string)($data['NAME'] ?? '')),
            (int)($data['ID_POSITION'] ?? 0),
            $config,
            $labels,
            $isDefault,
            isset($data['ENABLED']) ? (!empty($data['ENABLED']) ? 1 : 0) : 1,
            (int)($data['ID_CREATOR'] ?? 0),
            $now,
            $now,
        ]);
        $id = (int)Database::pdo()->lastInsertId();
        if ($isDefault && $id > 0) {
            self::clearDefaultExcept($id);
        }
        return $id;
    }

    /**
     * @param array{NAME?:string,ID_POSITION?:int,CONFIG_JSON?:string|array,LABELS_JSON?:string|array,IS_DEFAULT?:int,ENABLED?:int} $data
     */
    public static function update(int $id, array $data): void
    {
        if ($id < 1 || !self::tableReady()) {
            throw new RuntimeException('invalid summary id');
        }
        $config = self::encodeJson(self::normalizeConfig(
            is_array($data['CONFIG_JSON'] ?? null) ? $data['CONFIG_JSON'] : self::decodeJson((string)($data['CONFIG_JSON'] ?? '{}'))
        ));
        $labels = self::encodeJson(self::normalizeLabels(
            is_array($data['LABELS_JSON'] ?? null) ? $data['LABELS_JSON'] : self::decodeJson((string)($data['LABELS_JSON'] ?? '{}'))
        ));
        $isDefault = !empty($data['IS_DEFAULT']) ? 1 : 0;
        $st = Database::pdo()->prepare(
            'UPDATE base_records_summarys SET
             NAME = ?, ID_POSITION = ?, CONFIG_JSON = ?, LABELS_JSON = ?,
             IS_DEFAULT = ?, ENABLED = ?, DT_UPDATE = ?
             WHERE ID = ?'
        );
        $st->execute([
            trim((string)($data['NAME'] ?? '')),
            (int)($data['ID_POSITION'] ?? 0),
            $config,
            $labels,
            $isDefault,
            !empty($data['ENABLED']) ? 1 : 0,
            time(),
            $id,
        ]);
        if ($isDefault) {
            self::clearDefaultExcept($id);
        }
    }

    public static function delete(int $id): void
    {
        if ($id < 1 || !self::tableReady()) {
            return;
        }
        Database::pdo()->prepare('DELETE FROM base_records_summarys WHERE ID = ?')->execute([$id]);
    }

    /** @param array<string,mixed> $row */
    private static function hydrate(array $row): array
    {
        $row['ID'] = (int)($row['ID'] ?? 0);
        $row['ID_POSITION'] = (int)($row['ID_POSITION'] ?? 0);
        $row['IS_DEFAULT'] = (int)($row['IS_DEFAULT'] ?? 0);
        $row['ENABLED'] = (int)($row['ENABLED'] ?? 0);
        $row['config'] = self::normalizeConfig(self::decodeJson((string)($row['CONFIG_JSON'] ?? '{}')));
        $row['labels'] = self::normalizeLabels(self::decodeJson((string)($row['LABELS_JSON'] ?? '{}')));
        return $row;
    }

    private static function clearDefaultExcept(int $keepId): void
    {
        Database::pdo()->prepare(
            'UPDATE base_records_summarys SET IS_DEFAULT = 0 WHERE ID <> ?'
        )->execute([$keepId]);
    }

    /** @return array<string,mixed> */
    private static function decodeJson(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $data */
    private static function encodeJson(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        return $json !== false ? $json : '{}';
    }
}
