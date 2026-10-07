<?php
declare(strict_types=1);

/**
 * Month/range summary aggregation — oper rs_collect_month_summary parity.
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
        $year = (int)$period['year'];
        $month = (int)$period['month'];
        $positionId = (int)($profile['ID_POSITION'] ?? 0);
        $config = SummariesRepository::normalizeConfig($profile['config'] ?? []);
        $labels = SummariesRepository::normalizeLabels($profile['labels'] ?? []);
        $monthStart = (int)$period['start'];
        $monthEnd = (int)$period['end'];
        $pdo = Database::pdo();

        SummaryProgress::note('Employees for position #' . $positionId . '…');
        SummaryProgress::note('Period: ' . $period['label']);
        $employees = self::employeesForPosition($positionId);
        $empIds = array_keys($employees);
        $empList = $empIds !== [] ? implode(',', array_map('intval', $empIds)) : '0';
        SummaryProgress::mark('Employees in report: ' . count($employees));

        $empStats = [];
        foreach ($employees as $empId => $_e) {
            $empStats[$empId] = [
                'days' => [],
                'day_stats' => [],
                'playtime' => 0,
                'score_w' => 0.0,
                'score_sec' => 0,
                'chars' => 0,
                'instances' => 0,
                'script_starts' => [],
                'script_finishes' => [],
                'script_score' => [],
                'type_hit_sec' => [],
                'tracking' => [],
                'days_pass_score' => 0,
                'days_fail_score' => 0,
                'important' => 0,
            ];
        }

        $totalPlay = 0;
        $totalChars = 0;
        $totalInstances = 0;
        $totalRecords = 0;

        $criteriaIds = array_map('intval', $config['criteria_type_ids'] ?? []);
        $detailCriteriaIds = array_map('intval', $config['detail_criteria_ids'] ?? []);
        $collectTypeIds = array_values(array_unique(array_merge($criteriaIds, $detailCriteriaIds)));

        SummaryProgress::note('Reading records (SCORE, KEY_TYPES, transcription length)…');
        $dayScoreAcc = [];
        if ($empIds !== [] && Database::tableExists('base_records')) {
            $sql = "SELECT ID, ID_ADMIN, PLAYTIME, SCORE, KEY_TYPES,
                           CHAR_LENGTH(IFNULL(TRANSCRIPTION,'')) AS chars, DT_RECORDED
                    FROM base_records
                    WHERE ID_ADMIN IN ({$empList})
                      AND (DISABLED IS NULL OR DISABLED < 1)
                      AND DT_RECORDED >= ? AND DT_RECORDED <= ?";
            $st = $pdo->prepare($sql);
            $st->execute([$monthStart, $monthEnd]);
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $empId = (int)$r['ID_ADMIN'];
                if (!isset($empStats[$empId])) {
                    continue;
                }
                $play = (int)$r['PLAYTIME'];
                $score = (float)$r['SCORE'];
                if ($score < 0) {
                    $score = 0.0;
                }
                $dayKey = date('Y-m-d', (int)$r['DT_RECORDED']);
                $empStats[$empId]['days'][$dayKey] = true;
                $empStats[$empId]['playtime'] += $play;
                $empStats[$empId]['score_w'] += $score * $play;
                $empStats[$empId]['score_sec'] += $play;
                self::ensureDayStats($empStats, $empId, $dayKey);
                $empStats[$empId]['day_stats'][$dayKey]['playtime'] += $play;
                $empStats[$empId]['day_stats'][$dayKey]['score_w'] += $score * $play;
                $empStats[$empId]['day_stats'][$dayKey]['score_sec'] += $play;
                $chars = (int)$r['chars'];
                $empStats[$empId]['chars'] += $chars;
                $totalPlay += $play;
                $totalChars += $chars;
                $totalRecords++;
                $dk = $empId . '|' . $dayKey;
                if (!isset($dayScoreAcc[$dk])) {
                    $dayScoreAcc[$dk] = ['w' => 0.0, 'sec' => 0];
                }
                $dayScoreAcc[$dk]['w'] += $score * $play;
                $dayScoreAcc[$dk]['sec'] += $play;

                if ($play > 0 && $collectTypeIds !== [] && !empty($r['KEY_TYPES'])) {
                    $foundTypes = self::parseKeyTypes((string)$r['KEY_TYPES']);
                    foreach ($collectTypeIds as $tid) {
                        if (!empty($foundTypes[$tid])) {
                            if (!isset($empStats[$empId]['type_hit_sec'][$tid])) {
                                $empStats[$empId]['type_hit_sec'][$tid] = 0;
                            }
                            $empStats[$empId]['type_hit_sec'][$tid] += $play;
                            if (!isset($empStats[$empId]['day_stats'][$dayKey]['type_hit_sec'][$tid])) {
                                $empStats[$empId]['day_stats'][$dayKey]['type_hit_sec'][$tid] = 0;
                            }
                            $empStats[$empId]['day_stats'][$dayKey]['type_hit_sec'][$tid] += $play;
                        }
                    }
                }
            }
        }
        SummaryProgress::mark('Records processed: ' . $totalRecords);
        $totalAudioHours = round($totalPlay / 3600, 1);
        $totalWords = ($totalChars > 0) ? (int)round($totalChars / 5.5) : 0;

        SummaryProgress::note('Records needing review…');
        $importantTotal = 0;
        if ($empIds !== []
            && Database::tableExists('base_records_reviews')
            && Database::tableExists('base_records')
        ) {
            $st = $pdo->prepare(
                "SELECT br.ID_ADMIN, br.DT_RECORDED
                 FROM base_records_reviews brr
                 INNER JOIN base_records br ON br.ID = brr.ID_RECORD
                 WHERE brr.DT_REVIEW < 1
                   AND br.ID_ADMIN IN ({$empList})
                   AND br.DT_RECORDED >= ? AND br.DT_RECORDED <= ?
                   AND (br.DISABLED IS NULL OR br.DISABLED < 1)"
            );
            $st->execute([$monthStart, $monthEnd]);
            while ($ir = $st->fetch(PDO::FETCH_ASSOC)) {
                $empId = (int)$ir['ID_ADMIN'];
                if (!isset($empStats[$empId])) {
                    continue;
                }
                $dayKey = date('Y-m-d', (int)$ir['DT_RECORDED']);
                $empStats[$empId]['important']++;
                self::ensureDayStats($empStats, $empId, $dayKey);
                $empStats[$empId]['day_stats'][$dayKey]['important']++;
                $importantTotal++;
            }
        }
        SummaryProgress::mark('Need review: ' . $importantTotal);

        $threshold = (float)($config['score_pass_threshold'] ?? 1.0);
        foreach ($dayScoreAcc as $dk => $acc) {
            $parts = explode('|', $dk, 2);
            $empId = (int)$parts[0];
            if ($acc['sec'] <= 0 || !isset($empStats[$empId])) {
                continue;
            }
            $avg = $acc['w'] / $acc['sec'];
            if ($avg >= $threshold) {
                $empStats[$empId]['days_pass_score']++;
            } else {
                $empStats[$empId]['days_fail_score']++;
            }
        }

        // Tracking instances + cooldown
        $trackingIds = array_map('intval', $config['tracking_dict_ids'] ?? []);
        $detailTrackingIds = array_map('intval', $config['detail_tracking_dict_ids'] ?? []);
        $collectDictIds = array_values(array_unique(array_merge($trackingIds, $detailTrackingIds)));
        $keyToDict = [];
        $dictMeta = [];
        $detailTrackingMeta = [];
        if ($collectDictIds !== []
            && Database::tableExists('base_records_dictonarys')
            && Database::tableExists('base_records_keys')
        ) {
            SummaryProgress::note('Tracking dictionaries and keys…');
            $dList = implode(',', array_map('intval', $collectDictIds));
            $st = $pdo->query("SELECT ID, NAME, TRACKING_CODE FROM base_records_dictonarys WHERE ID IN ({$dList})");
            if ($st) {
                while ($d = $st->fetch(PDO::FETCH_ASSOC)) {
                    $did = (int)$d['ID'];
                    $meta = [
                        'NAME' => (string)$d['NAME'],
                        'TRACKING_CODE' => trim((string)$d['TRACKING_CODE']),
                        'count' => 0,
                    ];
                    if (in_array($did, $trackingIds, true)) {
                        $dictMeta[$did] = $meta;
                    }
                    if (in_array($did, $detailTrackingIds, true)) {
                        $detailTrackingMeta[$did] = $meta;
                    }
                }
            }
            $st = $pdo->query(
                "SELECT ID, ID_DICTONARY FROM base_records_keys WHERE DISABLED='0' AND ID_DICTONARY IN ({$dList})"
            );
            if ($st) {
                while ($k = $st->fetch(PDO::FETCH_ASSOC)) {
                    $keyToDict[(int)$k['ID']] = (int)$k['ID_DICTONARY'];
                }
            }
            SummaryProgress::mark('Tracking dict keys: ' . count($keyToDict));
        }

        SummaryProgress::note('Walking instances (DT_START)…');
        $trackingCooldownSec = isset($config['tracking_cooldown_sec']) ? max(0, (int)$config['tracking_cooldown_sec']) : 0;
        $trackingLastHit = [];
        if ($empIds !== [] && Database::tableExists('base_records_instances')) {
            $st = $pdo->prepare(
                "SELECT ID_ADMIN, ID_KEY, DT_START FROM base_records_instances
                 WHERE ID_ADMIN IN ({$empList})
                   AND DT_START >= ? AND DT_START <= ?
                 ORDER BY DT_START ASC, ID ASC"
            );
            $st->execute([$monthStart, $monthEnd]);
            while ($i = $st->fetch(PDO::FETCH_ASSOC)) {
                $empId = (int)$i['ID_ADMIN'];
                $keyId = (int)$i['ID_KEY'];
                $dt = (int)$i['DT_START'];
                $totalInstances++;
                if (isset($empStats[$empId])) {
                    $empStats[$empId]['instances']++;
                }
                $dayKey = date('Y-m-d', $dt);
                if (isset($empStats[$empId])) {
                    self::ensureDayStats($empStats, $empId, $dayKey);
                    $empStats[$empId]['day_stats'][$dayKey]['instances']++;
                }
                if (isset($keyToDict[$keyId], $empStats[$empId])) {
                    $did = $keyToDict[$keyId];
                    $lk = $empId . '|' . $did;
                    if ($trackingCooldownSec > 0
                        && isset($trackingLastHit[$lk])
                        && ($dt - $trackingLastHit[$lk]) < $trackingCooldownSec
                    ) {
                        continue;
                    }
                    $trackingLastHit[$lk] = $dt;
                    if (!isset($empStats[$empId]['tracking'][$did])) {
                        $empStats[$empId]['tracking'][$did] = 0;
                    }
                    $empStats[$empId]['tracking'][$did]++;
                    if (!isset($empStats[$empId]['day_stats'][$dayKey]['tracking'][$did])) {
                        $empStats[$empId]['day_stats'][$dayKey]['tracking'][$did] = 0;
                    }
                    $empStats[$empId]['day_stats'][$dayKey]['tracking'][$did]++;
                    if (isset($dictMeta[$did])) {
                        $dictMeta[$did]['count']++;
                    }
                    if (isset($detailTrackingMeta[$did])) {
                        $detailTrackingMeta[$did]['count']++;
                    }
                }
            }
        }
        SummaryProgress::mark(
            'Key instances: ' . $totalInstances
            . ($trackingCooldownSec > 0 ? ' (tracking cooldown ' . $trackingCooldownSec . ' s)' : '')
        );

        // Scripts: collections + FINISH parts (CalendarAggregates pattern)
        SummaryProgress::note('Scripts: FINISH map and collections…');
        $scriptIds = array_map('intval', $config['script_ids'] ?? []);
        $scriptMeta = [];
        $scriptsFinish = [];
        $scriptsFinishParts = [];
        if (Database::tableExists('base_records_scripts')) {
            $st = $pdo->query(
                'SELECT ID, NAME, PID, FINISH FROM base_records_scripts WHERE DISABLED < 1'
            );
            if ($st) {
                while ($scData = $st->fetch(PDO::FETCH_ASSOC)) {
                    $scId = (int)$scData['ID'];
                    $pid = (int)$scData['PID'];
                    if ($pid < 1) {
                        if (!isset($scriptsFinish[$scId])) {
                            $scriptsFinish[$scId] = 0;
                        }
                        if (in_array($scId, $scriptIds, true)) {
                            $scriptMeta[$scId] = [
                                'NAME' => (string)$scData['NAME'],
                                'starts' => 0,
                                'finishes' => 0,
                                'score' => 0.0,
                            ];
                        }
                    } else {
                        if (!isset($scriptsFinish[$pid])) {
                            $scriptsFinish[$pid] = 0;
                        }
                        if (!empty($scData['FINISH'])) {
                            $scriptsFinish[$pid] = $scId;
                            $scriptsFinishParts[$scId] = 1;
                        }
                    }
                }
            }
        }

        $finishCollectionIds = [];
        $monthCollections = [];
        $totalScriptScore = 0.0;
        if ($scriptIds !== [] && $empIds !== [] && Database::tableExists('base_records_collections')) {
            $sList = implode(',', array_map('intval', $scriptIds));
            $st = $pdo->prepare(
                "SELECT ID, ID_ADMIN, ID_SCRIPT, SCORE, DT_START FROM base_records_collections
                 WHERE ID_ADMIN IN ({$empList})
                   AND ID_SCRIPT IN ({$sList})
                   AND DT_START >= ? AND DT_START <= ?"
            );
            $st->execute([$monthStart, $monthEnd]);
            while ($c = $st->fetch(PDO::FETCH_ASSOC)) {
                $monthCollections[(int)$c['ID']] = $c;
            }
            SummaryProgress::mark('Script collections: ' . count($monthCollections));

            if ($scriptsFinishParts !== [] && $monthCollections !== []
                && Database::tableExists('base_records_collections_datas')
            ) {
                $partList = implode(',', array_map('intval', array_keys($scriptsFinishParts)));
                foreach (array_chunk(array_keys($monthCollections), 500) as $chunk) {
                    $colIds = implode(',', array_map('intval', $chunk));
                    $qf = $pdo->query(
                        "SELECT ID_COLLECTION, ID_PART FROM base_records_collections_datas
                         WHERE ID_COLLECTION IN ({$colIds}) AND ID_PART IN ({$partList})"
                    );
                    if ($qf) {
                        while ($cd = $qf->fetch(PDO::FETCH_ASSOC)) {
                            $finishCollectionIds[(int)$cd['ID_COLLECTION']][(int)$cd['ID_PART']] = true;
                        }
                    }
                }
                SummaryProgress::mark('Collections with finish parts: ' . count($finishCollectionIds));
            }

            foreach ($monthCollections as $colId => $c) {
                $empId = (int)$c['ID_ADMIN'];
                $sid = (int)$c['ID_SCRIPT'];
                $colScore = isset($c['SCORE']) ? (float)$c['SCORE'] : 0.0;
                if (!isset($empStats[$empId], $scriptMeta[$sid])) {
                    continue;
                }
                $scriptMeta[$sid]['starts']++;
                $scriptMeta[$sid]['score'] += $colScore;
                $totalScriptScore += $colScore;
                if (!isset($empStats[$empId]['script_starts'][$sid])) {
                    $empStats[$empId]['script_starts'][$sid] = 0;
                }
                $empStats[$empId]['script_starts'][$sid]++;
                if (!isset($empStats[$empId]['script_score'][$sid])) {
                    $empStats[$empId]['script_score'][$sid] = 0.0;
                }
                $empStats[$empId]['script_score'][$sid] += $colScore;
                $dayKey = date('Y-m-d', (int)$c['DT_START']);
                self::ensureDayStats($empStats, $empId, $dayKey);
                if (!isset($empStats[$empId]['day_stats'][$dayKey]['script_starts'][$sid])) {
                    $empStats[$empId]['day_stats'][$dayKey]['script_starts'][$sid] = 0;
                }
                $empStats[$empId]['day_stats'][$dayKey]['script_starts'][$sid]++;
                if (!isset($empStats[$empId]['day_stats'][$dayKey]['script_score'][$sid])) {
                    $empStats[$empId]['day_stats'][$dayKey]['script_score'][$sid] = 0.0;
                }
                $empStats[$empId]['day_stats'][$dayKey]['script_score'][$sid] += $colScore;
                $finishPart = isset($scriptsFinish[$sid]) ? (int)$scriptsFinish[$sid] : 0;
                if ($finishPart > 0 && !empty($finishCollectionIds[$colId][$finishPart])) {
                    $scriptMeta[$sid]['finishes']++;
                    if (!isset($empStats[$empId]['script_finishes'][$sid])) {
                        $empStats[$empId]['script_finishes'][$sid] = 0;
                    }
                    $empStats[$empId]['script_finishes'][$sid]++;
                    if (!isset($empStats[$empId]['day_stats'][$dayKey]['script_finishes'][$sid])) {
                        $empStats[$empId]['day_stats'][$dayKey]['script_finishes'][$sid] = 0;
                    }
                    $empStats[$empId]['day_stats'][$dayKey]['script_finishes'][$sid]++;
                }
            }
        }
        SummaryProgress::mark('Scripts counted');
        foreach ($scriptMeta as $sid => &$sm) {
            $sm['conversion'] = ($sm['starts'] > 0) ? round($sm['finishes'] * 100 / $sm['starts'], 1) : 0.0;
            $sm['score'] = round($sm['score'], 2);
        }
        unset($sm);
        $totalScriptScore = round($totalScriptScore, 2);

        // External sources (core PDO fetch)
        $external = [];
        $extByKey = [];
        $extByKeyDay = [];
        $extSources = is_array($config['external_sources'] ?? null) ? $config['external_sources'] : [];
        if ($extSources !== []) {
            SummaryProgress::note('External data: ' . count($extSources) . ' source(s)…');
            $fetchedList = SummaryExternal::fetchAll($extSources, $empIds, $monthStart, $monthEnd);
            foreach ($fetchedList as $fetched) {
                $external[] = $fetched;
                $extByKey[$fetched['key']] = isset($fetched['by_emp']) && is_array($fetched['by_emp'])
                    ? $fetched['by_emp'] : [];
                $extByKeyDay[$fetched['key']] = isset($fetched['by_emp_day']) && is_array($fetched['by_emp_day'])
                    ? $fetched['by_emp_day'] : [];
                if (!empty($fetched['error'])) {
                    SummaryProgress::note('⚠ ' . $fetched['title'] . ': ' . $fetched['error'], '#fbbf24');
                } else {
                    SummaryProgress::mark(
                        $fetched['title'] . ': ' . round((float)$fetched['total'], 2)
                        . ' (rows ' . (int)$fetched['rows_count'] . ')'
                    );
                }
            }
        }

        SummaryProgress::note('Building employee table…');

        $typeMeta = [];
        $detailTypeMeta = [];
        $typesForMeta = array_values(array_unique(array_merge($criteriaIds, $detailCriteriaIds)));
        if ($typesForMeta !== [] && Database::tableExists('base_records_types')) {
            $tList = implode(',', array_map('intval', $typesForMeta));
            $st = $pdo->query("SELECT ID, NAME, SCORE_VALUE FROM base_records_types WHERE ID IN ({$tList})");
            if ($st) {
                while ($t = $st->fetch(PDO::FETCH_ASSOC)) {
                    $tid = (int)$t['ID'];
                    $meta = [
                        'NAME' => (string)$t['NAME'],
                        'SCORE_VALUE' => (float)$t['SCORE_VALUE'],
                        'is_scoring' => ((float)$t['SCORE_VALUE'] > 1),
                        'hit_sec' => 0,
                    ];
                    if (in_array($tid, $criteriaIds, true)) {
                        $typeMeta[$tid] = $meta;
                    }
                    if (in_array($tid, $detailCriteriaIds, true)) {
                        $detailTypeMeta[$tid] = $meta;
                    }
                }
            }
        }
        $totalScoreSec = 0;
        foreach ($empStats as $stRow) {
            $totalScoreSec += $stRow['score_sec'];
            foreach ($stRow['type_hit_sec'] as $tid => $sec) {
                if (isset($typeMeta[$tid])) {
                    $typeMeta[$tid]['hit_sec'] += $sec;
                }
            }
        }
        $criteriaFails = [];
        foreach ($typeMeta as $tid => $tm) {
            if (empty($tm['is_scoring'])) {
                continue;
            }
            $pct = ($totalScoreSec > 0) ? round($tm['hit_sec'] * 100 / $totalScoreSec, 1) : 0.0;
            $typeMeta[$tid]['percent'] = $pct;
            $miss = 100 - $pct;
            if ($miss > 0) {
                $criteriaFails[$tm['NAME']] = $miss;
            }
        }
        arsort($criteriaFails);

        $globalScorePass = 0;
        $globalScoreFail = 0;
        $rows = [];
        $maxScoreAvg = 0.0;
        $bestName = '';
        $minDays = (int)($config['benchmark_min_days'] ?? 5);
        $calcMetrics = is_array($config['computed_metrics'] ?? null) ? $config['computed_metrics'] : [];

        foreach ($employees as $empId => $e) {
            $stRow = $empStats[$empId];
            $daysCount = count($stRow['days']);
            $avgScore = ($stRow['score_sec'] > 0) ? round($stRow['score_w'] / $stRow['score_sec'], 2) : 0.0;
            $globalScorePass += $stRow['days_pass_score'];
            $globalScoreFail += $stRow['days_fail_score'];
            if ($daysCount >= $minDays && $avgScore > $maxScoreAvg) {
                $maxScoreAvg = $avgScore;
                $bestName = (string)$e['initials'];
            }
            $scriptConv = 0.0;
            $scriptStarts = 0;
            $scriptFinishes = 0;
            $scriptScoreSum = 0.0;
            $scriptsDetail = [];
            foreach ($scriptIds as $sid) {
                $ss = isset($stRow['script_starts'][$sid]) ? (int)$stRow['script_starts'][$sid] : 0;
                $sf = isset($stRow['script_finishes'][$sid]) ? (int)$stRow['script_finishes'][$sid] : 0;
                $sc = isset($stRow['script_score'][$sid]) ? round((float)$stRow['script_score'][$sid], 2) : 0.0;
                $scriptStarts += $ss;
                $scriptFinishes += $sf;
                $scriptScoreSum += $sc;
                $scriptsDetail[$sid] = [
                    'starts' => $ss,
                    'finishes' => $sf,
                    'conversion' => ($ss > 0) ? round($sf * 100 / $ss, 1) : 0.0,
                    'score' => $sc,
                ];
            }
            if ($scriptStarts > 0) {
                $scriptConv = round($scriptFinishes * 100 / $scriptStarts, 1);
            }

            $criteriaPct = [];
            foreach ($detailCriteriaIds as $tid) {
                $hit = isset($stRow['type_hit_sec'][$tid]) ? (int)$stRow['type_hit_sec'][$tid] : 0;
                $criteriaPct[$tid] = ($stRow['score_sec'] > 0) ? round($hit * 100 / $stRow['score_sec'], 1) : 0.0;
            }
            $trackingDetail = [];
            foreach ($detailTrackingIds as $did) {
                $trackingDetail[$did] = isset($stRow['tracking'][$did]) ? (int)$stRow['tracking'][$did] : 0;
            }
            $externalDetail = [];
            foreach ($extByKey as $ek => $byEmp) {
                $externalDetail[$ek] = isset($byEmp[$empId]) ? (float)$byEmp[$empId] : 0.0;
            }

            $dayKeysMap = [];
            if (!empty($stRow['day_stats'])) {
                foreach ($stRow['day_stats'] as $dk => $_ds) {
                    $dayKeysMap[$dk] = true;
                }
            }
            foreach ($extByKeyDay as $ek => $byEmpDay) {
                if (empty($byEmpDay[$empId]) || !is_array($byEmpDay[$empId])) {
                    continue;
                }
                foreach ($byEmpDay[$empId] as $dk => $_v) {
                    $dayKeysMap[$dk] = true;
                }
            }
            ksort($dayKeysMap);

            $daysDetail = [];
            foreach ($dayKeysMap as $dayKey => $_x) {
                $ts = strtotime($dayKey . ' 12:00:00');
                if ($ts === false || $ts <= 0) {
                    continue;
                }
                $d = (int)date('j', $ts);
                $m = (int)date('n', $ts);
                $y = (int)date('Y', $ts);
                $ds = isset($stRow['day_stats'][$dayKey]) ? $stRow['day_stats'][$dayKey] : [
                    'playtime' => 0, 'score_w' => 0.0, 'score_sec' => 0, 'instances' => 0, 'important' => 0,
                    'type_hit_sec' => [], 'tracking' => [],
                    'script_starts' => [], 'script_finishes' => [], 'script_score' => [],
                ];
                $sec = (int)$ds['score_sec'];
                $avg = ($sec > 0) ? round($ds['score_w'] / $sec, 2) : 0.0;

                $dayScriptStarts = 0;
                $dayScriptFinishes = 0;
                $dayScriptScore = 0.0;
                $dayScriptsDetail = [];
                foreach ($scriptIds as $sid) {
                    $ss = isset($ds['script_starts'][$sid]) ? (int)$ds['script_starts'][$sid] : 0;
                    $sf = isset($ds['script_finishes'][$sid]) ? (int)$ds['script_finishes'][$sid] : 0;
                    $sc = isset($ds['script_score'][$sid]) ? round((float)$ds['script_score'][$sid], 2) : 0.0;
                    $dayScriptStarts += $ss;
                    $dayScriptFinishes += $sf;
                    $dayScriptScore += $sc;
                    $dayScriptsDetail[(string)$sid] = [
                        'starts' => $ss,
                        'finishes' => $sf,
                        'conversion' => ($ss > 0) ? round($sf * 100 / $ss, 1) : 0.0,
                        'score' => $sc,
                    ];
                }
                $dayScriptConv = ($dayScriptStarts > 0)
                    ? round($dayScriptFinishes * 100 / $dayScriptStarts, 1) : 0.0;

                $dayCriteriaPct = [];
                foreach ($detailCriteriaIds as $tid) {
                    $hit = isset($ds['type_hit_sec'][$tid]) ? (int)$ds['type_hit_sec'][$tid] : 0;
                    $dayCriteriaPct[(string)$tid] = ($sec > 0) ? round($hit * 100 / $sec, 1) : 0.0;
                }
                $dayTracking = [];
                foreach ($detailTrackingIds as $did) {
                    $dayTracking[(string)$did] = isset($ds['tracking'][$did]) ? (int)$ds['tracking'][$did] : 0;
                }
                $dayExternal = [];
                foreach ($extByKeyDay as $ek => $byEmpDay) {
                    $dayExternal[$ek] = isset($byEmpDay[$empId][$dayKey])
                        ? (float)$byEmpDay[$empId][$dayKey] : 0.0;
                }

                $daysDetail[] = [
                    'date' => $dayKey,
                    'day' => $d,
                    'month' => $m,
                    'year' => $y,
                    'day_code' => $d . '.' . $m . '.' . $y,
                    'playtime' => (int)$ds['playtime'],
                    'audio_hours' => round(((int)$ds['playtime']) / 3600, 2),
                    'avg_score' => $avg,
                    'pass' => ($sec > 0 && $avg >= $threshold) ? 1 : 0,
                    'instances' => isset($ds['instances']) ? (int)$ds['instances'] : 0,
                    'important' => isset($ds['important']) ? (int)$ds['important'] : 0,
                    'script_starts' => $dayScriptStarts,
                    'script_finishes' => $dayScriptFinishes,
                    'script_conversion' => $dayScriptConv,
                    'script_score' => round($dayScriptScore, 2),
                    'scripts_detail' => $dayScriptsDetail,
                    'criteria_pct' => $dayCriteriaPct,
                    'tracking_detail' => $dayTracking,
                    'external_detail' => $dayExternal,
                ];
            }

            $rows[$empId] = [
                'employee_id' => $empId,
                'name' => $e['initials'],
                'name_full' => $e['name'],
                'color' => $e['color'],
                'color_text' => $e['color_text'],
                'days_count' => $daysCount,
                'audio_hours' => round($stRow['playtime'] / 3600, 1),
                'avg_score' => $avgScore,
                'instances' => $stRow['instances'],
                'days_pass_score' => $stRow['days_pass_score'],
                'days_fail_score' => $stRow['days_fail_score'],
                'script_starts' => $scriptStarts,
                'script_finishes' => $scriptFinishes,
                'script_conversion' => $scriptConv,
                'script_score' => round($scriptScoreSum, 2),
                'scripts_detail' => $scriptsDetail,
                'criteria_pct' => $criteriaPct,
                'tracking_detail' => $trackingDetail,
                'tracking_total' => array_sum($stRow['tracking']),
                'external_detail' => $externalDetail,
                'important' => isset($stRow['important']) ? (int)$stRow['important'] : 0,
                'days_detail' => $daysDetail,
            ];

            if ($calcMetrics !== []) {
                $ev = SummaryComputed::evalList($calcMetrics, $rows[$empId]);
                $rows[$empId]['computed_detail'] = $ev['detail'];
                $rows[$empId]['computed_pass'] = $ev['pass'];
                foreach ($rows[$empId]['days_detail'] as $di => $dayRow) {
                    $dev = SummaryComputed::evalList($calcMetrics, $dayRow);
                    $rows[$empId]['days_detail'][$di]['computed_detail'] = $dev['detail'];
                    $rows[$empId]['days_detail'][$di]['computed_pass'] = $dev['pass'];
                }
            } else {
                $rows[$empId]['computed_detail'] = [];
                $rows[$empId]['computed_pass'] = [];
            }
        }

        if ($maxScoreAvg <= 0) {
            foreach ($rows as $r) {
                if ($r['avg_score'] > $maxScoreAvg) {
                    $maxScoreAvg = $r['avg_score'];
                    $bestName = (string)$r['name'];
                }
            }
        }

        uasort($rows, static function (array $a, array $b): int {
            if ($a['days_count'] !== $b['days_count']) {
                return $b['days_count'] - $a['days_count'];
            }
            return ($b['avg_score'] > $a['avg_score']) ? 1 : -1;
        });

        $globalAvgScore = ($totalScoreSec > 0) ? round(
            array_sum(array_map(static function (array $stRow): float {
                return (float)$stRow['score_w'];
            }, $empStats)) / $totalScoreSec,
            2
        ) : 0.0;

        $computedOut = [];
        if ($calcMetrics !== []) {
            $teamRow = [
                'audio_hours' => $totalAudioHours,
                'avg_score' => $globalAvgScore,
                'script_score' => $totalScriptScore,
                'instances' => $totalInstances,
                'script_starts' => 0,
                'script_finishes' => 0,
                'script_conversion' => 0.0,
                'days_count' => 0,
                'days_pass_score' => $globalScorePass,
                'days_fail_score' => $globalScoreFail,
                'external_detail' => [],
                'criteria_pct' => [],
                'tracking_detail' => [],
                'scripts_detail' => [],
            ];
            foreach ($scriptMeta as $sid => $sm) {
                $teamRow['script_starts'] += (int)$sm['starts'];
                $teamRow['script_finishes'] += (int)$sm['finishes'];
                $teamRow['scripts_detail'][$sid] = [
                    'starts' => (int)$sm['starts'],
                    'finishes' => (int)$sm['finishes'],
                    'conversion' => (float)$sm['conversion'],
                    'score' => (float)$sm['score'],
                ];
            }
            if ($teamRow['script_starts'] > 0) {
                $teamRow['script_conversion'] = round(
                    $teamRow['script_finishes'] * 100 / $teamRow['script_starts'],
                    1
                );
            }
            foreach ($extByKey as $ek => $byEmp) {
                $sum = 0.0;
                foreach ($byEmp as $v) {
                    $sum += (float)$v;
                }
                $teamRow['external_detail'][$ek] = $sum;
            }
            foreach ($typeMeta as $tid => $tm) {
                $teamRow['criteria_pct'][$tid] = isset($tm['percent']) ? (float)$tm['percent'] : 0.0;
            }
            foreach ($dictMeta as $did => $td) {
                $teamRow['tracking_detail'][$did] = isset($td['count']) ? (float)$td['count'] : 0.0;
            }
            $teamEv = SummaryComputed::evalList($calcMetrics, $teamRow);
            $totalCnt = count($rows);
            foreach ($calcMetrics as $cm) {
                $ck = (string)$cm['key'];
                $passCnt = 0;
                foreach ($rows as $r) {
                    if (isset($r['computed_pass'][$ck]) && (int)$r['computed_pass'][$ck] === 1) {
                        $passCnt++;
                    }
                }
                $computedOut[] = [
                    'key' => $ck,
                    'title' => (string)$cm['title'],
                    'kind' => (string)$cm['kind'],
                    'meta' => $cm,
                    'total' => $teamEv['detail'][$ck] ?? null,
                    'pass' => $teamEv['pass'][$ck] ?? null,
                    'pass_count' => $passCnt,
                    'rows_count' => $totalCnt,
                ];
            }
        }

        // EventBus hooks AFTER core aggregation
        $cards = CalendarHooks::emitSummaryCards(
            is_array($config['cards'] ?? null) ? $config['cards'] : [],
            [
                'profile_id' => (int)($profile['ID'] ?? 0),
                'period' => $period,
                'position_id' => $positionId,
            ]
        );
        $config['cards'] = $cards;

        $hookSeries = CalendarHooks::emitSummaryExternal([
            'profile_id' => (int)($profile['ID'] ?? 0),
            'position_id' => $positionId,
            'period' => $period,
            'employee_ids' => $empIds,
            'rows' => $rows,
        ]);
        foreach ($hookSeries as $series) {
            if (!is_array($series) || empty($series['key'])) {
                continue;
            }
            $key = (string)$series['key'];
            $byEmp = isset($series['by_employee']) && is_array($series['by_employee'])
                ? $series['by_employee'] : [];
            $external[] = [
                'key' => $key,
                'title' => (string)($series['title'] ?? $key),
                'by_emp' => $byEmp,
                'by_emp_day' => [],
                'total' => isset($series['total']) ? (float)$series['total'] : 0.0,
                'rows_count' => 0,
                'error' => '',
                'meta' => [
                    'unit' => (string)($series['unit'] ?? ''),
                    'source' => 'hook',
                ],
            ];
            foreach ($rows as $empId => &$rowRef) {
                if (!isset($rowRef['external_detail']) || !is_array($rowRef['external_detail'])) {
                    $rowRef['external_detail'] = [];
                }
                $rowRef['external_detail'][$key] = isset($byEmp[$empId]) ? (float)$byEmp[$empId] : 0.0;
            }
            unset($rowRef);
        }

        SummaryProgress::mark('Employee table ready');

        return [
            'year' => $year,
            'month' => $month,
            'period' => $period,
            'period_mode' => $period['mode'],
            'period_label' => $period['label'],
            'date_from' => $period['date_from'],
            'date_to' => $period['date_to'],
            'period_start' => $monthStart,
            'period_end' => $monthEnd,
            'profile_id' => (int)($profile['ID'] ?? 0),
            'profile_name' => (string)($profile['NAME'] ?? ''),
            'position_id' => $positionId,
            'position_name' => self::positionName($positionId),
            'is_default' => !empty($profile['IS_DEFAULT']),
            'config' => $config,
            'labels' => $labels,
            'cards' => $cards,
            'total_audio_hours' => $totalAudioHours,
            'total_transcription_chars' => $totalChars,
            'total_transcription_words' => $totalWords,
            'total_key_instances' => $totalInstances,
            'total_records' => $totalRecords,
            'global_avg_score' => $globalAvgScore,
            'global_score_pass_days' => $globalScorePass,
            'global_score_fail_days' => $globalScoreFail,
            'score_pass_threshold' => $threshold,
            'criteria' => $typeMeta,
            'detail_criteria' => $detailTypeMeta,
            'criteria_fails' => $criteriaFails,
            'scripts' => $scriptMeta,
            'total_script_score' => $totalScriptScore,
            'tracking' => $dictMeta,
            'detail_tracking' => $detailTrackingMeta,
            'external' => $external,
            'computed' => $computedOut,
            'benchmark_score' => $maxScoreAvg,
            'benchmark_employee_name' => $bestName,
            'rows' => $rows,
            // Compact totals for callers expecting previous FreeSaaS shape
            'totals' => [
                'audio_hours' => $totalAudioHours,
                'records' => $totalRecords,
                'avg_score' => $globalAvgScore > 0 ? $globalAvgScore : null,
                'score_pass_days' => $globalScorePass,
                'score_fail_days' => $globalScoreFail,
                'criteria_count' => count($criteriaIds),
                'scripts_count' => count($scriptIds),
                'tracking_count' => count($trackingIds),
                'script_score_total' => $totalScriptScore,
                'key_instances' => $totalInstances,
                'transcription_words' => $totalWords,
            ],
        ];
    }

    /**
     * @return array{mode:string,start:int,end:int,year:int,month:int,date_from:string,date_to:string,label:string}
     */
    public static function resolvePeriod(int $year, int $month, string $dateFrom = '', string $dateTo = ''): array
    {
        $dateFrom = trim($dateFrom);
        $dateTo = trim($dateTo);
        $now = time();

        if ($dateFrom !== '' && $dateTo !== ''
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)
        ) {
            $start = strtotime($dateFrom . ' 00:00:00');
            $end = strtotime($dateTo . ' 23:59:59');
            if ($start !== false && $end !== false && $start > 0 && $end > 0) {
                if ($start > $end) {
                    $tmp = $start;
                    $start = mktime(0, 0, 0, (int)date('n', $end), (int)date('j', $end), (int)date('Y', $end));
                    $end = mktime(23, 59, 59, (int)date('n', $tmp), (int)date('j', $tmp), (int)date('Y', $tmp));
                }
                if ($end > $now) {
                    $end = $now;
                }
                if ($start > $end) {
                    $start = mktime(0, 0, 0, (int)date('n', $end), (int)date('j', $end), (int)date('Y', $end));
                }
                $maxSpan = 366 * 86400;
                if (($end - $start) > $maxSpan) {
                    $start = $end - $maxSpan;
                    $start = mktime(0, 0, 0, (int)date('n', $start), (int)date('j', $start), (int)date('Y', $start));
                }
                return [
                    'mode' => 'range',
                    'start' => (int)$start,
                    'end' => (int)$end,
                    'year' => (int)date('Y', (int)$start),
                    'month' => (int)date('n', (int)$start),
                    'date_from' => date('Y-m-d', (int)$start),
                    'date_to' => date('Y-m-d', (int)$end),
                    'label' => date('d.m.Y', (int)$start) . ' — ' . date('d.m.Y', (int)$end),
                ];
            }
        }

        if ($year <= 0) {
            $year = (int)date('Y');
        }
        if ($month < 1 || $month > 12) {
            $month = (int)date('n');
        }
        $start = mktime(0, 0, 0, $month, 1, $year);
        if ($start !== false && $start > $now) {
            $year = (int)date('Y');
            $month = (int)date('n');
            $start = mktime(0, 0, 0, $month, 1, $year);
        }
        $daysInMonth = (int)date('t', $start ?: time());
        $end = mktime(23, 59, 59, $month, $daysInMonth, $year);
        if ($end !== false && $end > $now) {
            $end = $now;
        }
        $label = t('audio_reports.calendar.month.' . $month) . ' ' . $year;
        return [
            'mode' => 'month',
            'start' => (int)$start,
            'end' => (int)$end,
            'year' => $year,
            'month' => $month,
            'date_from' => date('Y-m-d', (int)$start),
            'date_to' => date('Y-m-d', (int)$end),
            'label' => $label,
        ];
    }

    public static function formatFioInitials(string $fullName): string
    {
        $fullName = trim($fullName);
        if ($fullName === '') {
            return '';
        }
        $parts = preg_split('/\s+/u', $fullName);
        if (!is_array($parts) || count($parts) === 1) {
            return $fullName;
        }
        $lastName = $parts[0];
        $initials = '';
        for ($i = 1, $n = count($parts); $i < $n; $i++) {
            $p = trim($parts[$i]);
            if ($p !== '') {
                $initials .= mb_strtoupper(mb_substr($p, 0, 1, 'UTF-8'), 'UTF-8') . '.';
            }
        }
        return $lastName . ($initials !== '' ? ' ' . $initials : '');
    }

    public static function scoreColor(float $score, float $threshold): string
    {
        if ($threshold <= 0) {
            if ($score <= 0) {
                return '#b91c1c';
            }
            if ($score < 1) {
                return '#ea580c';
            }
            if ($score < 2) {
                return '#16a34a';
            }
            return '#14532d';
        }
        $ratio = $score / $threshold;
        if ($ratio < 0.6) {
            return '#b91c1c';
        }
        if ($ratio < 0.85) {
            return '#dc2626';
        }
        if ($ratio < 1.0) {
            return '#ea580c';
        }
        if ($ratio < 1.15) {
            return '#16a34a';
        }
        if ($ratio < 1.35) {
            return '#15803d';
        }
        return '#14532d';
    }

    /**
     * Parse KEY_TYPES: ##id-…## fragments (oper ## split).
     *
     * @return array<int,true>
     */
    public static function parseKeyTypes(string $raw): array
    {
        $found = [];
        $parts = explode('##', $raw);
        foreach ($parts as $p) {
            $p = trim($p, '#');
            if ($p === '') {
                continue;
            }
            $bits = explode('-', $p);
            $tid = isset($bits[0]) ? (int)$bits[0] : 0;
            if ($tid > 0) {
                $found[$tid] = true;
            }
        }
        return $found;
    }

    /**
     * @param array<int, array<string,mixed>> $empStats
     */
    private static function ensureDayStats(array &$empStats, int $empId, string $dayKey): void
    {
        if ($empId <= 0 || $dayKey === '' || !isset($empStats[$empId])) {
            return;
        }
        if (!isset($empStats[$empId]['day_stats'][$dayKey])) {
            $empStats[$empId]['day_stats'][$dayKey] = [
                'playtime' => 0,
                'score_w' => 0.0,
                'score_sec' => 0,
                'instances' => 0,
                'important' => 0,
                'type_hit_sec' => [],
                'tracking' => [],
                'script_starts' => [],
                'script_finishes' => [],
                'script_score' => [],
            ];
        }
    }

    /**
     * @return array<int, array{id:int,name:string,initials:string,color:string,color_text:string}>
     */
    private static function employeesForPosition(int $positionId): array
    {
        if ($positionId < 1 || !Database::tableExists('base_users')) {
            return [];
        }
        $st = Database::pdo()->prepare(
            "SELECT id, name, color, color_text FROM base_users
             WHERE ID_STATUS = ? AND enabled = '1'
               AND (IS_HIDDEN IS NULL OR IS_HIDDEN < 1)
             ORDER BY name ASC"
        );
        $st->execute([$positionId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $u) {
            $id = (int)$u['id'];
            $name = trim((string)($u['name'] ?? '')) ?: ('#' . $id);
            $out[$id] = [
                'id' => $id,
                'name' => $name,
                'initials' => self::formatFioInitials($name),
                'color' => (string)($u['color'] ?? ''),
                'color_text' => (string)($u['color_text'] ?? ''),
            ];
        }
        return $out;
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
