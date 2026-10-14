<?php
declare(strict_types=1);

/**
 * Discover catalog entities for a position + propose a default summary profile.
 * Port of oper rs_discover_catalog / rs_propose_profile.
 */
final class SummaryCatalog
{
    /**
     * @return array{
     *   position_id:int,position_name:string,
     *   types:list<array<string,mixed>>,scripts:list<array<string,mixed>>,dicts:list<array<string,mixed>>,
     *   employees_count:int,volume:array{records:int,playtime_sec:int,instances:int,collections:int},
     *   year:int,month:int
     * }
     */
    public static function discover(int $positionId, int $year = 0, int $month = 0): array
    {
        $positionId = max(0, $positionId);
        if ($year <= 0) {
            $year = (int)date('Y');
        }
        if ($month <= 0) {
            $month = (int)date('n');
        }
        $monthStart = (int)mktime(0, 0, 0, $month, 1, $year);
        $monthEnd = (int)mktime(23, 59, 59, $month, (int)date('t', $monthStart), $year);
        $pdo = Database::pdo();
        $posCond = self::positionSqlCondition('ID_POSITION', $positionId);

        SummaryProgress::note('Loading criteria (rating scripts)…');
        $types = [];
        if (Database::tableExists('base_records_scripts')
            && Database::columnExists('base_records_scripts', 'RATING_ENABLED')
        ) {
            $rateCol = Database::columnExists('base_records_scripts', 'SCORE_RATE')
                ? '(SELECT COALESCE(SUM(p.SCORE_RATE),0) FROM base_records_scripts p WHERE p.PID = s.ID)'
                : '0';
            $hiddenCol = Database::columnExists('base_records_scripts', 'IS_HIDDEN')
                ? 's.IS_HIDDEN' : '0';
            $posCondS = '(s.ID_POSITION="0" OR s.ID_POSITION="" OR s.ID_POSITION="' . (int)$positionId
                . '" OR s.ID_POSITION LIKE "%#' . (int)$positionId . '#%")';
            $sql = "SELECT s.ID, s.NAME, {$rateCol} AS SCORE_VALUE, {$hiddenCol} AS IS_HIDDEN
                    FROM base_records_scripts s
                    WHERE s.PID = 0 AND s.RATING_ENABLED = 1 AND s.DISABLED < 1 AND {$posCondS}
                    ORDER BY s.NUM ASC, s.ID ASC";
            try {
                $st = $pdo->query($sql);
                if ($st) {
                    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                        $sv = (float)$row['SCORE_VALUE'];
                        if ((int)$row['IS_HIDDEN'] > 0) {
                            continue;
                        }
                        $types[] = [
                            'ID' => (int)$row['ID'],
                            'NAME' => (string)$row['NAME'],
                            'DESCR' => '',
                            'SCORE_VALUE' => $sv,
                            'is_scoring' => ($sv > 1),
                        ];
                    }
                }
            } catch (Throwable $e) {
                // leave empty
            }
        }
        SummaryProgress::mark('Criteria: ' . count($types));

        SummaryProgress::note('Loading scripts (base_records_scripts)…');
        $scripts = [];
        if (Database::tableExists('base_records_scripts')) {
            $sql = "SELECT ID, NAME, PID, FINISH, ID_POSITION FROM base_records_scripts
                    WHERE DISABLED='0' AND PID < 1 AND {$posCond} ORDER BY NAME ASC";
            try {
                $st = $pdo->query($sql);
                if ($st) {
                    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                        $scripts[] = [
                            'ID' => (int)$row['ID'],
                            'NAME' => (string)$row['NAME'],
                            'PID' => (int)$row['PID'],
                            'FINISH' => (int)$row['FINISH'],
                        ];
                    }
                }
            } catch (Throwable $e) {
                // leave empty
            }
        }
        SummaryProgress::mark('Scripts: ' . count($scripts));

        SummaryProgress::note('Loading TRACKING_CODE dictionaries…');
        $dicts = [];
        if (Database::tableExists('base_records_dictonarys')) {
            $sql = "SELECT ID, NAME, TRACKING_CODE, ID_POSITION FROM base_records_dictonarys
                    WHERE DISABLED='0' AND TRACKING_CODE IS NOT NULL AND TRIM(TRACKING_CODE) != ''
                      AND {$posCond} ORDER BY NAME ASC";
            try {
                $st = $pdo->query($sql);
                if ($st) {
                    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                        $dicts[] = [
                            'ID' => (int)$row['ID'],
                            'NAME' => (string)$row['NAME'],
                            'TRACKING_CODE' => trim((string)$row['TRACKING_CODE']),
                        ];
                    }
                }
            } catch (Throwable $e) {
                // leave empty
            }
        }
        SummaryProgress::mark('Tracking dicts: ' . count($dicts));

        SummaryProgress::note('Employees for position…');
        $empIds = [];
        if ($positionId > 0 && Database::tableExists('base_users')) {
            $st = $pdo->prepare(
                "SELECT id FROM base_users
                 WHERE ID_STATUS = ? AND enabled = '1'
                   AND (IS_HIDDEN IS NULL OR IS_HIDDEN < 1)"
            );
            $st->execute([$positionId]);
            while ($u = $st->fetch(PDO::FETCH_ASSOC)) {
                $empIds[] = (int)$u['id'];
            }
        }
        $empList = $empIds !== [] ? implode(',', array_map('intval', $empIds)) : '0';
        SummaryProgress::mark('Employees: ' . count($empIds));

        $volume = ['records' => 0, 'playtime_sec' => 0, 'instances' => 0, 'collections' => 0];
        SummaryProgress::note('Record volume for month…');
        if (Database::tableExists('base_records')) {
            $st = $pdo->query(
                "SELECT COUNT(ID) AS c, SUM(PLAYTIME) AS s FROM base_records
                 WHERE ID_ADMIN IN ({$empList}) AND (DISABLED IS NULL OR DISABLED < 1)
                   AND DT_RECORDED >= {$monthStart} AND DT_RECORDED <= {$monthEnd}"
            );
            if ($st && ($rowV = $st->fetch(PDO::FETCH_ASSOC))) {
                $volume['records'] = (int)$rowV['c'];
                $volume['playtime_sec'] = (int)$rowV['s'];
            }
        }
        SummaryProgress::mark('Records: ' . $volume['records']);

        SummaryProgress::note('Counting instances…');
        if (Database::tableExists('base_records_instances')) {
            $st = $pdo->query(
                "SELECT COUNT(ID) AS c FROM base_records_instances
                 WHERE ID_ADMIN IN ({$empList})
                   AND DT_START >= {$monthStart} AND DT_START <= {$monthEnd}"
            );
            if ($st && ($rowI = $st->fetch(PDO::FETCH_ASSOC))) {
                $volume['instances'] = (int)$rowI['c'];
            }
        }
        SummaryProgress::mark('Instances: ' . $volume['instances']);

        SummaryProgress::note('Counting collections…');
        if (Database::tableExists('base_records_collections')) {
            $st = $pdo->query(
                "SELECT COUNT(ID) AS c FROM base_records_collections
                 WHERE ID_ADMIN IN ({$empList})
                   AND DT_START >= {$monthStart} AND DT_START <= {$monthEnd}"
            );
            if ($st && ($rowC = $st->fetch(PDO::FETCH_ASSOC))) {
                $volume['collections'] = (int)$rowC['c'];
            }
        }
        SummaryProgress::mark('Collections: ' . $volume['collections']);

        return [
            'position_id' => $positionId,
            'position_name' => self::positionName($positionId),
            'types' => $types,
            'scripts' => $scripts,
            'dicts' => $dicts,
            'employees_count' => count($empIds),
            'volume' => $volume,
            'year' => $year,
            'month' => $month,
        ];
    }

    /**
     * @param array<string,mixed> $catalog from discover()
     * @return array{NAME:string,config:array<string,mixed>,labels:array<string,string>}
     */
    public static function propose(array $catalog): array
    {
        $config = SummariesRepository::defaultConfig();
        $labels = SummariesRepository::defaultLabels();
        $posName = isset($catalog['position_name']) && (string)$catalog['position_name'] !== ''
            ? (string)$catalog['position_name']
            : 'position';

        $scoring = [];
        foreach ($catalog['types'] ?? [] as $t) {
            if (!empty($t['is_scoring'])) {
                $scoring[] = (int)$t['ID'];
            }
        }
        $config['criteria_type_ids'] = array_slice($scoring, 0, 12);

        $scriptIds = [];
        foreach ($catalog['scripts'] ?? [] as $s) {
            $scriptIds[] = (int)$s['ID'];
        }
        $config['script_ids'] = array_slice($scriptIds, 0, 8);

        $dictIds = [];
        foreach ($catalog['dicts'] ?? [] as $d) {
            $dictIds[] = (int)$d['ID'];
        }
        $config['tracking_dict_ids'] = $dictIds;

        $config['detail_criteria_ids'] = array_slice($config['criteria_type_ids'], 0, 3);
        $config['detail_tracking_dict_ids'] = array_slice($dictIds, 0, 2);
        $config['detail_scripts_mode'] = 'sum';
        $config['detail_show_score'] = 1;
        $config['detail_show_pass'] = 1;
        $config['detail_show_keys'] = 1;
        $config['detail_show_script_score'] = 1;
        $config['tracking_cooldown_sec'] = 30;

        $cards = ['volume', 'score'];
        if (!empty($config['criteria_type_ids'])) {
            $cards[] = 'criteria';
        }
        if (!empty($config['script_ids'])) {
            $cards[] = 'scripts';
            $cards[] = 'script_score';
        }
        if (!empty($config['tracking_dict_ids'])) {
            $cards[] = 'tracking';
        }
        $cards[] = 'leaders';
        $config['cards'] = $cards;

        $labels['banner'] = 'NBadge: employee conversations (' . $posName . ') → standards, scripts and service control.';
        if (!empty($catalog['types'])) {
            $names = [];
            foreach ($catalog['types'] as $t) {
                if (!empty($t['is_scoring'])) {
                    $names[] = (string)$t['NAME'];
                }
                if (count($names) >= 2) {
                    break;
                }
            }
            if ($names !== []) {
                $labels['growth_criteria'] = 'Control points: ' . implode(', ', $names);
            }
        }
        if (!empty($catalog['scripts'][0]['NAME'])) {
            $labels['growth_scripts'] = 'Script «' . (string)$catalog['scripts'][0]['NAME'] . '»';
        }

        return [
            'NAME' => 'Report: ' . $posName,
            'config' => SummariesRepository::normalizeConfig($config),
            'labels' => SummariesRepository::normalizeLabels($labels),
        ];
    }

    private static function positionSqlCondition(string $column, int $positionId): string
    {
        $column = SummaryExternal::sqlIdent($column);
        if ($column === '') {
            $column = 'ID_POSITION';
        }
        $pid = (int)$positionId;
        return '(' . $column . '="0" OR ' . $column . '="" OR ' . $column . '="' . $pid . '" OR '
            . $column . ' LIKE "%#' . $pid . '#%")';
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
