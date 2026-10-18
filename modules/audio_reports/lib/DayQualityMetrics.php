<?php
declare(strict_types=1);

/**
 * Named metrics for script day-gates (builtins + EventBus providers).
 * External modules must not be hard-required: unavailable → gate skipped.
 */
final class DayQualityMetrics
{
    private const OPS = ['gt', 'gte', 'lt', 'lte'];

    /** @return list<string> */
    public static function ops(): array
    {
        return self::OPS;
    }

    /** Display symbol for a stored op code (gt/gte/lt/lte). */
    public static function opSymbol(string $op): string
    {
        switch (strtolower(trim($op))) {
            case 'gt':
                return '>';
            case 'lt':
                return '<';
            case 'lte':
                return '≤';
            case 'gte':
            default:
                return '≥';
        }
    }

    /**
     * Catalog for script UI: builtins + EventBus subscribers.
     *
     * @return list<array{id:string,label:string,unit?:string}>
     */
    public static function catalog(): array
    {
        $items = [
            [
                'id' => 'audio_playtime',
                'label' => t('audio_reports.day_quality.metric.audio_playtime'),
                'unit' => 's',
            ],
            [
                'id' => 'script_starts',
                'label' => t('audio_reports.day_quality.metric.script_starts'),
                'unit' => '',
            ],
            [
                'id' => 'script_finishes',
                'label' => t('audio_reports.day_quality.metric.script_finishes'),
                'unit' => '',
            ],
            [
                'id' => 'proc_ratio',
                'label' => t('audio_reports.day_quality.metric.proc_ratio'),
                'unit' => '%',
            ],
        ];
        $payload = EventBus::emit('audio_reports.day_quality.metrics.catalog', [
            'items' => $items,
        ]);
        $out = [];
        $seen = [];
        foreach (is_array($payload['items'] ?? null) ? $payload['items'] : $items as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string)($row['id'] ?? ''));
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = [
                'id' => $id,
                'label' => trim((string)($row['label'] ?? $id)) !== ''
                    ? trim((string)($row['label'] ?? $id))
                    : $id,
                'unit' => (string)($row['unit'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Resolve metric values for one script/day.
     * Missing / unavailable metrics are omitted (gate skip).
     *
     * @param array<string,mixed>|null $coverage AudioCoverageConfig::forAdmin
     * @return array<string,float|int>
     */
    public static function resolve(
        int $adminId,
        string $date,
        int $scriptId,
        int $sessionCount,
        ?array $coverage = null,
        ?int $procedureCount = null
    ): array {
        $metrics = [];
        if ($adminId < 1 || $date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $metrics;
        }
        $dayStart = strtotime($date . ' 00:00:00');
        if ($dayStart === false) {
            return $metrics;
        }
        $dayEnd = (int)$dayStart + 86399;

        $metrics['audio_playtime'] = self::audioPlaytime($adminId, (int)$dayStart, $dayEnd);
        $counts = self::scriptCollectionCounts($adminId, $scriptId, (int)$dayStart, $dayEnd);
        $metrics['script_starts'] = $counts['starts'];
        $metrics['script_finishes'] = $counts['finishes'];

        $procRatio = self::procRatio(
            $adminId,
            (int)$dayStart,
            $dayEnd,
            $sessionCount > 0 ? $sessionCount : $counts['starts'],
            $coverage,
            $procedureCount
        );
        if ($procRatio !== null) {
            $metrics['proc_ratio'] = $procRatio;
        }

        $payload = EventBus::emit('audio_reports.day_quality.metrics', [
            'admin_id' => $adminId,
            'date' => $date,
            'script_id' => $scriptId,
            'day_start' => (int)$dayStart,
            'day_end' => $dayEnd,
            'session_count' => $sessionCount,
            'script_starts' => $counts['starts'],
            'script_finishes' => $counts['finishes'],
            'metrics' => $metrics,
        ]);
        $merged = is_array($payload['metrics'] ?? null) ? $payload['metrics'] : $metrics;
        $out = [];
        foreach ($merged as $key => $val) {
            $id = trim((string)$key);
            if ($id === '' || $val === null || $val === false) {
                continue;
            }
            if (!is_numeric($val)) {
                continue;
            }
            $out[$id] = (float)$val;
        }

        return $out;
    }

    /** Human label for a metric id (catalog / i18n); empty if unknown. */
    public static function metricLabel(string $metricId): string
    {
        $metricId = trim($metricId);
        if ($metricId === '') {
            return '';
        }
        foreach (self::catalog() as $row) {
            if ((string)($row['id'] ?? '') === $metricId) {
                return trim((string)($row['label'] ?? ''));
            }
        }
        $key = 'audio_reports.day_quality.metric.' . $metricId;
        $viaKey = t($key);
        if ($viaKey !== $key) {
            return $viaKey;
        }

        return '';
    }

    /**
     * Evaluate configured gates against resolved metrics.
     *
     * @param list<array<string,mixed>> $gates
     * @param array<string,float|int> $metrics
     * @return list<array<string,mixed>>
     */
    public static function evaluateGates(array $gates, array $metrics): array
    {
        $rows = [];
        foreach ($gates as $gate) {
            if (!is_array($gate)) {
                continue;
            }
            $metric = trim((string)($gate['metric'] ?? ''));
            $op = strtolower(trim((string)($gate['op'] ?? 'gte')));
            if (!in_array($op, self::OPS, true)) {
                $op = 'gte';
            }
            $threshold = (float)($gate['threshold'] ?? 0);
            $label = trim((string)($gate['label'] ?? ''));
            if ($label === '' && $metric !== '') {
                $label = self::metricLabel($metric);
            }
            $available = array_key_exists($metric, $metrics);
            $value = $available ? (float)$metrics[$metric] : null;
            $ok = true;
            $color = 'skip';
            $skipped = !$available || $metric === '';
            if (!$skipped && $value !== null) {
                $ok = self::compare($value, $op, $threshold);
                $color = $ok ? 'green' : 'red';
            }
            $rows[] = [
                'metric' => $metric,
                'op' => $op,
                'threshold' => $threshold,
                'label' => $label !== '' ? $label : $metric,
                'value' => $value,
                'available' => $available && $metric !== '',
                'skipped' => $skipped,
                'ok' => $ok,
                'color' => $color,
            ];
        }

        return $rows;
    }

    public static function compare(float $value, string $op, float $threshold): bool
    {
        switch ($op) {
            case 'gt':
                return $value > $threshold;
            case 'lt':
                return $value < $threshold;
            case 'lte':
                return $value <= $threshold;
            case 'gte':
            default:
                return $value >= $threshold;
        }
    }

    private static function audioPlaytime(int $adminId, int $dayStart, int $dayEnd): int
    {
        if (!Database::tableExists('base_records')) {
            return 0;
        }
        $st = Database::pdo()->prepare(
            'SELECT COALESCE(SUM(PLAYTIME), 0) FROM base_records
             WHERE ID_ADMIN = ? AND DT_RECORDED >= ? AND DT_RECORDED <= ?
               AND (DISABLED IS NULL OR DISABLED < 1)'
        );
        $st->execute([$adminId, $dayStart, $dayEnd]);

        return max(0, (int)$st->fetchColumn());
    }

    /**
     * @return array{starts:int,finishes:int}
     */
    private static function scriptCollectionCounts(
        int $adminId,
        int $scriptId,
        int $dayStart,
        int $dayEnd
    ): array {
        $empty = ['starts' => 0, 'finishes' => 0];
        if ($scriptId < 1 || !Database::tableExists('base_records_collections')) {
            return $empty;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id FROM base_records_collections
             WHERE ID_ADMIN = ? AND ID_SCRIPT = ?
               AND DT_START >= ? AND DT_START <= ?'
        );
        $st->execute([$adminId, $scriptId, $dayStart, $dayEnd]);
        $ids = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $cid = (int)($row['id'] ?? 0);
            if ($cid > 0) {
                $ids[] = $cid;
            }
        }
        $starts = count($ids);
        if ($starts < 1 || !Database::tableExists('base_records_collections_datas')) {
            return ['starts' => $starts, 'finishes' => 0];
        }
        $finishPart = 0;
        if (Database::tableExists('base_records_scripts')) {
            $fp = Database::pdo()->prepare(
                'SELECT ID FROM base_records_scripts
                 WHERE PID = ? AND FINISH > 0 AND DISABLED < 1
                 ORDER BY NUM ASC, ID ASC LIMIT 1'
            );
            $fp->execute([$scriptId]);
            $finishPart = (int)$fp->fetchColumn();
        }
        if ($finishPart < 1) {
            return ['starts' => $starts, 'finishes' => 0];
        }
        $finishes = 0;
        foreach (array_chunk($ids, 400) as $chunk) {
            $in = implode(',', array_map('intval', $chunk));
            $q = Database::pdo()->query(
                "SELECT COUNT(DISTINCT ID_COLLECTION) FROM base_records_collections_datas
                 WHERE ID_COLLECTION IN ($in) AND ID_PART = " . (int)$finishPart
            );
            if ($q) {
                $finishes += (int)$q->fetchColumn();
            }
        }

        return ['starts' => $starts, 'finishes' => $finishes];
    }

    /**
     * Soft procedures metric: only when coverage match_procedures and module/table ready.
     *
     * @param array<string,mixed>|null $coverage
     */
    private static function procRatio(
        int $adminId,
        int $dayStart,
        int $dayEnd,
        int $sessionOrStarts,
        ?array $coverage,
        ?int $procedureCount
    ): ?float {
        if ($coverage === null && class_exists('AudioCoverageConfig', false)) {
            $coverage = AudioCoverageConfig::forAdmin($adminId);
        }
        if (empty($coverage['match_procedures'])) {
            return null;
        }
        if (!class_exists('ModuleManager', false) || !ModuleManager::isEnabled('procedures')) {
            return null;
        }
        if (!Database::tableExists('base_procedures')) {
            return null;
        }
        $procCount = $procedureCount;
        if ($procCount === null) {
            $procCount = self::countPaidProcedures(
                $adminId,
                $dayStart,
                $dayEnd,
                (int)($coverage['min_procedure_sec'] ?? 360)
            );
        }
        if ($procCount < 1) {
            // Same as legacy: no procedures → gate not applied (unavailable/skip).
            return null;
        }

        return round(100.0 * max(0, $sessionOrStarts) / $procCount, 1);
    }

    private static function countPaidProcedures(int $adminId, int $dayStart, int $dayEnd, int $minSec): int
    {
        $opCol = Database::columnExists('base_procedures', 'ID_OPERATOR')
            ? 'ID_OPERATOR' : 'ID_ADMIN';
        $st = Database::pdo()->prepare(
            "SELECT DTSTART, DTEND FROM base_procedures
             WHERE {$opCol} = ? AND ENABLED = 1 AND SUMM > 0
               AND DTSTART <= ? AND (DTEND = 0 OR DTEND >= ?)"
        );
        $st->execute([$adminId, $dayEnd, $dayStart]);
        $n = 0;
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $ps = (int)$row['DTSTART'];
            $pe = (int)$row['DTEND'];
            if ($pe < 1) {
                $pe = $ps;
            }
            $dur = max(0, $pe - $ps);
            if ($minSec > 0 && $dur < $minSec) {
                continue;
            }
            $n++;
        }

        return $n;
    }
}
