<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** Phase 2: collection starts, trigger health, incomplete session misses. */
final class CoachFacts
{
    /**
     * @param array<string,mixed> $catalog from CoachCatalog::build
     * @param string $depth normal|hard — hard still mines stage fill when the day looks closed
     * @return array<string,mixed>
     */
    public static function build(int $adminId, string $date, array $catalog, string $depth = 'normal'): array
    {
        $depth = CoachRunsRepository::normalizeDepth($depth);
        $hard = $depth === 'hard';
        $bounds = self::dayWindow($date);
        $dayStart = $bounds['start'];
        $dayEnd = $bounds['end'];

        $recordCount = self::countRecords($adminId, $dayStart, $dayEnd);
        $recordIds = self::listRecordIds($adminId, $dayStart, $dayEnd, 40);

        $scriptsOut = [];
        foreach (is_array($catalog['scripts'] ?? null) ? $catalog['scripts'] : [] as $script) {
            $sid = (int)($script['id'] ?? 0);
            if ($sid < 1) {
                continue;
            }
            $collections = self::loadCollections($adminId, $sid, $dayStart, $dayEnd);
            $starts = count($collections);
            $finished = 0;
            $incomplete = [];
            $stageGaps = [];
            $finishedSessions = [];
            $parts = is_array($script['parts'] ?? null) ? $script['parts'] : [];
            $enrichablePartIds = [];
            foreach ($parts as $part) {
                $pid = (int)($part['id'] ?? 0);
                if ($pid < 1 || empty($part['dict_ids'])) {
                    continue;
                }
                if ((string)($part['role'] ?? '') === 'killer' || !empty($part['cond_type'])) {
                    continue;
                }
                $enrichablePartIds[] = $pid;
            }

            $hitsByColl = self::collectionPartHits(array_map(static fn ($c) => (int)$c['id'], $collections));
            foreach ($collections as $coll) {
                $cid = (int)$coll['id'];
                $hits = $hitsByColl[$cid] ?? [];
                $chain = [];
                $hasCloser = false;
                $closerHit = false;
                $hasFinish = false;
                $finishHit = false;
                $misses = [];
                foreach ($parts as $part) {
                    $pid = (int)$part['id'];
                    $hit = isset($hits[$pid]);
                    if (!empty($part['closer'])) {
                        $hasCloser = true;
                        if ($hit) {
                            $closerHit = true;
                        }
                    }
                    if (!empty($part['finish'])) {
                        $hasFinish = true;
                        if ($hit) {
                            $finishHit = true;
                        }
                    }
                    $chain[] = [
                        'part_id' => $pid,
                        'name' => (string)$part['name'],
                        'hit' => $hit,
                        'role' => (string)($part['role'] ?? 'ordinary'),
                    ];
                    if (!$hit && (string)($part['role'] ?? '') !== 'killer' && empty($part['cond_type'])) {
                        // Missed ordinary/trigger/finish part with dicts
                        if (!empty($part['dict_ids'])) {
                            $misses[] = $pid;
                        }
                    }
                }
                // Prefer closer (session end); fall back to conversion FINISH for legacy scripts.
                $isFinished = $hasCloser
                    ? $closerHit
                    : ($hasFinish ? $finishHit : ($hits !== []));
                if ($isFinished) {
                    $finished++;
                    $sessionRow = [
                        'collection_id' => $cid,
                        'dt_start' => (int)$coll['dt_start'],
                        'dt_end' => (int)$coll['dt_end'],
                        'miss_part_ids' => $misses,
                        'chain' => $chain,
                    ];
                    if ($hard && $misses !== []) {
                        // Closed episode still missing mid/late stages — mine those parts.
                        $stageGaps[] = $sessionRow;
                    }
                    if ($hard) {
                        $finishedSessions[] = $sessionRow;
                    }
                } else {
                    $incomplete[] = [
                        'collection_id' => $cid,
                        'dt_start' => (int)$coll['dt_start'],
                        'dt_end' => (int)$coll['dt_end'],
                        'miss_part_ids' => $misses,
                        'chain' => $chain,
                    ];
                }
            }

            $triggerParts = [];
            $triggerGap = false;
            $maxTriggerHits = 0;
            $minTriggerHits = null;
            foreach ($parts as $part) {
                $role = (string)($part['role'] ?? '');
                $startGate = (int)($part['start_gate'] ?? 0);
                if ($role !== 'trigger' && $startGate < 1) {
                    continue;
                }
                $dictIds = is_array($part['dict_ids'] ?? null) ? $part['dict_ids'] : [];
                $instCount = self::countInstancesForDicts($adminId, $dictIds, $dayStart, $dayEnd);
                $triggerParts[] = [
                    'part_id' => (int)$part['id'],
                    'name' => (string)$part['name'],
                    'start_gate' => $startGate,
                    'dict_ids' => array_values(array_map('intval', $dictIds)),
                    'instance_hits' => $instCount,
                ];
                if ($instCount < 1) {
                    $triggerGap = true;
                }
                if ($instCount > $maxTriggerHits) {
                    $maxTriggerHits = $instCount;
                }
                if ($minTriggerHits === null || $instCount < $minTriggerHits) {
                    $minTriggerHits = $instCount;
                }
            }
            // Silent: activity expected but zero collections saved.
            $silent = $recordCount > 0 && $starts === 0;
            if ($silent) {
                $triggerGap = true;
            }
            // Weak start: trigger lemmas fire often, but few collections open
            // (AND-gates / diversity / phrasing of the *other* start part).
            // Example: 80+ hits on each start part, only 2 collections.
            $weakStart = false;
            if ($triggerParts !== [] && $maxTriggerHits >= 10) {
                $floor = $minTriggerHits !== null ? (int)$minTriggerHits : 0;
                if ($starts === 0 && $floor > 0) {
                    $weakStart = true;
                } elseif ($starts > 0 && $floor >= max(10, $starts * 5)) {
                    $weakStart = true;
                }
            }

            // Paid procedures without an overlapping script collection and without
            // start-gate hits nearby (speech may not match dictionary samples).
            $missedStarts = [];
            if ($triggerParts !== []) {
                $missedStarts = self::findMissedStarts(
                    $adminId,
                    $dayStart,
                    $dayEnd,
                    $collections,
                    $triggerParts
                );
                if ($missedStarts !== []) {
                    $triggerGap = true;
                }
            }

            // Hard + fully covered day (no gaps): still force stage enrichment windows
            // so ASR variants can fill each part's dictionaries.
            $forceEnrich = false;
            $forceSessions = [];
            if ($hard && $finishedSessions !== [] && $enrichablePartIds !== []) {
                $hasNormalGap = $silent || $triggerGap || $weakStart
                    || $missedStarts !== [] || $incomplete !== [] || $stageGaps !== [];
                if (!$hasNormalGap) {
                    $forceEnrich = true;
                    $forceSessions = array_slice($finishedSessions, 0, 4);
                }
            }

            $scriptsOut[] = [
                'id' => $sid,
                'name' => (string)($script['name'] ?? ''),
                'starts' => $starts,
                'finishes' => $finished,
                'silent' => $silent,
                'trigger_gap' => $triggerGap,
                'weak_start' => $weakStart,
                'trigger_parts' => $triggerParts,
                'missed_starts' => $missedStarts,
                'incomplete' => $incomplete,
                'stage_gaps' => $stageGaps,
                'force_enrich' => $forceEnrich,
                'force_sessions' => $forceSessions,
                'enrichable_part_ids' => $enrichablePartIds,
            ];
        }

        $qualityPack = self::buildQualityRed($adminId, $date, $catalog, $scriptsOut);

        return [
            'admin_id' => $adminId,
            'date' => $date,
            'depth' => $depth,
            'day_start' => $dayStart,
            'day_end' => $dayEnd,
            'record_count' => $recordCount,
            'record_ids' => $recordIds,
            'scripts' => $scriptsOut,
            'quality_red' => $qualityPack['parts'],
            'quality_bonus_missed' => $qualityPack['bonus_missed'],
        ];
    }

    /**
     * Red scoring day-quality parts with rated sessions that missed them.
     *
     * @param array<string,mixed> $catalog
     * @param list<array<string,mixed>> $scriptsOut
     * @return array{parts:list<array<string,mixed>>,bonus_missed:int}
     */
    private static function buildQualityRed(
        int $adminId,
        string $date,
        array $catalog,
        array $scriptsOut
    ): array {
        $empty = ['parts' => [], 'bonus_missed' => 0];
        if ($adminId < 1 || !class_exists('DayQualityReport', false)) {
            return $empty;
        }
        try {
            $quality = DayQualityReport::build($adminId, $date);
        } catch (Throwable $e) {
            return $empty;
        }
        if (empty($quality['ok']) || empty($quality['enabled'])) {
            return $empty;
        }
        $partsById = [];
        foreach (is_array($catalog['scripts'] ?? null) ? $catalog['scripts'] : [] as $script) {
            $sid = (int)($script['id'] ?? 0);
            foreach (is_array($script['parts'] ?? null) ? $script['parts'] : [] as $part) {
                $pid = (int)($part['id'] ?? 0);
                if ($pid > 0) {
                    $partsById[$pid] = [
                        'script_id' => $sid,
                        'script_name' => (string)($script['name'] ?? ''),
                        'dict_ids' => is_array($part['dict_ids'] ?? null) ? $part['dict_ids'] : [],
                        'name' => (string)($part['name'] ?? ''),
                        'role' => (string)($part['role'] ?? 'ordinary'),
                        'start_gate' => (int)($part['start_gate'] ?? 0),
                    ];
                }
            }
        }

        $collByScript = [];
        foreach ($scriptsOut as $sf) {
            $sid = (int)($sf['id'] ?? 0);
            foreach (['incomplete', 'stage_gaps', 'force_sessions'] as $bucket) {
                foreach (is_array($sf[$bucket] ?? null) ? $sf[$bucket] : [] as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $collByScript[$sid][] = $row;
                }
            }
        }

        $out = [];
        $bonusMissed = (int)($quality['bonus_sum_missed'] ?? 0);
        foreach (is_array($quality['scripts'] ?? null) ? $quality['scripts'] : [] as $qs) {
            if (empty($qs['has_red'])) {
                continue;
            }
            $sid = (int)($qs['script_id'] ?? 0);
            $scriptName = (string)($qs['name'] ?? '');
            foreach (is_array($qs['parts'] ?? null) ? $qs['parts'] : [] as $qp) {
                if (($qp['color'] ?? '') !== 'red' || empty($qp['is_scoring'])) {
                    continue;
                }
                if ((string)($qp['part_kind'] ?? '') === 'day_metric') {
                    continue;
                }
                $pid = (int)($qp['part_id'] ?? 0);
                if ($pid < 1) {
                    continue;
                }
                $meta = $partsById[$pid] ?? [
                    'script_id' => $sid,
                    'script_name' => $scriptName,
                    'dict_ids' => [],
                    'name' => (string)($qp['name'] ?? ''),
                    'role' => 'ordinary',
                    'start_gate' => 0,
                ];
                $missedSessions = self::missedSessionsForPart(
                    $pid,
                    $meta,
                    $collByScript[$sid] ?? []
                );
                if ($missedSessions === []) {
                    $missedSessions = self::loadMissedRatedSessions($adminId, $sid, $pid, $date);
                }
                $out[] = [
                    'script_id' => $sid,
                    'script_name' => $scriptName !== '' ? $scriptName : (string)$meta['script_name'],
                    'part_id' => $pid,
                    'part_name' => (string)($qp['name'] ?? $meta['name']),
                    'numerator' => (int)($qp['numerator'] ?? 0),
                    'denominator' => (int)($qp['denominator'] ?? 0),
                    'percent' => (float)($qp['percent'] ?? 0),
                    'gate_red_max' => (float)($qp['gate_red_max'] ?? 0),
                    'dict_ids' => array_values(array_map('intval', $meta['dict_ids'] ?? [])),
                    'missed_sessions' => $missedSessions,
                ];
            }
        }

        return ['parts' => $out, 'bonus_missed' => $bonusMissed];
    }

    /**
     * @param array<string,mixed> $partMeta
     * @param list<array<string,mixed>> $knownSessions
     * @return list<array<string,mixed>>
     */
    private static function missedSessionsForPart(
        int $partId,
        array $partMeta,
        array $knownSessions
    ): array {
        $out = [];
        foreach ($knownSessions as $sess) {
            $missIds = is_array($sess['miss_part_ids'] ?? null) ? $sess['miss_part_ids'] : [];
            $missSet = [];
            foreach ($missIds as $mid) {
                $missSet[(int)$mid] = true;
            }
            $hit = true;
            if (isset($missSet[$partId])) {
                $hit = false;
            } else {
                // Check chain if present.
                foreach (is_array($sess['chain'] ?? null) ? $sess['chain'] : [] as $chip) {
                    if ((int)($chip['part_id'] ?? 0) === $partId) {
                        $hit = !empty($chip['hit']);
                        break;
                    }
                }
            }
            if ($hit) {
                continue;
            }
            $chain = is_array($sess['chain'] ?? null) ? $sess['chain'] : [];
            // Enrich chain chips with role from part meta when missing.
            foreach ($chain as &$chip) {
                if ((int)($chip['part_id'] ?? 0) === $partId) {
                    $chip['role'] = (string)($partMeta['role'] ?? 'ordinary');
                    $chip['start_gate'] = (int)($partMeta['start_gate'] ?? 0);
                }
            }
            unset($chip);
            $out[] = [
                'collection_id' => (int)($sess['collection_id'] ?? 0),
                'dt_start' => (int)($sess['dt_start'] ?? 0),
                'dt_end' => (int)($sess['dt_end'] ?? 0),
                'chain' => $chain,
                'miss_part_ids' => $missIds,
            ];
            if (count($out) >= 20) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private static function loadMissedRatedSessions(
        int $adminId,
        int $scriptId,
        int $partId,
        string $date
    ): array {
        if ($adminId < 1 || $scriptId < 1 || $partId < 1
            || !Database::tableExists('base_records_collections')
        ) {
            return [];
        }
        $bounds = self::dayWindow($date);
        $ratedCond = Database::columnExists('base_records_collections', 'RATED')
            ? 'AND RATED = 1' : '';
        $st = Database::pdo()->prepare(
            "SELECT ID AS id, DT_START AS dt_start, DT_END AS dt_end
             FROM base_records_collections
             WHERE ID_ADMIN=? AND ID_SCRIPT=? {$ratedCond}
               AND DT_START <= ? AND (DT_END = 0 OR DT_END >= ?)
             ORDER BY DT_START ASC
             LIMIT 40"
        );
        $st->execute([$adminId, $scriptId, $bounds['end'], $bounds['start']]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static fn ($r) => (int)$r['id'], $rows);
        $hits = self::collectionPartHits($ids);
        $out = [];
        foreach ($rows as $row) {
            $cid = (int)$row['id'];
            if (isset($hits[$cid][$partId])) {
                continue;
            }
            $chain = [];
            foreach (($hits[$cid] ?? []) as $pid => $_) {
                $chain[] = ['part_id' => (int)$pid, 'hit' => true, 'role' => 'ordinary'];
            }
            $chain[] = ['part_id' => $partId, 'hit' => false, 'role' => 'ordinary'];
            $out[] = [
                'collection_id' => $cid,
                'dt_start' => (int)$row['dt_start'],
                'dt_end' => (int)$row['dt_end'],
                'chain' => $chain,
                'miss_part_ids' => [$partId],
            ];
        }

        return $out;
    }

    /** @return array{start:int,end:int} */
    public static function dayWindow(string $date): array
    {
        $dayStart = strtotime($date . ' 00:00:00');
        if ($dayStart === false) {
            $dayStart = time();
        }
        $dayEnd = (int)$dayStart + 86399;

        return ['start' => (int)$dayStart, 'end' => $dayEnd];
    }

    private static function countRecords(int $adminId, int $dayStart, int $dayEnd): int
    {
        if ($adminId < 1 || !Database::tableExists('base_records')) {
            return 0;
        }
        $st = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM base_records
             WHERE ID_ADMIN=? AND DT_RECORDED >= ? AND DT_RECORDED <= ?
               AND TRANSCRIPTION IS NOT NULL AND TRANSCRIPTION != \'\''
        );
        $st->execute([$adminId, $dayStart, $dayEnd]);

        return (int)$st->fetchColumn();
    }

    /**
     * @return list<int>
     */
    private static function listRecordIds(int $adminId, int $dayStart, int $dayEnd, int $limit): array
    {
        if ($adminId < 1 || !Database::tableExists('base_records')) {
            return [];
        }
        $limit = max(1, min(100, $limit));
        $st = Database::pdo()->prepare(
            "SELECT ID FROM base_records
             WHERE ID_ADMIN=? AND DT_RECORDED >= ? AND DT_RECORDED <= ?
               AND TRANSCRIPTION IS NOT NULL AND TRANSCRIPTION != ''
             ORDER BY DT_RECORDED ASC
             LIMIT $limit"
        );
        $st->execute([$adminId, $dayStart, $dayEnd]);
        $ids = [];
        while ($id = $st->fetchColumn()) {
            $ids[] = (int)$id;
        }

        return $ids;
    }

    /**
     * @return list<array{id:int,dt_start:int,dt_end:int}>
     */
    private static function loadCollections(int $adminId, int $scriptId, int $dayStart, int $dayEnd): array
    {
        if (!Database::tableExists('base_records_collections')) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, DT_START AS dt_start, DT_END AS dt_end
             FROM base_records_collections
             WHERE ID_ADMIN=? AND ID_SCRIPT=?
               AND DT_START <= ? AND (DT_END = 0 OR DT_END >= ?)
             ORDER BY DT_START ASC'
        );
        $st->execute([$adminId, $scriptId, $dayEnd, $dayStart]);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param list<int> $collectionIds
     * @return array<int, array<int,true>>
     */
    private static function collectionPartHits(array $collectionIds): array
    {
        if ($collectionIds === [] || !Database::tableExists('base_records_collections_datas')) {
            return [];
        }
        $in = implode(',', array_map('intval', $collectionIds));
        $rows = Database::pdo()->query(
            "SELECT ID_COLLECTION, ID_PART FROM base_records_collections_datas
             WHERE ID_COLLECTION IN ($in) AND ID_PART > 0"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $out[(int)$row['ID_COLLECTION']][(int)$row['ID_PART']] = true;
        }

        return $out;
    }

    /**
     * @param list<int> $dictIds
     */
    private static function countInstancesForDicts(int $adminId, array $dictIds, int $dayStart, int $dayEnd): int
    {
        $dictIds = array_values(array_filter(array_map('intval', $dictIds), static fn ($d) => $d > 0));
        if ($dictIds === [] || !Database::tableExists('base_records_instances')
            || !Database::tableExists('base_records_keys')
        ) {
            return 0;
        }
        $in = implode(',', $dictIds);
        $st = Database::pdo()->prepare(
            "SELECT COUNT(*) FROM base_records_instances i
             INNER JOIN base_records_keys k ON k.ID = i.ID_KEY
             WHERE i.ID_ADMIN=? AND k.ID_DICTONARY IN ($in)
               AND i.DT_START >= ? AND i.DT_START <= ?"
        );
        $st->execute([$adminId, $dayStart, $dayEnd]);

        return (int)$st->fetchColumn();
    }

    /**
     * Procedures that look like receptions but never opened a script session
     * because start-gate keys did not fire near the procedure start.
     *
     * @param list<array{id:int,dt_start:int,dt_end:int}> $collections
     * @param list<array<string,mixed>> $triggerParts
     * @return list<array<string,mixed>>
     */
    private static function findMissedStarts(
        int $adminId,
        int $dayStart,
        int $dayEnd,
        array $collections,
        array $triggerParts
    ): array {
        $procedures = self::loadPaidProcedures($adminId, $dayStart, $dayEnd, 360);
        if ($procedures === []) {
            return [];
        }
        $dictIds = [];
        foreach ($triggerParts as $tp) {
            foreach (is_array($tp['dict_ids'] ?? null) ? $tp['dict_ids'] : [] as $did) {
                $did = (int)$did;
                if ($did > 0) {
                    $dictIds[$did] = true;
                }
            }
        }
        $dictIdList = array_keys($dictIds);
        if ($dictIdList === []) {
            return [];
        }

        $buffer = 120;
        $out = [];
        foreach ($procedures as $proc) {
            $ps = (int)$proc['dt_start'];
            $pe = (int)$proc['dt_end'];
            if ($pe < $ps) {
                $pe = $ps;
            }
            $covered = false;
            foreach ($collections as $coll) {
                $cs = (int)$coll['dt_start'] - $buffer;
                $ce = (int)$coll['dt_end'] + $buffer;
                if ($ce < 1) {
                    $ce = $cs;
                }
                if (min($ce, $pe) - max($cs, $ps) > 0) {
                    $covered = true;
                    break;
                }
            }
            if ($covered) {
                continue;
            }
            // Look for greeting window around procedure start (not whole duration).
            $winFrom = $ps - 300;
            $winTo = min($pe, $ps + 900);
            $startHits = self::countInstancesForDicts($adminId, $dictIdList, $winFrom, $winTo);
            if ($startHits > 0) {
                continue;
            }
            $recordIds = self::listRecordIdsInRange($adminId, $winFrom, $winTo, 4);
            if ($recordIds === []) {
                // Fall back to any record overlapping the procedure body.
                $recordIds = self::listRecordIdsInRange($adminId, $ps, $pe, 3);
            }
            if ($recordIds === []) {
                continue;
            }
            $out[] = [
                'procedure_id' => (int)$proc['id'],
                'procedure_name' => (string)$proc['name'],
                'dt_start' => $ps,
                'dt_end' => $pe,
                'record_ids' => $recordIds,
                'trigger_hits_near' => 0,
            ];
            if (count($out) >= 12) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return list<array{id:int,dt_start:int,dt_end:int,name:string}>
     */
    private static function loadPaidProcedures(int $adminId, int $dayStart, int $dayEnd, int $minSec): array
    {
        if ($adminId < 1
            || !class_exists('ModuleManager', false)
            || !ModuleManager::isEnabled('procedures')
            || !Database::tableExists('base_procedures')
        ) {
            return [];
        }
        $opCol = Database::columnExists('base_procedures', 'ID_OPERATOR')
            ? 'ID_OPERATOR' : 'ID_ADMIN';
        $joins = '';
        $nameExpr = 'NULL';
        if (Database::tableExists('base_products') && Database::columnExists('base_procedures', 'ID_GOOD')) {
            $joins .= ' LEFT JOIN base_products g ON g.id = p.ID_GOOD';
            $nameExpr = "NULLIF(TRIM(g.name), '')";
        }
        if (Database::tableExists('base_procedure_types') && Database::columnExists('base_procedures', 'TYPE')) {
            $joins .= ' LEFT JOIN base_procedure_types t ON t.ID = p.TYPE';
            $nameExpr = "COALESCE({$nameExpr}, NULLIF(TRIM(t.TYPE), ''))";
        }
        $st = Database::pdo()->prepare(
            "SELECT p.ID AS id, p.DTSTART AS dt_start, p.DTEND AS dt_end,
                    COALESCE({$nameExpr}, CONCAT('#', p.ID)) AS name
             FROM base_procedures p
             {$joins}
             WHERE p.{$opCol} = ? AND p.ENABLED = 1 AND p.SUMM > 0
               AND p.DTSTART <= ? AND (p.DTEND = 0 OR p.DTEND >= ?)
             ORDER BY p.DTSTART ASC"
        );
        $st->execute([$adminId, $dayEnd, $dayStart]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $ps = (int)$row['dt_start'];
            $pe = (int)$row['dt_end'];
            if ($pe < 1) {
                $pe = $ps;
            }
            if ($minSec > 0 && ($pe - $ps) < $minSec) {
                continue;
            }
            $name = trim((string)($row['name'] ?? ''));
            $out[] = [
                'id' => (int)$row['id'],
                'dt_start' => $ps,
                'dt_end' => $pe,
                'name' => $name !== '' ? $name : ('#' . (int)$row['id']),
            ];
        }

        return $out;
    }

    /**
     * @return list<int>
     */
    private static function listRecordIdsInRange(int $adminId, int $fromTs, int $toTs, int $limit): array
    {
        if ($adminId < 1 || !Database::tableExists('base_records') || $toTs < $fromTs) {
            return [];
        }
        $limit = max(1, min(20, $limit));
        $st = Database::pdo()->prepare(
            "SELECT ID FROM base_records
             WHERE ID_ADMIN=? AND DT_RECORDED <= ? AND (DT_RECORDED + COALESCE(PLAYTIME, 600)) >= ?
               AND TRANSCRIPTION IS NOT NULL AND TRANSCRIPTION != ''
             ORDER BY DT_RECORDED ASC
             LIMIT $limit"
        );
        $st->execute([$adminId, $toTs, $fromTs]);
        $ids = [];
        while ($id = $st->fetchColumn()) {
            $ids[] = (int)$id;
        }

        return $ids;
    }
}
