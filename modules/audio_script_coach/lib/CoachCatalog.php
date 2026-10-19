<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** Phase 1: scripts + parts + dict samples for staff position. */
final class CoachCatalog
{
    private const SAMPLE_KEYS = 30;

    /**
     * @return array{
     *   admin_id:int,position_ids:list<int>,scripts:list<array<string,mixed>>,
     *   dicts:array<string,array<string,mixed>>
     * }
     */
    public static function build(int $adminId): array
    {
        $positionIds = class_exists('RecordsDictsRepository', false)
            ? RecordsDictsRepository::adminAllowedPositionIds($adminId)
            : [];
        $scripts = [];
        $dictIdsNeeded = [];

        if (class_exists('DayQualityReport', false)) {
            $roots = DayQualityReport::loadScriptsForAdmin($adminId);
        } else {
            $roots = self::loadScriptsFallback($adminId, $positionIds);
        }

        foreach ($roots as $root) {
            $sid = (int)($root['id'] ?? 0);
            if ($sid < 1) {
                continue;
            }
            $tree = class_exists('RecordsScriptsRepository', false)
                ? RecordsScriptsRepository::findWithParts($sid)
                : null;
            $partsOut = [];
            $parts = is_array($tree['parts'] ?? null) ? $tree['parts'] : [];
            foreach ($parts as $part) {
                if (!empty($part['disabled'])) {
                    continue;
                }
                $dictIds = is_array($part['dict_ids'] ?? null) ? $part['dict_ids'] : [];
                $dictIds = array_values(array_filter(array_map('intval', $dictIds), static fn ($d) => $d > 0));
                foreach ($dictIds as $did) {
                    $dictIdsNeeded[$did] = true;
                }
                $startGate = max(0, min(2, (int)($part['start_gate'] ?? 0)));
                $role = 'ordinary';
                if ($startGate > 0 || !empty($part['is_req']) || !empty($part['break_flag'])) {
                    $role = 'trigger';
                } elseif (!empty($part['finish'])) {
                    $role = 'finish';
                } elseif (!empty($part['collection_killer'])) {
                    $role = 'killer';
                }
                $partsOut[] = [
                    'id' => (int)$part['id'],
                    'name' => (string)$part['name'],
                    'role' => $role,
                    'start_gate' => $startGate,
                    'finish' => !empty($part['finish']) ? 1 : 0,
                    'break' => !empty($part['break_flag']) ? 1 : 0,
                    'killer' => !empty($part['collection_killer']) ? 1 : 0,
                    'dict_ids' => $dictIds,
                    'diversity' => (int)($part['diversity'] ?? 0),
                    'instances_min' => (int)($part['instances_min'] ?? 0),
                    'cond_type' => (string)($part['cond_type'] ?? ''),
                ];
            }
            $scripts[] = [
                'id' => $sid,
                'name' => (string)($root['name'] ?? ('#' . $sid)),
                'parts' => $partsOut,
            ];
        }

        $dicts = self::loadDictSamples(array_keys($dictIdsNeeded));

        return [
            'admin_id' => $adminId,
            'position_ids' => array_values(array_map('intval', $positionIds)),
            'scripts' => $scripts,
            'dicts' => $dicts,
        ];
    }

    /**
     * @param list<int> $positionIds
     * @return list<array<string,mixed>>
     */
    private static function loadScriptsFallback(int $adminId, array $positionIds): array
    {
        if (!Database::tableExists('base_records_scripts')) {
            return [];
        }
        $hasRating = Database::columnExists('base_records_scripts', 'RATING_ENABLED');
        $sql = 'SELECT ID AS id, NAME AS name, ID_POSITION AS id_position
                FROM base_records_scripts
                WHERE PID = 0 AND DISABLED < 1';
        if ($hasRating) {
            $sql .= ' AND RATING_ENABLED = 1';
        }
        $sql .= ' ORDER BY NUM ASC, ID ASC';
        $out = [];
        foreach (Database::pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $posRaw = (string)($row['id_position'] ?? '');
            if ($posRaw !== '' && $posRaw !== '0' && $positionIds !== []) {
                $ok = false;
                foreach ($positionIds as $pid) {
                    $pid = (int)$pid;
                    if ($pid > 0 && ($posRaw === (string)$pid || strpos($posRaw, '#' . $pid . '#') !== false)) {
                        $ok = true;
                        break;
                    }
                }
                if (!$ok) {
                    continue;
                }
            }
            $out[] = ['id' => (int)$row['id'], 'name' => (string)$row['name']];
        }

        return $out;
    }

    /**
     * @param list<int> $dictIds
     * @return array<string,array{id:int,name:string,sample_keys:list<string>,key_count:int}>
     */
    private static function loadDictSamples(array $dictIds): array
    {
        $out = [];
        $dictIds = array_values(array_filter(array_map('intval', $dictIds), static fn ($d) => $d > 0));
        if ($dictIds === [] || !Database::tableExists('base_records_dictonarys')) {
            return $out;
        }
        $in = implode(',', $dictIds);
        $rows = Database::pdo()->query(
            "SELECT ID AS id, NAME AS name FROM base_records_dictonarys WHERE ID IN ($in)"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $did = (int)$row['id'];
            $samples = [];
            $count = 0;
            if (Database::tableExists('base_records_keys')) {
                $stc = Database::pdo()->prepare(
                    'SELECT COUNT(*) FROM base_records_keys WHERE ID_DICTONARY=? AND DISABLED<1'
                );
                $stc->execute([$did]);
                $count = (int)$stc->fetchColumn();
                $st = Database::pdo()->prepare(
                    'SELECT NAME FROM base_records_keys
                     WHERE ID_DICTONARY=? AND DISABLED<1
                     ORDER BY ID ASC LIMIT ' . self::SAMPLE_KEYS
                );
                $st->execute([$did]);
                while ($name = $st->fetchColumn()) {
                    $samples[] = (string)$name;
                }
            }
            $out[(string)$did] = [
                'id' => $did,
                'name' => (string)$row['name'],
                'sample_keys' => $samples,
                'key_count' => $count,
            ];
        }

        return $out;
    }
}
