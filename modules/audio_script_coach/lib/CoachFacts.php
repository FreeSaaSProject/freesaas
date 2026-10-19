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
     * @return array<string,mixed>
     */
    public static function build(int $adminId, string $date, array $catalog): array
    {
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
            $parts = is_array($script['parts'] ?? null) ? $script['parts'] : [];

            $hitsByColl = self::collectionPartHits(array_map(static fn ($c) => (int)$c['id'], $collections));
            foreach ($collections as $coll) {
                $cid = (int)$coll['id'];
                $hits = $hitsByColl[$cid] ?? [];
                $chain = [];
                $hasFinish = false;
                $finishHit = false;
                $misses = [];
                foreach ($parts as $part) {
                    $pid = (int)$part['id'];
                    $hit = isset($hits[$pid]);
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
                $isFinished = $hasFinish ? $finishHit : ($hits !== []);
                if ($isFinished) {
                    $finished++;
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

            $scriptsOut[] = [
                'id' => $sid,
                'name' => (string)($script['name'] ?? ''),
                'starts' => $starts,
                'finishes' => $finished,
                'silent' => $silent,
                'trigger_gap' => $triggerGap,
                'weak_start' => $weakStart,
                'trigger_parts' => $triggerParts,
                'incomplete' => $incomplete,
            ];
        }

        return [
            'admin_id' => $adminId,
            'date' => $date,
            'day_start' => $dayStart,
            'day_end' => $dayEnd,
            'record_count' => $recordCount,
            'record_ids' => $recordIds,
            'scripts' => $scriptsOut,
        ];
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
}
