<?php
declare(strict_types=1);

if (!class_exists('DayQualityMetrics', false)) {
    require_once __DIR__ . '/DayQualityMetrics.php';
}

/**
 * Day quality traffic lights from rated script sessions (no likespa hardcoding).
 * Part % = sessions with part hit / rated sessions of that script.
 * Day gates = named metrics (builtins + EventBus); unavailable → skip.
 * Per DAY_BONUS script: bonus if sessions and no red (parts or day gates).
 */
final class DayQualityReport
{
    /**
     * @param list<array<string,mixed>>|null $sessions optional prebuilt DayRatingReport sessions
     * @param array<string,mixed>|null $proceduresCompare DayRatingReport procedures block
     * @param array<string,mixed>|null $coverage AudioCoverageConfig::forAdmin
     * @return array<string,mixed>
     */
    public static function build(
        int $adminId,
        string $date,
        ?array $sessions = null,
        ?array $proceduresCompare = null,
        ?array $coverage = null
    ): array {
        $empty = [
            'ok' => false,
            'enabled' => false,
            'publish' => false,
            'has_red' => false,
            'bonus_eligible' => false,
            'bonus_sum' => 0,
            'bonus_sum_potential' => 0,
            'bonus_sum_missed' => 0,
            'scripts' => [],
            'parts' => [],
            'session_count' => 0,
            'procedure_count' => 0,
        ];
        if ($adminId < 1 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $empty;
        }
        $dayStart = strtotime($date . ' 00:00:00');
        if ($dayStart === false) {
            return $empty;
        }
        $dayEnd = (int)$dayStart + 86399;

        $scripts = self::loadScriptsForAdmin($adminId);
        $dayBonusScripts = array_values(array_filter(
            $scripts,
            static fn (array $s): bool => !empty($s['day_bonus'])
        ));
        if ($dayBonusScripts === [] && $scripts === []) {
            return $empty;
        }
        $publish = $dayBonusScripts !== [];
        if (!$publish) {
            return array_merge($empty, ['ok' => true, 'enabled' => false, 'publish' => false]);
        }

        if ($sessions === null) {
            $sessions = self::loadRatedSessions($adminId, (int)$dayStart, $dayEnd, array_column($scripts, 'id'));
        }
        $sessionCount = count($sessions);
        $byScriptSessions = [];
        $partHits = [];
        foreach ($sessions as $sess) {
            $sid = (int)($sess['script_id'] ?? 0);
            if ($sid < 1) {
                continue;
            }
            if (!isset($byScriptSessions[$sid])) {
                $byScriptSessions[$sid] = 0;
            }
            $byScriptSessions[$sid]++;
            $hitParts = [];
            foreach (is_array($sess['chain'] ?? null) ? $sess['chain'] : [] as $chip) {
                if (!empty($chip['hit'])) {
                    $hitParts[(int)($chip['part_id'] ?? 0)] = true;
                }
            }
            if ($hitParts === [] && isset($sess['id'])) {
                $hitParts = self::partHitsForCollection((int)$sess['id']);
            }
            if (!isset($partHits[$sid])) {
                $partHits[$sid] = [];
            }
            foreach ($hitParts as $pid => $_) {
                if ($pid < 1) {
                    continue;
                }
                if (!isset($partHits[$sid][$pid])) {
                    $partHits[$sid][$pid] = 0;
                }
                $partHits[$sid][$pid]++;
            }
        }

        if ($coverage === null && class_exists('AudioCoverageConfig', false)) {
            $coverage = AudioCoverageConfig::forAdmin($adminId);
        }
        $matchProc = !empty($coverage['match_procedures']);
        $procCount = 0;
        if ($matchProc) {
            if (is_array($proceduresCompare)) {
                $procCount = (int)($proceduresCompare['procedure_count'] ?? 0);
            }
            if ($procCount < 1) {
                $procCount = self::countPaidProcedures(
                    $adminId,
                    (int)$dayStart,
                    $dayEnd,
                    (int)($coverage['min_procedure_sec'] ?? 360)
                );
            }
        }

        $hasRed = false;
        $bonusSum = 0;
        $bonusPotential = 0;
        $scriptRows = [];
        $partRows = [];

        foreach ($dayBonusScripts as $script) {
            $sid = (int)$script['id'];
            $sessN = (int)($byScriptSessions[$sid] ?? 0);
            $gatesCfg = is_array($script['day_gates'] ?? null) ? $script['day_gates'] : [];
            $metrics = class_exists('DayQualityMetrics', false)
                ? DayQualityMetrics::resolve(
                    $adminId,
                    $date,
                    $sid,
                    $sessN,
                    $coverage,
                    $matchProc ? $procCount : null
                )
                : [];
            $gateRows = class_exists('DayQualityMetrics', false)
                ? DayQualityMetrics::evaluateGates($gatesCfg, $metrics)
                : [];

            $sum = max(0, (int)($script['day_bonus_sum'] ?? 0));
            $bonusPotential += $sum;

            $scriptHasRed = false;
            foreach ($gateRows as $gr) {
                if (!empty($gr['skipped'])) {
                    continue;
                }
                if (($gr['color'] ?? '') === 'red') {
                    $scriptHasRed = true;
                    $hasRed = true;
                }
            }

            // Legacy compat fields for older ticket/render paths.
            $procRatioMin = 0;
            $procRatioPct = null;
            $procRatioOk = true;
            $procRatioColor = 'ok';
            foreach ($gateRows as $gr) {
                if (($gr['metric'] ?? '') !== 'proc_ratio' || !empty($gr['skipped'])) {
                    continue;
                }
                $procRatioMin = (int)round((float)($gr['threshold'] ?? 0));
                $procRatioPct = $gr['value'];
                $procRatioOk = !empty($gr['ok']);
                $procRatioColor = (string)($gr['color'] ?? 'ok');
            }

            $scriptStarts = (int)round((float)($metrics['script_starts'] ?? 0));
            $partsOut = [];
            foreach (is_array($script['parts'] ?? null) ? $script['parts'] : [] as $part) {
                $pid = (int)$part['id'];
                $redMax = (float)($part['gate_red_max'] ?? 0);
                $yellowMax = (float)($part['gate_yellow_max'] ?? 0);
                $scoring = !($redMax <= 0.0 && $yellowMax <= 0.0);
                $condType = trim((string)($part['cond_type'] ?? ''));
                $hits = (int)($partHits[$sid][$pid] ?? 0);
                $den = $sessN;
                $pct = $den > 0 ? round(100.0 * $hits / $den, 1) : 0.0;
                $color = 'none';
                $partKind = 'phrase';

                // Day-level conversion parts (e.g. bracelet scans) — not phrase hits.
                if ($condType !== '' && self::isDayQualityPartMetric($condType)) {
                    $partKind = 'day_metric';
                    $pm = self::resolvePartMetric(
                        $condType,
                        $adminId,
                        $date,
                        $sid,
                        $scriptStarts,
                        $metrics
                    );
                    if (empty($pm['available'])) {
                        // Soft-dep off / no denominator → skip (not red).
                        $scoring = false;
                        $hits = 0;
                        $den = 0;
                        $pct = 0.0;
                        $color = 'none';
                    } else {
                        $hits = (int)($pm['numerator'] ?? 0);
                        $den = (int)($pm['denominator'] ?? 0);
                        $pct = (float)($pm['percent'] ?? 0);
                        if ($scoring) {
                            if ($pct < $redMax) {
                                $color = 'red';
                            } elseif ($pct <= $yellowMax) {
                                $color = 'yellow';
                            } else {
                                $color = 'green';
                            }
                            if ($color === 'red') {
                                $scriptHasRed = true;
                                $hasRed = true;
                            }
                        }
                    }
                } elseif ($scoring) {
                    if ($pct < $redMax) {
                        $color = 'red';
                    } elseif ($pct <= $yellowMax) {
                        $color = 'yellow';
                    } else {
                        $color = 'green';
                    }
                    if ($color === 'red') {
                        $scriptHasRed = true;
                        $hasRed = true;
                    }
                }
                $row = [
                    'script_id' => $sid,
                    'part_id' => $pid,
                    'name' => (string)$part['name'],
                    'numerator' => $hits,
                    'denominator' => $den,
                    'percent' => $pct,
                    'color' => $color,
                    'is_scoring' => $scoring,
                    'gate_red_max' => $redMax,
                    'gate_yellow_max' => $yellowMax,
                    'hide_from_reports' => !empty($part['hide_from_reports']) ? 1 : 0,
                    'cond_type' => $condType,
                    'part_kind' => $partKind,
                ];
                $partsOut[] = $row;
                if (empty($row['hide_from_reports'])) {
                    $partRows[] = $row;
                }
            }

            $scriptEligible = $sessN > 0 && !$scriptHasRed;
            if ($scriptEligible) {
                $bonusSum += $sum;
            }

            $visibleParts = array_values(array_filter(
                $partsOut,
                static fn (array $r): bool => empty($r['hide_from_reports'])
            ));
            $scriptRows[] = [
                'script_id' => $sid,
                'name' => (string)$script['name'],
                'day_bonus' => true,
                'day_bonus_sum' => $sum,
                'bonus_eligible' => $scriptEligible,
                'session_count' => $sessN,
                'day_gates' => $gateRows,
                'proc_ratio_min' => $procRatioMin,
                'proc_ratio_pct' => $procRatioPct,
                'proc_ratio_ok' => $procRatioOk,
                'proc_ratio_color' => $procRatioColor,
                'has_red' => $scriptHasRed,
                'parts' => $visibleParts,
            ];
        }

        $bonusMissed = max(0, $bonusPotential - $bonusSum);
        $bonusEligible = $publish && $bonusSum > 0;

        return [
            'ok' => true,
            'enabled' => true,
            'publish' => true,
            'has_red' => $hasRed,
            'bonus_eligible' => $bonusEligible,
            'bonus_sum' => $bonusSum,
            'bonus_sum_potential' => $bonusPotential,
            'bonus_sum_missed' => $bonusMissed,
            'scripts' => $scriptRows,
            'parts' => $partRows,
            'session_count' => $sessionCount,
            'procedure_count' => $procCount,
            'match_procedures' => $matchProc,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function loadScriptsForAdmin(int $adminId): array
    {
        if ($adminId < 1 || !Database::tableExists('base_records_scripts')) {
            return [];
        }
        if (!Database::columnExists('base_records_scripts', 'RATING_ENABLED')) {
            return [];
        }
        $positionId = 0;
        if (Database::tableExists('base_users')) {
            $st = Database::pdo()->prepare('SELECT ID_STATUS FROM base_users WHERE id = ? LIMIT 1');
            $st->execute([$adminId]);
            $positionId = (int)$st->fetchColumn();
        }
        $posIds = [$positionId];
        if ($positionId > 0 && class_exists('SummariesRepository', false)) {
            $eq = SummariesRepository::equivalentAudioPositionIds($positionId);
            if ($eq !== []) {
                $posIds = $eq;
            }
        }
        $hasDay = Database::columnExists('base_records_scripts', 'DAY_BONUS');
        $dayCols = $hasDay
            ? ', DAY_BONUS AS day_bonus, DAY_BONUS_SUM AS day_bonus_sum, PROC_RATIO_MIN AS proc_ratio_min'
            : ', 0 AS day_bonus, 0 AS day_bonus_sum, 0 AS proc_ratio_min';
        if (Database::columnExists('base_records_scripts', 'DAY_GATES')) {
            $dayCols .= ', DAY_GATES AS day_gates';
        } else {
            $dayCols .= ", '' AS day_gates";
        }
        $st = Database::pdo()->query(
            "SELECT ID AS id, NAME AS name, ID_POSITION AS id_position {$dayCols}
             FROM base_records_scripts
             WHERE PID = 0 AND RATING_ENABLED = 1 AND DISABLED < 1
             ORDER BY NUM ASC, NAME ASC, ID ASC"
        );
        if (!$st) {
            return [];
        }
        $roots = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $posRaw = (string)($row['id_position'] ?? '');
            if ($posRaw !== '' && $posRaw !== '0' && $positionId > 0) {
                $ok = false;
                foreach ($posIds as $pid) {
                    $pid = (int)$pid;
                    if ($pid < 1) {
                        continue;
                    }
                    if ($posRaw === (string)$pid || strpos($posRaw, '#' . $pid . '#') !== false) {
                        $ok = true;
                        break;
                    }
                }
                if (!$ok) {
                    continue;
                }
            }
            $gates = [];
            if (class_exists('RecordsScriptsRepository', false)) {
                $gates = RecordsScriptsRepository::resolveDayGatesFromRow($row);
            } else {
                $decoded = json_decode((string)($row['day_gates'] ?? ''), true);
                if (is_array($decoded)) {
                    $gates = $decoded;
                } elseif ((int)($row['proc_ratio_min'] ?? 0) > 0) {
                    $gates = [[
                        'metric' => 'proc_ratio',
                        'op' => 'gte',
                        'threshold' => (float)(int)$row['proc_ratio_min'],
                        'label' => '',
                    ]];
                }
            }
            $roots[] = [
                'id' => (int)$row['id'],
                'name' => (string)$row['name'],
                'day_bonus' => (int)($row['day_bonus'] ?? 0),
                'day_bonus_sum' => (int)($row['day_bonus_sum'] ?? 0),
                'day_gates' => $gates,
                'proc_ratio_min' => (int)($row['proc_ratio_min'] ?? 0),
                'parts' => [],
            ];
        }
        if ($roots === []) {
            return [];
        }
        $ids = array_map(static fn (array $r): int => (int)$r['id'], $roots);
        $partsBy = self::loadParts($ids);
        foreach ($roots as &$r) {
            $r['parts'] = $partsBy[(int)$r['id']] ?? [];
        }
        unset($r);

        return $roots;
    }

    /**
     * @param list<int> $scriptIds
     * @return array<int, list<array<string,mixed>>>
     */
    private static function loadParts(array $scriptIds): array
    {
        $out = [];
        foreach ($scriptIds as $sid) {
            $out[(int)$sid] = [];
        }
        if ($scriptIds === [] || !Database::tableExists('base_records_scripts')) {
            return $out;
        }
        $in = implode(',', array_map('intval', $scriptIds));
        $gateCols = Database::columnExists('base_records_scripts', 'GATE_RED_MAX')
            ? ', GATE_RED_MAX AS gate_red_max, GATE_YELLOW_MAX AS gate_yellow_max'
            : ', 0 AS gate_red_max, 0 AS gate_yellow_max';
        $hideCol = Database::columnExists('base_records_scripts', 'HIDE_FROM_REPORTS')
            ? ', HIDE_FROM_REPORTS AS hide_from_reports'
            : ', 0 AS hide_from_reports';
        $condCol = Database::columnExists('base_records_scripts', 'COND_TYPE')
            ? ', COND_TYPE AS cond_type'
            : ", '' AS cond_type";
        $rows = Database::pdo()->query(
            "SELECT ID AS id, PID AS pid, NAME AS name {$gateCols}{$hideCol}{$condCol}
             FROM base_records_scripts
             WHERE PID IN ($in) AND DISABLED < 1
             ORDER BY PID ASC, NUM ASC, ID ASC"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $pid = (int)$row['pid'];
            if (!isset($out[$pid])) {
                continue;
            }
            $out[$pid][] = [
                'id' => (int)$row['id'],
                'name' => trim((string)$row['name']) !== '' ? trim((string)$row['name']) : ('#' . (int)$row['id']),
                'gate_red_max' => (float)($row['gate_red_max'] ?? 0),
                'gate_yellow_max' => (float)($row['gate_yellow_max'] ?? 0),
                'hide_from_reports' => !empty($row['hide_from_reports']) ? 1 : 0,
                'cond_type' => trim((string)($row['cond_type'] ?? '')),
            ];
        }

        return $out;
    }

    /** Day-quality metric parts (not CollectRunner session activators). */
    private static function isDayQualityPartMetric(string $condType): bool
    {
        return $condType === 'term_scan_unique'
            || $condType === 'term_scan_conversion'
            || $condType === 'proc_created';
    }

    /**
     * Soft part metrics via EventBus (likespa term scans, etc.).
     *
     * @param array<string,float|int> $dayMetrics already resolved DayQualityMetrics
     * @return array{available:bool,percent?:float,numerator?:int,denominator?:int}
     */
    private static function resolvePartMetric(
        string $condType,
        int $adminId,
        string $date,
        int $scriptId,
        int $scriptStarts,
        array $dayMetrics
    ): array {
        $payload = EventBus::emit('audio_reports.day_quality.part_metric', [
            'cond_type' => $condType,
            'admin_id' => $adminId,
            'date' => $date,
            'script_id' => $scriptId,
            'script_starts' => $scriptStarts,
            'day_metrics' => $dayMetrics,
            'available' => false,
            'percent' => null,
            'numerator' => null,
            'denominator' => null,
        ]);
        if (!is_array($payload) || empty($payload['available'])) {
            return ['available' => false];
        }

        return [
            'available' => true,
            'percent' => (float)($payload['percent'] ?? 0),
            'numerator' => (int)($payload['numerator'] ?? 0),
            'denominator' => (int)($payload['denominator'] ?? 0),
        ];
    }

    /**
     * @param list<int> $scriptIds
     * @return list<array<string,mixed>>
     */
    private static function loadRatedSessions(int $adminId, int $dayStart, int $dayEnd, array $scriptIds): array
    {
        if ($scriptIds === [] || !Database::tableExists('base_records_collections')) {
            return [];
        }
        $in = implode(',', array_map('intval', $scriptIds));
        $ratedCond = Database::columnExists('base_records_collections', 'RATED')
            ? 'AND c.RATED = 1' : '';
        $st = Database::pdo()->prepare(
            "SELECT c.ID AS id, c.ID_SCRIPT AS script_id, c.DT_START AS dt_start, c.DT_END AS dt_end
             FROM base_records_collections c
             WHERE c.ID_ADMIN = ? AND c.ID_SCRIPT IN ($in) {$ratedCond}
               AND c.DT_START <= ? AND (c.DT_END = 0 OR c.DT_END >= ?)
             ORDER BY c.DT_START ASC"
        );
        $st->execute([$adminId, $dayEnd, $dayStart]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $cid = (int)$row['id'];
            $hits = self::partHitsForCollection($cid);
            $chain = [];
            foreach ($hits as $pid => $_) {
                $chain[] = ['part_id' => $pid, 'hit' => true];
            }
            $out[] = [
                'id' => $cid,
                'script_id' => (int)$row['script_id'],
                'dt_start' => (int)$row['dt_start'],
                'dt_end' => (int)$row['dt_end'],
                'chain' => $chain,
            ];
        }

        return $out;
    }

    /** @return array<int,true> */
    private static function partHitsForCollection(int $collectionId): array
    {
        if ($collectionId < 1 || !Database::tableExists('base_records_collections_datas')) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT ID_PART FROM base_records_collections_datas
             WHERE ID_COLLECTION = ? AND ID_PART > 0'
        );
        $st->execute([$collectionId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $pid) {
            $out[(int)$pid] = true;
        }

        return $out;
    }

    private static function countPaidProcedures(int $adminId, int $dayStart, int $dayEnd, int $minSec): int
    {
        if (!class_exists('ModuleManager', false) || !ModuleManager::isEnabled('procedures')) {
            return 0;
        }
        if (!Database::tableExists('base_procedures')) {
            return 0;
        }
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
