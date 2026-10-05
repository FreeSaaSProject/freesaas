<?php
declare(strict_types=1);

/**
 * Core summary aggregation (volume / score / criteria / scripts / tracking / leaders).
 * external_sources & computed_metrics are not executed here — EventBus hook for LikeSpa.
 */
final class SummaryRunner
{
    /**
     * @param array<string,mixed> $profile hydrated SummariesRepository row
     * @return array<string,mixed>
     */
    public static function run(array $profile, int $year, int $month, string $dateFrom = '', string $dateTo = ''): array
    {
        $period = self::resolvePeriod($year, $month, $dateFrom, $dateTo);
        $config = SummariesRepository::normalizeConfig($profile['config'] ?? []);
        $labels = SummariesRepository::normalizeLabels($profile['labels'] ?? []);
        $cards = CalendarHooks::emitSummaryCards(
            is_array($config['cards'] ?? null) ? $config['cards'] : [],
            ['profile_id' => (int)($profile['ID'] ?? 0), 'period' => $period]
        );
        $config['cards'] = $cards;

        $positionId = (int)($profile['ID_POSITION'] ?? 0);
        $employees = self::employeesForPosition($positionId);
        $empIds = array_keys($employees);

        $empStats = [];
        foreach ($empIds as $empId) {
            $empStats[$empId] = [
                'days' => [],
                'playtime' => 0,
                'score_w' => 0.0,
                'score_sec' => 0,
                'instances' => 0,
                'script_starts' => [],
                'script_finishes' => [],
                'script_score' => [],
                'type_hit_sec' => [],
                'tracking' => [],
                'days_pass_score' => 0,
                'days_fail_score' => 0,
            ];
        }

        $totalPlay = 0;
        $totalRecords = 0;
        $threshold = (float)($config['score_pass_threshold'] ?? 1.0);

        if ($empIds !== [] && Database::tableExists('base_records')) {
            $in = implode(',', array_map('intval', $empIds));
            $sql = "SELECT ID_ADMIN AS id_admin, PLAYTIME AS playtime, SCORE AS score,
                           KEY_TYPES AS key_types, DT_RECORDED AS dt
                    FROM base_records
                    WHERE ID_ADMIN IN ($in)
                      AND (DISABLED IS NULL OR DISABLED < 1)
                      AND DT_RECORDED >= ? AND DT_RECORDED <= ?";
            $st = Database::pdo()->prepare($sql);
            $st->execute([(int)$period['start'], (int)$period['end']]);
            $dayScoreAcc = [];
            $criteriaIds = array_map('intval', $config['criteria_type_ids'] ?? []);
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $empId = (int)$r['id_admin'];
                if (!isset($empStats[$empId])) {
                    continue;
                }
                $play = max(0, (int)$r['playtime']);
                $score = max(0.0, (float)$r['score']);
                $dayKey = date('Y-m-d', (int)$r['dt']);
                $empStats[$empId]['days'][$dayKey] = true;
                $empStats[$empId]['playtime'] += $play;
                $empStats[$empId]['score_w'] += $score * $play;
                $empStats[$empId]['score_sec'] += $play;
                $totalPlay += $play;
                $totalRecords++;

                $dk = $empId . '|' . $dayKey;
                if (!isset($dayScoreAcc[$dk])) {
                    $dayScoreAcc[$dk] = ['w' => 0.0, 'sec' => 0];
                }
                $dayScoreAcc[$dk]['w'] += $score * $play;
                $dayScoreAcc[$dk]['sec'] += $play;

                if ($criteriaIds !== []) {
                    $kt = trim((string)($r['key_types'] ?? ''));
                    if ($kt !== '') {
                        foreach (preg_split('/[#,\s]+/', $kt) ?: [] as $tid) {
                            $tid = (int)$tid;
                            if ($tid > 0 && in_array($tid, $criteriaIds, true)) {
                                if (!isset($empStats[$empId]['type_hit_sec'][$tid])) {
                                    $empStats[$empId]['type_hit_sec'][$tid] = 0;
                                }
                                $empStats[$empId]['type_hit_sec'][$tid] += $play;
                            }
                        }
                    }
                }
            }
            foreach ($dayScoreAcc as $dk => $acc) {
                [$empIdStr] = explode('|', $dk, 2);
                $empId = (int)$empIdStr;
                if (!isset($empStats[$empId]) || $acc['sec'] < 1) {
                    continue;
                }
                $avg = $acc['w'] / $acc['sec'];
                if ($avg >= $threshold) {
                    $empStats[$empId]['days_pass_score']++;
                } else {
                    $empStats[$empId]['days_fail_score']++;
                }
            }
        }

        // Script collections
        $scriptIds = array_map('intval', $config['script_ids'] ?? []);
        if ($empIds !== [] && $scriptIds !== [] && Database::tableExists('base_records_collections')) {
            $inE = implode(',', array_map('intval', $empIds));
            $inS = implode(',', $scriptIds);
            $sql = "SELECT ID_ADMIN AS id_admin, ID_SCRIPT AS id_script, SCORE AS score, DT_START AS dt
                    FROM base_records_collections
                    WHERE ID_ADMIN IN ($inE) AND ID_SCRIPT IN ($inS)
                      AND DT_START >= ? AND DT_START <= ?";
            $st = Database::pdo()->prepare($sql);
            $st->execute([(int)$period['start'], (int)$period['end']]);
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $empId = (int)$r['id_admin'];
                $sid = (int)$r['id_script'];
                if (!isset($empStats[$empId])) {
                    continue;
                }
                if (!isset($empStats[$empId]['script_starts'][$sid])) {
                    $empStats[$empId]['script_starts'][$sid] = 0;
                    $empStats[$empId]['script_score'][$sid] = 0;
                }
                $empStats[$empId]['script_starts'][$sid]++;
                $empStats[$empId]['script_score'][$sid] += (int)$r['score'];
            }
        }

        // Tracking instances by dict (via keys.ID_DICTONARY)
        $trackIds = array_map('intval', $config['tracking_dict_ids'] ?? []);
        if ($empIds !== [] && $trackIds !== []
            && Database::tableExists('base_records_instances')
            && Database::tableExists('base_records_keys')
        ) {
            $inE = implode(',', array_map('intval', $empIds));
            $inD = implode(',', $trackIds);
            $sql = "SELECT i.ID_ADMIN AS id_admin, k.ID_DICTONARY AS id_dict, COUNT(*) AS cnt
                    FROM base_records_instances i
                    INNER JOIN base_records_keys k ON k.ID = i.ID_KEY
                    WHERE i.ID_ADMIN IN ($inE) AND k.ID_DICTONARY IN ($inD)
                      AND i.DT_START >= ? AND i.DT_START <= ?
                    GROUP BY i.ID_ADMIN, k.ID_DICTONARY";
            $st = Database::pdo()->prepare($sql);
            $st->execute([(int)$period['start'], (int)$period['end']]);
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $empId = (int)$r['id_admin'];
                $did = (int)$r['id_dict'];
                if (!isset($empStats[$empId])) {
                    continue;
                }
                $empStats[$empId]['tracking'][$did] = (int)$r['cnt'];
            }
        }

        $rows = [];
        $globalScoreW = 0.0;
        $globalScoreSec = 0;
        $globalPass = 0;
        $globalFail = 0;
        $totalScriptScore = 0;
        foreach ($empIds as $empId) {
            $s = $empStats[$empId];
            $avg = $s['score_sec'] > 0 ? round($s['score_w'] / $s['score_sec'], 2) : null;
            $hours = round($s['playtime'] / 3600, 2);
            $scriptScoreSum = array_sum($s['script_score']);
            $totalScriptScore += $scriptScoreSum;
            $globalScoreW += $s['score_w'];
            $globalScoreSec += $s['score_sec'];
            $globalPass += $s['days_pass_score'];
            $globalFail += $s['days_fail_score'];
            $rows[] = [
                'employee' => $employees[$empId],
                'hours' => $hours,
                'playtime' => $s['playtime'],
                'avg_score' => $avg,
                'days' => count($s['days']),
                'days_pass' => $s['days_pass_score'],
                'days_fail' => $s['days_fail_score'],
                'script_score' => $scriptScoreSum,
                'script_starts' => $s['script_starts'],
                'tracking' => $s['tracking'],
                'type_hit_sec' => $s['type_hit_sec'],
            ];
        }
        usort($rows, static function (array $a, array $b): int {
            return ($b['hours'] <=> $a['hours']) ?: strcmp((string)$a['employee']['name'], (string)$b['employee']['name']);
        });

        $external = CalendarHooks::emitSummaryExternal([
            'profile_id' => (int)($profile['ID'] ?? 0),
            'position_id' => $positionId,
            'period' => $period,
            'employee_ids' => $empIds,
        ]);

        return [
            'profile_id' => (int)($profile['ID'] ?? 0),
            'profile_name' => (string)($profile['NAME'] ?? ''),
            'is_default' => !empty($profile['IS_DEFAULT']),
            'position_id' => $positionId,
            'period' => $period,
            'config' => $config,
            'labels' => $labels,
            'cards' => $cards,
            'totals' => [
                'audio_hours' => round($totalPlay / 3600, 2),
                'records' => $totalRecords,
                'avg_score' => $globalScoreSec > 0 ? round($globalScoreW / $globalScoreSec, 2) : null,
                'score_pass_days' => $globalPass,
                'score_fail_days' => $globalFail,
                'criteria_count' => count($config['criteria_type_ids'] ?? []),
                'scripts_count' => count($config['script_ids'] ?? []),
                'tracking_count' => count($config['tracking_dict_ids'] ?? []),
                'script_score_total' => $totalScriptScore,
            ],
            'rows' => $rows,
            'external' => $external,
        ];
    }

    /**
     * @return array{mode:string,start:int,end:int,year:int,month:int,date_from:string,date_to:string,label:string}
     */
    public static function resolvePeriod(int $year, int $month, string $dateFrom = '', string $dateTo = ''): array
    {
        $dateFrom = trim($dateFrom);
        $dateTo = trim($dateTo);
        if ($dateFrom !== '' && $dateTo !== ''
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)
        ) {
            $start = strtotime($dateFrom . ' 00:00:00');
            $end = strtotime($dateTo . ' 23:59:59');
            if ($start !== false && $end !== false && $end >= $start) {
                return [
                    'mode' => 'range',
                    'start' => (int)$start,
                    'end' => (int)$end,
                    'year' => (int)date('Y', $start),
                    'month' => (int)date('n', $start),
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'label' => $dateFrom . ' — ' . $dateTo,
                ];
            }
        }
        if ($year < 2000 || $year > 2100) {
            $year = (int)date('Y');
        }
        if ($month < 1 || $month > 12) {
            $month = (int)date('n');
        }
        $start = mktime(0, 0, 0, $month, 1, $year);
        $end = mktime(23, 59, 59, $month, (int)date('t', $start ?: time()), $year);
        return [
            'mode' => 'month',
            'start' => (int)$start,
            'end' => (int)$end,
            'year' => $year,
            'month' => $month,
            'date_from' => date('Y-m-d', (int)$start),
            'date_to' => date('Y-m-d', (int)$end),
            'label' => t('audio_reports.calendar.month.' . $month) . ' ' . $year,
        ];
    }

    /**
     * @return array<int, array{id:int,name:string,color:string,color_text:string}>
     */
    private static function employeesForPosition(int $positionId): array
    {
        if ($positionId < 1 || !Database::tableExists('base_users')) {
            return [];
        }
        $st = Database::pdo()->prepare(
            "SELECT id, name, color, color_text FROM base_users
             WHERE name_enabled = ? AND enabled = '1'
               AND (IS_HIDDEN IS NULL OR IS_HIDDEN < 1)
             ORDER BY name ASC"
        );
        $st->execute([$positionId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $u) {
            $id = (int)$u['id'];
            $out[$id] = [
                'id' => $id,
                'name' => trim((string)($u['name'] ?? '')) ?: ('#' . $id),
                'color' => (string)($u['color'] ?? ''),
                'color_text' => (string)($u['color_text'] ?? ''),
            ];
        }
        return $out;
    }
}
