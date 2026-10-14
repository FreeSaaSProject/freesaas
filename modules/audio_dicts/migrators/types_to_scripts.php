<?php
declare(strict_types=1);

/**
 * Convert base_records_types scoring criteria into RATING_ENABLED scripts.
 * Idempotent via LEGACY_TYPE_ID on script roots. Type 13 (Рабочие зоны) is dropped.
 * Massage types 20–27 coalesce into one root «М: Приём» (dual-start + BREAK reopen).
 *
 * Dry-run: set env FREESAAS_DRY_RUN=1 (no writes).
 *
 * @return array{ok:bool,message:string,created?:int,skipped?:int,dropped?:list<int>,map?:array<int,int>,anomalies?:list<string>}
 */
return static function (PDO $pdo, array $spec): array {
    $dry = (getenv('FREESAAS_DRY_RUN') === '1') || !empty($spec['dry_run']);

    if (!Database::tableExists('base_records_types')
        || !Database::tableExists('base_records_scripts')
        || !Database::tableExists('base_records_keys')
        || !Database::columnExists('base_records_scripts', 'RATING_ENABLED')
        || !Database::columnExists('base_records_scripts', 'LEGACY_TYPE_ID')
    ) {
        return [
            'ok' => false,
            'message' => 'schema incomplete — run audio_dicts 002_scripts_rating first',
        ];
    }

    $hasStartGate = Database::columnExists('base_records_scripts', 'START_GATE');
    if (!$hasStartGate) {
        return [
            'ok' => false,
            'message' => 'schema incomplete — run audio_dicts 003_scripts_start_gate first',
        ];
    }

    $joinIds = static function (array $ids): string {
        $clean = [];
        foreach ($ids as $id) {
            $n = (int)$id;
            if ($n > 0) {
                $clean[] = $n;
            }
        }
        $clean = array_values(array_unique($clean));
        if ($clean === []) {
            return '';
        }
        return '#' . implode('#,#', $clean) . '#';
    };

    $existing = [];
    $stEx = $pdo->query(
        'SELECT ID, LEGACY_TYPE_ID FROM base_records_scripts
         WHERE PID = 0 AND LEGACY_TYPE_ID > 0'
    );
    foreach ($stEx->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $existing[(int)$row['LEGACY_TYPE_ID']] = (int)$row['ID'];
    }

    $types = [];
    foreach ($pdo->query(
        'SELECT ID, NAME, DESCR, ENABLED, KPI_ENABLED, SCORE_VALUE, SCORE_TYPE_ID,
                SCORE_DIVERSITY, SCORE_INSTANCES_MIN, SCORE_INSTANCES_MAX,
                VOLUME, STRINGS_AMOUNT, IS_HIDDEN, ID_POSITION
         FROM base_records_types WHERE ENABLED = 1'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $types[(int)$row['ID']] = $row;
    }

    $dictsByType = [];
    foreach ($pdo->query(
        'SELECT ID_TYPE, ID_DICTONARY, COUNT(*) AS c
         FROM base_records_keys WHERE DISABLED < 1 AND ID_TYPE > 0
         GROUP BY ID_TYPE, ID_DICTONARY'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $tid = (int)$row['ID_TYPE'];
        $did = (int)$row['ID_DICTONARY'];
        if ($tid < 1 || $did < 1) {
            continue;
        }
        if (!isset($dictsByType[$tid])) {
            $dictsByType[$tid] = [];
        }
        $dictsByType[$tid][$did] = (int)$row['c'];
    }

    $anomalies = [];
    $dropped = [];
    $created = 0;
    $skipped = 0;
    $map = $existing;

    if (isset($types[13])) {
        $dropped[] = 13;
        unset($types[13]);
    }

    $clientChildren = [];
    foreach ($types as $tid => $t) {
        if ((int)$t['SCORE_TYPE_ID'] === 2 && $tid !== 2) {
            $clientChildren[$tid] = $t;
        }
    }

    $insertRoot = static function (
        string $name,
        string $idPosition,
        int $ratingEnabled,
        int $isHidden,
        int $legacyTypeId
    ) use ($pdo, $dry, &$created): int {
        if ($dry) {
            $created++;
            return -1 * $legacyTypeId;
        }
        $st = $pdo->prepare(
            'INSERT INTO base_records_scripts
             (PID, NEXT_QID, EL_TYPE, NUM, NAME, DT_CREATE, DISABLED, IS_REQ, FINISH,
              IDS_DICTONARYS, SCORE, `BREAK`, TIMEOUT, DIVERSITY, INSTANCES_MIN,
              COLLECTION_KILLER, ID_POSITION, RATING_ENABLED, IS_HIDDEN, LEGACY_TYPE_ID,
              SCORE_RATE, KPI, COND_TYPE, COND_VALUE)
             VALUES (0, 0, 0, 0, ?, ?, 0, 0, 0, \'\', 0, 0, 0, 0, 0, 0, ?, ?, ?, ?, 0, 0, \'\', 0)'
        );
        $st->execute([
            mb_substr($name, 0, 40),
            time(),
            $idPosition,
            $ratingEnabled,
            $isHidden,
            $legacyTypeId,
        ]);
        $created++;
        return (int)$pdo->lastInsertId();
    };

    $hasExtendTimeout = Database::columnExists('base_records_scripts', 'EXTEND_TIMEOUT');
    $insertPart = static function (
        int $rootId,
        int $num,
        string $name,
        array $opts
    ) use ($pdo, $dry, $joinIds, $hasExtendTimeout): void {
        if ($dry || $rootId < 1) {
            return;
        }
        $dicts = $joinIds($opts['dict_ids'] ?? []);
        $extend = array_key_exists('extend_timeout', $opts)
            ? (!empty($opts['extend_timeout']) ? 1 : 0)
            : 1;
        $st = $pdo->prepare(
            'INSERT INTO base_records_scripts
             (PID, NEXT_QID, EL_TYPE, NUM, NAME, DT_CREATE, DISABLED, IS_REQ, FINISH,
              IDS_DICTONARYS, SCORE, `BREAK`, TIMEOUT, DIVERSITY, INSTANCES_MIN,
              COLLECTION_KILLER, ID_POSITION, SCORE_RATE, KPI, COND_TYPE, COND_VALUE, START_GATE'
            . ($hasExtendTimeout ? ', EXTEND_TIMEOUT' : '')
            . ')
             VALUES (?, 0, 1, ?, ?, ?, 0, ?, ?, ?, 0, ?, ?, ?, ?, ?, \'\', ?, ?, ?, ?, ?'
            . ($hasExtendTimeout ? ', ?' : '')
            . ')'
        );
        $params = [
            $rootId,
            $num,
            mb_substr($name, 0, 40),
            time(),
            (int)($opts['is_req'] ?? 0),
            (int)($opts['finish'] ?? 0),
            $dicts,
            (int)($opts['break'] ?? 0),
            (int)($opts['timeout'] ?? 0),
            (int)($opts['diversity'] ?? 0),
            (int)($opts['instances_min'] ?? 0),
            (int)($opts['killer'] ?? 0),
            (int)($opts['score_rate'] ?? 0),
            (int)($opts['kpi'] ?? 0),
            (string)($opts['cond_type'] ?? ''),
            (int)($opts['cond_value'] ?? 0),
            max(0, min(2, (int)($opts['start_gate'] ?? 0))),
        ];
        if ($hasExtendTimeout) {
            $params[] = $extend;
        }
        $st->execute($params);
    };

    /** Delete script roots + parts + collections for given root IDs. */
    $deleteScriptRoots = static function (array $rootIds) use ($pdo, $dry): int {
        $ids = [];
        foreach ($rootIds as $id) {
            $n = (int)$id;
            if ($n > 0) {
                $ids[$n] = $n;
            }
        }
        $ids = array_values($ids);
        if ($ids === [] || $dry) {
            return count($ids);
        }
        $in = implode(',', array_map('intval', $ids));
        if (Database::tableExists('base_records_collections')) {
            $collIds = $pdo->query(
                "SELECT ID FROM base_records_collections WHERE ID_SCRIPT IN ($in)"
            )->fetchAll(PDO::FETCH_COLUMN) ?: [];
            if ($collIds !== []) {
                $cIn = implode(',', array_map('intval', $collIds));
                if (Database::tableExists('base_records_collections_datas')) {
                    $pdo->exec("DELETE FROM base_records_collections_datas WHERE ID_COLLECTION IN ($cIn)");
                }
                $pdo->exec("DELETE FROM base_records_collections WHERE ID IN ($cIn)");
            }
        }
        $pdo->exec("DELETE FROM base_records_scripts WHERE PID IN ($in)");
        $pdo->exec("DELETE FROM base_records_scripts WHERE ID IN ($in)");
        return count($ids);
    };

    if (isset($types[2]) && (float)$types[2]['SCORE_VALUE'] != 0.0) {
        $t = $types[2];
        if (isset($existing[2])) {
            $skipped++;
            $map[2] = $existing[2];
        } else {
            $dictIds = array_keys($dictsByType[2] ?? []);
            if ($dictIds === []) {
                $anomalies[] = 'type 2 has SCORE but no keys/dicts';
            }
            $rootId = $insertRoot(
                (string)$t['NAME'],
                (string)($t['ID_POSITION'] ?? ''),
                1,
                (int)$t['IS_HIDDEN'] > 0 ? 1 : 0,
                2
            );
            $map[2] = $rootId;
            $num = 1;
            $insertPart($rootId, $num++, (string)$t['NAME'], [
                'is_req' => 1,
                'dict_ids' => $dictIds,
                'diversity' => max(0, (int)$t['SCORE_DIVERSITY']),
                'instances_min' => max(0, (int)$t['SCORE_INSTANCES_MIN']),
                'score_rate' => (int)$t['SCORE_VALUE'],
                'timeout' => 120,
            ]);
            foreach ($clientChildren as $cid => $ct) {
                $sv = (int)$ct['SCORE_VALUE'];
                $instMin = (int)$ct['SCORE_INSTANCES_MIN'];
                if ($instMin < 0) {
                    $insertPart($rootId, $num++, (string)$ct['NAME'], [
                        'cond_type' => 'no_intersect',
                        'score_rate' => $sv > 0 ? $sv : 1,
                    ]);
                    $map[$cid] = $rootId;
                    continue;
                }
                $stringsReq = (int)$ct['STRINGS_AMOUNT'];
                $childDicts = array_keys($dictsByType[$cid] ?? []);
                if ($stringsReq > 0 && $childDicts === []) {
                    $insertPart($rootId, $num++, (string)$ct['NAME'], [
                        'cond_type' => 'strings_gt',
                        'cond_value' => $stringsReq,
                        'score_rate' => $sv > 0 ? $sv : 1,
                    ]);
                    $map[$cid] = $rootId;
                    continue;
                }
                if ($sv == 0 && $childDicts === []) {
                    continue;
                }
                $insertPart($rootId, $num++, (string)$ct['NAME'], [
                    'dict_ids' => $childDicts,
                    'diversity' => max(0, (int)$ct['SCORE_DIVERSITY']),
                    'instances_min' => max(0, $instMin),
                    'score_rate' => $sv,
                    'kpi' => (int)$ct['KPI_ENABLED'] > 0 ? 7 : 0,
                    'timeout' => 120,
                ]);
                $map[$cid] = $rootId;
            }
        }
        unset($types[2]);
        foreach ($clientChildren as $cid => $_) {
            unset($types[$cid]);
        }
    }

    // Massage reception pack: types 20–27 → one root «М: Приём».
    $massageTypeIds = [20, 21, 22, 23, 24, 25, 26, 27];
    $massagePresent = false;
    foreach ($massageTypeIds as $mtid) {
        if (isset($types[$mtid]) || isset($existing[$mtid])) {
            $massagePresent = true;
            break;
        }
    }
    if ($massagePresent) {
        $sessionTimeout = 5400;
        $canonName = 'М: Приём';
        $ready = false;
        if (isset($existing[20])) {
            $stName = $pdo->prepare(
                'SELECT NAME FROM base_records_scripts WHERE ID = ? AND PID = 0 LIMIT 1'
            );
            $stName->execute([(int)$existing[20]]);
            $curName = (string)($stName->fetchColumn() ?: '');
            if ($curName === $canonName) {
                $stGate = $pdo->prepare(
                    'SELECT COUNT(*) FROM base_records_scripts
                     WHERE PID = ? AND START_GATE > 0 AND DISABLED < 1'
                );
                $stGate->execute([(int)$existing[20]]);
                $ready = (int)$stGate->fetchColumn() > 0;
            }
        }

        if ($ready) {
            $skipped++;
            $rootId = (int)$existing[20];
            foreach ($massageTypeIds as $mtid) {
                $map[$mtid] = $rootId;
            }
        } else {
            $oldRoots = [];
            foreach ($massageTypeIds as $mtid) {
                if (isset($existing[$mtid])) {
                    $oldRoots[] = (int)$existing[$mtid];
                    unset($existing[$mtid], $map[$mtid]);
                }
            }
            $deleted = $deleteScriptRoots($oldRoots);
            if ($deleted > 0) {
                $anomalies[] = "massage: deleted {$deleted} old root(s) for types 20–27";
            }

            $pos = '#1#,#18#';
            if (isset($types[20]['ID_POSITION']) && trim((string)$types[20]['ID_POSITION']) !== '') {
                $pos = (string)$types[20]['ID_POSITION'];
            }
            $rootId = $insertRoot($canonName, $pos, 1, 0, 20);
            foreach ($massageTypeIds as $mtid) {
                $map[$mtid] = $rootId;
            }

            // Part order: должность, представиться, цена допа, комфорт, пинги, продавалки, план, прощание.
            $partDefs = [
                21 => [ // Должность — primary start + BREAK
                    'start_gate' => 1, 'break' => 1, 'is_req' => 1, 'finish' => 0,
                    'instances_min' => 1, 'score_rate' => 2,
                ],
                20 => [ // Представиться — primary start + BREAK
                    'start_gate' => 1, 'break' => 1, 'is_req' => 1, 'finish' => 0,
                    'instances_min' => 1, 'score_rate' => 2,
                ],
                23 => [ // Цена допа — alt start
                    'start_gate' => 2, 'break' => 0, 'is_req' => 0, 'finish' => 0,
                    'instances_min' => 1, 'score_rate' => 2,
                ],
                26 => [ // Комфорт
                    'start_gate' => 0, 'break' => 0, 'is_req' => 0, 'finish' => 0,
                    'instances_min' => 1, 'score_rate' => 2,
                ],
                24 => [ // Пинги — не продлевают TIMEOUT сессии
                    'start_gate' => 0, 'break' => 0, 'is_req' => 0, 'finish' => 0,
                    'instances_min' => 1, 'score_rate' => 2, 'extend_timeout' => 0,
                ],
                22 => [ // Продавалки
                    'start_gate' => 0, 'break' => 0, 'is_req' => 0, 'finish' => 0,
                    'instances_min' => 1, 'score_rate' => 2,
                ],
                27 => [ // План процедур
                    'start_gate' => 0, 'break' => 0, 'is_req' => 0, 'finish' => 0,
                    'instances_min' => 0, 'score_rate' => 1,
                ],
                25 => [ // Прощание — FINISH
                    'start_gate' => 0, 'break' => 0, 'is_req' => 0, 'finish' => 1,
                    'instances_min' => 2, 'score_rate' => 2,
                ],
            ];
            $num = 1;
            foreach ($partDefs as $tid => $flags) {
                $tRow = $types[$tid] ?? null;
                $name = $tRow ? (string)$tRow['NAME'] : ('type ' . $tid);
                // Shorten part names: strip «М: N-» prefix when present.
                if (preg_match('/^М:\s*\d+-(.+)$/u', $name, $m)) {
                    $name = (string)$m[1];
                }
                $dictIds = array_keys($dictsByType[$tid] ?? []);
                if ($tRow) {
                    $flags['score_rate'] = (int)$tRow['SCORE_VALUE'] != 0
                        ? (int)$tRow['SCORE_VALUE']
                        : (int)$flags['score_rate'];
                    $instMin = (int)$tRow['SCORE_INSTANCES_MIN'];
                    if ($instMin > 0 || $tid === 27) {
                        $flags['instances_min'] = max(0, $instMin);
                    }
                    $flags['diversity'] = max(0, (int)$tRow['SCORE_DIVERSITY']);
                }
                $insertPart($rootId, $num++, $name, array_merge($flags, [
                    'dict_ids' => $dictIds,
                    'timeout' => $sessionTimeout,
                ]));
            }
        }

        foreach ($massageTypeIds as $mtid) {
            unset($types[$mtid]);
        }
    }

    foreach ($types as $tid => $t) {
        $sv = (int)$t['SCORE_VALUE'];
        if ($sv == 0) {
            continue;
        }
        if (isset($existing[$tid])) {
            $skipped++;
            $map[$tid] = $existing[$tid];
            continue;
        }
        $dictIds = array_keys($dictsByType[$tid] ?? []);
        $stringsReq = (int)$t['STRINGS_AMOUNT'];
        $volReq = (int)$t['VOLUME'];
        $instMin = (int)$t['SCORE_INSTANCES_MIN'];

        $rootId = $insertRoot(
            (string)$t['NAME'],
            (string)($t['ID_POSITION'] ?? ''),
            1,
            (int)$t['IS_HIDDEN'] > 0 ? 1 : 0,
            $tid
        );
        $map[$tid] = $rootId;

        if ($dictIds === [] && ($stringsReq > 0 || $volReq > 0 || $instMin < 0)) {
            $cond = '';
            $cval = 0;
            if ($instMin < 0) {
                $cond = 'no_intersect';
            } elseif ($stringsReq > 0) {
                $cond = 'strings_gt';
                $cval = $stringsReq;
            } elseif ($volReq > 0) {
                $cond = 'volume_gt';
                $cval = $volReq;
            }
            $insertPart($rootId, 1, (string)$t['NAME'], [
                'is_req' => 1,
                'cond_type' => $cond,
                'cond_value' => $cval,
                'score_rate' => $sv,
            ]);
            continue;
        }

        if ($dictIds === []) {
            $anomalies[] = "type {$tid} ({$t['NAME']}) has SCORE={$sv} but no dicts/keys — empty script root";
            continue;
        }

        $insertPart($rootId, 1, (string)$t['NAME'], [
            'is_req' => 1,
            'dict_ids' => $dictIds,
            'diversity' => max(0, (int)$t['SCORE_DIVERSITY']),
            'instances_min' => max(0, $instMin),
            'score_rate' => $sv,
            'kpi' => (int)$t['KPI_ENABLED'] > 0 ? 7 : 0,
            'timeout' => 120,
        ]);
    }

    $remapNotes = [];
    if (!$dry && $map !== []) {
        if (Database::tableExists('base_records_summarys')) {
            $cols = [];
            foreach ($pdo->query('SHOW COLUMNS FROM base_records_summarys')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $c) {
                $cols[strtoupper((string)$c['Field'])] = (string)$c['Field'];
            }
            $cfgCol = $cols['CONFIG'] ?? $cols['CONFIG_JSON'] ?? null;
            $idCol = $cols['ID'] ?? null;
            if ($cfgCol && $idCol) {
                $rows = $pdo->query("SELECT `{$idCol}` AS id, `{$cfgCol}` AS cfg FROM base_records_summarys")->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($rows as $row) {
                    $cfg = json_decode((string)$row['cfg'], true);
                    if (!is_array($cfg)) {
                        continue;
                    }
                    $changed = false;
                    foreach (['criteria_type_ids', 'detail_criteria_ids'] as $key) {
                        if (!isset($cfg[$key]) || !is_array($cfg[$key])) {
                            continue;
                        }
                        $new = [];
                        foreach ($cfg[$key] as $tid) {
                            $tid = (int)$tid;
                            $new[] = isset($map[$tid]) ? (int)$map[$tid] : $tid;
                            if (isset($map[$tid]) && (int)$map[$tid] !== $tid) {
                                $changed = true;
                            }
                        }
                        $cfg[$key] = $new;
                        $cfg[str_replace('type', 'script', $key)] = $new;
                    }
                    if ($changed) {
                        $upd = $pdo->prepare("UPDATE base_records_summarys SET `{$cfgCol}` = ? WHERE `{$idCol}` = ?");
                        $upd->execute([json_encode($cfg, JSON_UNESCAPED_UNICODE), (int)$row['id']]);
                        $remapNotes[] = 'summary#' . (int)$row['id'];
                    }
                }
            }
        }
        if (Database::tableExists('base_staff_report_profiles')) {
            $cols = [];
            foreach ($pdo->query('SHOW COLUMNS FROM base_staff_report_profiles')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $c) {
                $cols[strtoupper((string)$c['Field'])] = (string)$c['Field'];
            }
            $critCol = $cols['CRITERIA_TYPE_IDS'] ?? $cols['CRITERIA_IDS'] ?? null;
            $idCol = $cols['ID'] ?? null;
            if ($critCol && $idCol) {
                $rows = $pdo->query("SELECT `{$idCol}` AS id, `{$critCol}` AS crit FROM base_staff_report_profiles")->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($rows as $row) {
                    $raw = (string)$row['crit'];
                    $ids = [];
                    if ($raw !== '' && ($raw[0] ?? '') === '[') {
                        $decoded = json_decode($raw, true);
                        $ids = is_array($decoded) ? $decoded : [];
                    } else {
                        foreach (preg_split('/[#,\s]+/', $raw) ?: [] as $p) {
                            $n = (int)$p;
                            if ($n > 0) {
                                $ids[] = $n;
                            }
                        }
                    }
                    if ($ids === []) {
                        continue;
                    }
                    $new = [];
                    $changed = false;
                    foreach ($ids as $tid) {
                        $tid = (int)$tid;
                        if (isset($map[$tid])) {
                            $new[] = (int)$map[$tid];
                            if ((int)$map[$tid] !== $tid) {
                                $changed = true;
                            }
                        } else {
                            $new[] = $tid;
                        }
                    }
                    if ($changed) {
                        $val = (($raw[0] ?? '') === '[')
                            ? json_encode(array_values(array_unique($new)))
                            : '#' . implode('#,#', array_values(array_unique($new))) . '#';
                        $upd = $pdo->prepare("UPDATE base_staff_report_profiles SET `{$critCol}` = ? WHERE `{$idCol}` = ?");
                        $upd->execute([$val, (int)$row['id']]);
                        $remapNotes[] = 'staff_profile#' . (int)$row['id'];
                    }
                }
            }
        }
    }

    return [
        'ok' => true,
        'message' => ($dry ? 'dry-run: ' : '') . "created={$created} skipped={$skipped}",
        'created' => $created,
        'skipped' => $skipped,
        'dropped' => $dropped,
        'map' => $map,
        'anomalies' => $anomalies,
        'remapped' => $remapNotes,
        'dry_run' => $dry,
    ];
};
