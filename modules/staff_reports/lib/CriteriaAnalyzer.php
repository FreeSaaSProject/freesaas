<?php
declare(strict_types=1);

/** Criteria traffic-light analysis for staff day reports. */
final class CriteriaAnalyzer
{
    /**
     * @param array<string,mixed> $config
     * @return array<int, array{ID:int,NAME:string,SCORE_VALUE:float,is_scoring:bool}>
     */
    public static function loadCriteriaTypes(int $employeeId, array $config): array
    {
        if ($employeeId < 1 || !Database::tableExists('base_users')) {
            return [];
        }
        $st = Database::pdo()->prepare('SELECT ID_STATUS FROM base_users WHERE id = ? LIMIT 1');
        $st->execute([$employeeId]);
        $positionId = (int)$st->fetchColumn();
        $positions = [$positionId];
        if ($positionId > 0 && !empty($config['analysis']['equivalent_via_audio_position'])) {
            $positions = StaffReportProfiles::equivalentPositions($positionId);
        }
        $posParts = [];
        foreach ($positions as $pid) {
            $pid = (int)$pid;
            if ($pid > 0) {
                $posParts[] = 'ID_POSITION="' . $pid . '" OR ID_POSITION LIKE "%#' . $pid . '#%"';
            }
        }
        $posSql = $posParts !== []
            ? '(ID_POSITION="0" OR ID_POSITION="" OR ' . implode(' OR ', $posParts) . ')'
            : '(ID_POSITION="0" OR ID_POSITION="")';

        $types = [];
        // Rating criteria come from RATING_ENABLED scripts (legacy types table removed).
        if (Database::tableExists('base_records_scripts')
            && Database::columnExists('base_records_scripts', 'RATING_ENABLED')
        ) {
            $rateCol = Database::columnExists('base_records_scripts', 'SCORE_RATE')
                ? '(SELECT COALESCE(SUM(p.SCORE_RATE),0) FROM base_records_scripts p WHERE p.PID = s.ID)'
                : '0';
            $hiddenCol = Database::columnExists('base_records_scripts', 'IS_HIDDEN')
                ? 's.IS_HIDDEN' : '0';
            $sql = "SELECT s.ID, s.NAME, {$rateCol} AS SCORE_VALUE, {$hiddenCol} AS IS_HIDDEN
                    FROM base_records_scripts s
                    WHERE s.PID = 0 AND s.RATING_ENABLED = 1 AND s.DISABLED < 1 AND {$posSql}
                    ORDER BY s.NUM ASC, s.ID ASC";
            try {
                $q = Database::pdo()->query($sql);
                if ($q) {
                    while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
                        $sv = (float)($row['SCORE_VALUE'] ?? 0);
                        if ((int)($row['IS_HIDDEN'] ?? 0) > 0) {
                            continue;
                        }
                        $tid = (int)$row['ID'];
                        $types[$tid] = [
                            'ID' => $tid,
                            'NAME' => (string)($row['NAME'] ?? ''),
                            'SCORE_VALUE' => $sv,
                            'is_scoring' => $sv > 1,
                        ];
                    }
                }
            } catch (Throwable $e) {
                $types = [];
            }
        }

        $filter = StaffReportConfig::intList($config['criteria_type_ids'] ?? []);
        if ($filter !== []) {
            $allow = array_fill_keys($filter, true);
            $types = array_filter($types, static function (array $t) use ($allow): bool {
                return isset($allow[(int)$t['ID']]);
            });
        }
        return $types;
    }

    public static function groupKey(string $name): string
    {
        $name = trim($name);
        if (preg_match('/^\s*([МM]:\s*\d+)/u', $name, $m)) {
            return preg_replace('/\s+/u', ' ', trim($m[1])) ?: $name;
        }
        return $name;
    }

    /**
     * @param list<array{client_id:int,win_start:int,win_end:int,procedure_id?:int}> $windows
     * @param array<int, array{ID:int,NAME:string,SCORE_VALUE:float,is_scoring:bool}> $criteriaTypes
     * @param array<string,mixed> $config
     * @return array{
     *   criteria:list<array<string,mixed>>,
     *   has_red:bool,
     *   has_scoring_groups:bool,
     *   scoring_group_count:int
     * }
     */
    public static function analyze(
        int $employeeId,
        array $windows,
        array $criteriaTypes,
        int $uniqueClients,
        array $config,
        string $mode = StaffReportConfig::MODE_PROCEDURE_SLOT,
        int $dayStart = 0,
        int $dayEnd = 0
    ): array {
        $empty = [
            'criteria' => [],
            'has_red' => false,
            'has_scoring_groups' => false,
            'scoring_group_count' => 0,
        ];
        if ($employeeId < 1 || $criteriaTypes === []) {
            return $empty;
        }

        if ($mode === StaffReportConfig::MODE_SCORE_TIME) {
            return self::analyzeScoreTime($employeeId, $criteriaTypes, $uniqueClients, $config, $dayStart, $dayEnd);
        }

        $hits = [];
        $groups = [];
        foreach ($criteriaTypes as $typeId => $typeData) {
            $typeId = (int)$typeId;
            $name = (string)($typeData['NAME'] ?? ('#' . $typeId));
            $isScoring = !empty($typeData['is_scoring']);
            $gk = self::groupKey($name);
            $hits[$typeId] = [
                'name' => $name,
                'group_key' => $gk,
                'is_scoring' => $isScoring,
                'client_ids' => [],
            ];
            if (!isset($groups[$gk])) {
                $groups[$gk] = [
                    'group_key' => $gk,
                    'type_ids' => [],
                    'client_ids' => [],
                    'client_ids_scoring' => [],
                    'has_scoring' => false,
                ];
            }
            $groups[$gk]['type_ids'][] = $typeId;
            if ($isScoring) {
                $groups[$gk]['has_scoring'] = true;
            }
        }

        foreach ($windows as $win) {
            $clientId = (int)($win['client_id'] ?? 0);
            if ($clientId < 1) {
                continue;
            }
            $typesIn = self::typesInWindow($employeeId, (int)$win['win_start'], (int)$win['win_end']);
            foreach ($hits as $typeId => $meta) {
                if (isset($typesIn[$typeId])) {
                    $hits[$typeId]['client_ids'][$clientId] = true;
                    $gk = $meta['group_key'];
                    $groups[$gk]['client_ids'][$clientId] = true;
                    if (!empty($meta['is_scoring'])) {
                        $groups[$gk]['client_ids_scoring'][$clientId] = true;
                    }
                }
            }
        }

        return self::buildResults($hits, $groups, $uniqueClients, $config);
    }

    /**
     * Score-time mode: criteria hits over the whole day window; denominator = unique clients
     * (or 1-day presence). Traffic light still uses scoring groups.
     *
     * @param array<int, array{ID:int,NAME:string,SCORE_VALUE:float,is_scoring:bool}> $criteriaTypes
     * @param array<string,mixed> $config
     * @return array{criteria:list,has_red:bool,has_scoring_groups:bool,scoring_group_count:int}
     */
    private static function analyzeScoreTime(
        int $employeeId,
        array $criteriaTypes,
        int $uniqueClients,
        array $config,
        int $dayStart,
        int $dayEnd
    ): array {
        $windows = [];
        if ($dayStart > 0 && $dayEnd > $dayStart) {
            // Synthetic single window for the day; clients discovered from hits.
            $clientIds = self::clientIdsWithRecords($employeeId, $dayStart, $dayEnd);
            if ($clientIds === []) {
                // Fall back: treat as one anonymous bucket via uniqueClients from procedures.
                $windows[] = ['client_id' => 0, 'win_start' => $dayStart, 'win_end' => $dayEnd];
            } else {
                foreach ($clientIds as $cid) {
                    $windows[] = ['client_id' => $cid, 'win_start' => $dayStart, 'win_end' => $dayEnd];
                }
            }
        }
        // If no procedure clients, use playtime-based pseudo denominator of max(1, uniqueClients).
        $den = max(1, $uniqueClients);
        $hits = [];
        $groups = [];
        foreach ($criteriaTypes as $typeId => $typeData) {
            $typeId = (int)$typeId;
            $name = (string)($typeData['NAME'] ?? ('#' . $typeId));
            $isScoring = !empty($typeData['is_scoring']);
            $gk = self::groupKey($name);
            $hits[$typeId] = [
                'name' => $name,
                'group_key' => $gk,
                'is_scoring' => $isScoring,
                'client_ids' => [],
            ];
            if (!isset($groups[$gk])) {
                $groups[$gk] = [
                    'group_key' => $gk,
                    'type_ids' => [],
                    'client_ids' => [],
                    'client_ids_scoring' => [],
                    'has_scoring' => false,
                ];
            }
            $groups[$gk]['type_ids'][] = $typeId;
            if ($isScoring) {
                $groups[$gk]['has_scoring'] = true;
            }
        }

        if ($dayStart > 0 && $dayEnd > $dayStart) {
            $typesIn = self::typesInWindow($employeeId, $dayStart, $dayEnd);
            // Attribute all day hits to a synthetic client set of size $den via bitflags:
            // if type found, mark clients 1..foundCount capped — simpler: mark one pseudo client per hit group.
            $pseudo = 1;
            foreach ($hits as $typeId => $meta) {
                if (isset($typesIn[$typeId])) {
                    $hits[$typeId]['client_ids'][$pseudo] = true;
                    $gk = $meta['group_key'];
                    $groups[$gk]['client_ids'][$pseudo] = true;
                    if (!empty($meta['is_scoring'])) {
                        $groups[$gk]['client_ids_scoring'][$pseudo] = true;
                    }
                }
            }
            // Force denominator for score_time when no procedure clients.
            return self::buildResults($hits, $groups, $den, $config);
        }

        return self::buildResults($hits, $groups, $uniqueClients, $config);
    }

    /**
     * @return list<int>
     */
    private static function clientIdsWithRecords(int $employeeId, int $dayStart, int $dayEnd): array
    {
        // Records don't carry client ids; return empty → score_time uses uniqueClients denominator.
        return [];
    }

    /**
     * @param array<int, array{name:string,group_key:string,is_scoring:bool,client_ids:array}> $hits
     * @param array<string, array{group_key:string,type_ids:list,client_ids:array,client_ids_scoring:array,has_scoring:bool}> $groups
     * @param array<string,mixed> $config
     * @return array{criteria:list,has_red:bool,has_scoring_groups:bool,scoring_group_count:int}
     */
    private static function buildResults(array $hits, array $groups, int $uniqueClients, array $config): array
    {
        $denominator = max(0, $uniqueClients);
        $results = [];
        $hasRed = false;
        $scoringGroupCount = 0;

        foreach ($groups as $gk => $g) {
            $members = [];
            foreach ($g['type_ids'] as $tid) {
                $h = $hits[$tid] ?? null;
                if ($h === null) {
                    continue;
                }
                $num = count($h['client_ids']);
                $members[] = [
                    'type_id' => (int)$tid,
                    'name' => (string)$h['name'],
                    'numerator' => $num,
                    'denominator' => $denominator,
                    'is_scoring' => !empty($h['is_scoring']),
                ];
            }
            $isScoringGroup = !empty($g['has_scoring']);
            if ($isScoringGroup) {
                $scoringGroupCount++;
                $numForColor = count($g['client_ids_scoring']);
            } else {
                $numForColor = count($g['client_ids']);
            }
            $percent = $denominator > 0 ? ($numForColor * 100.0 / $denominator) : 0.0;
            $colorInfo = StaffReportConfig::colorForPercent($percent, $config);
            if ($isScoringGroup && $colorInfo['color'] === 'red') {
                $hasRed = true;
            }
            $results[] = [
                'group_key' => $gk,
                'members' => $members,
                'numerator' => $numForColor,
                'denominator' => $denominator,
                'percent' => round($percent, 1),
                'color' => $colorInfo['color'],
                'emoji' => $colorInfo['emoji'],
                'is_scoring' => $isScoringGroup,
            ];
        }

        return [
            'criteria' => $results,
            'has_red' => $hasRed,
            'has_scoring_groups' => $scoringGroupCount > 0,
            'scoring_group_count' => $scoringGroupCount,
        ];
    }

    /**
     * Script (or legacy type) ids present in the window.
     * Prefers rated collections when SCORE_RATE schema is available.
     *
     * @return array<int,true>
     */
    public static function typesInWindow(int $employeeId, int $winStart, int $winEnd): array
    {
        $found = [];
        if ($employeeId < 1 || $winEnd <= $winStart) {
            return $found;
        }
        if (Database::tableExists('base_records_collections')
            && Database::columnExists('base_records_collections', 'RATED')
        ) {
            $sql = 'SELECT DISTINCT ID_SCRIPT AS script_id
                    FROM base_records_collections
                    WHERE ID_ADMIN = ? AND RATED = 1
                      AND DT_START < ?
                      AND (DT_END = 0 OR DT_END > ?)';
            $st = Database::pdo()->prepare($sql);
            $st->execute([$employeeId, $winEnd, $winStart]);
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $sid = (int)($row['script_id'] ?? 0);
                if ($sid > 0) {
                    $found[$sid] = true;
                }
            }
            if ($found !== []) {
                return $found;
            }
        }
        return $found;
    }
}
