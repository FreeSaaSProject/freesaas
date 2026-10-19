<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** Phase 3: compact transcript windows for LLM. */
final class CoachWindows
{
    private const MAX_LINES_PER_WINDOW = 40;
    private const MAX_WINDOWS = 12;
    private const HEAD_LINES = 25;

    /**
     * @param array<string,mixed> $catalog
     * @param array<string,mixed> $facts
     * @return array{windows:list<array<string,mixed>>}
     */
    public static function build(array $catalog, array $facts): array
    {
        $windows = [];
        $recordIds = is_array($facts['record_ids'] ?? null) ? $facts['record_ids'] : [];
        $scriptsById = [];
        foreach (is_array($catalog['scripts'] ?? null) ? $catalog['scripts'] : [] as $s) {
            $scriptsById[(int)$s['id']] = $s;
        }

        foreach (is_array($facts['scripts'] ?? null) ? $facts['scripts'] : [] as $sf) {
            $sid = (int)($sf['id'] ?? 0);
            if ($sid < 1) {
                continue;
            }
            if (!empty($sf['silent']) || !empty($sf['trigger_gap']) || !empty($sf['weak_start'])) {
                // Head of day records — missing / incomplete start phrasing
                $kind = !empty($sf['weak_start']) ? 'weak_start' : 'trigger_gap';
                $n = 0;
                foreach ($recordIds as $rid) {
                    if (count($windows) >= self::MAX_WINDOWS) {
                        break;
                    }
                    $rid = (int)$rid;
                    $lines = self::recordHeadLines($rid, self::HEAD_LINES);
                    if ($lines === []) {
                        continue;
                    }
                    $windows[] = [
                        'kind' => $kind,
                        'script_id' => $sid,
                        'script_name' => (string)($sf['name'] ?? ''),
                        'record_id' => $rid,
                        'part_ids' => array_map(
                            static fn ($tp) => (int)($tp['part_id'] ?? 0),
                            is_array($sf['trigger_parts'] ?? null) ? $sf['trigger_parts'] : []
                        ),
                        'lines' => $lines,
                        'hint' => $kind === 'weak_start'
                            ? 'Trigger dict keys already hit often, but few script collections started. Propose synonyms so BOTH start-gate parts can meet diversity/min in the same talk.'
                            : 'Start-gate phrases may be missing from speech.',
                    ];
                    $n++;
                    if ($n >= 4) {
                        break;
                    }
                }
            }

            foreach (is_array($sf['incomplete'] ?? null) ? $sf['incomplete'] : [] as $inc) {
                if (count($windows) >= self::MAX_WINDOWS) {
                    break 2;
                }
                $missIds = is_array($inc['miss_part_ids'] ?? null) ? $inc['miss_part_ids'] : [];
                if ($missIds === []) {
                    continue;
                }
                $dtStart = (int)($inc['dt_start'] ?? 0);
                $dtEnd = (int)($inc['dt_end'] ?? 0);
                if ($dtEnd < 1) {
                    $dtEnd = $dtStart + 600;
                }
                $lines = self::linesAroundTime(
                    (int)($facts['admin_id'] ?? 0),
                    $dtStart - 30,
                    $dtEnd + 30
                );
                if ($lines === []) {
                    continue;
                }
                $windows[] = [
                    'kind' => 'incomplete',
                    'script_id' => $sid,
                    'script_name' => (string)($sf['name'] ?? ''),
                    'collection_id' => (int)($inc['collection_id'] ?? 0),
                    'part_ids' => array_values(array_map('intval', $missIds)),
                    'lines' => $lines,
                ];
            }
        }

        return ['windows' => $windows];
    }

    /**
     * @return list<string>
     */
    private static function recordHeadLines(int $recordId, int $limit): array
    {
        $all = self::parseTranscriptLines($recordId);
        if ($all === []) {
            return [];
        }

        return array_slice($all, 0, max(1, $limit));
    }

    /**
     * @return list<string>
     */
    private static function linesAroundTime(int $adminId, int $fromTs, int $toTs): array
    {
        if ($adminId < 1 || !Database::tableExists('base_records')) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT ID, DT_RECORDED FROM base_records
             WHERE ID_ADMIN=? AND DT_RECORDED <= ? AND (DT_RECORDED + COALESCE(PLAYTIME, 600)) >= ?
               AND TRANSCRIPTION IS NOT NULL AND TRANSCRIPTION != \'\'
             ORDER BY DT_RECORDED ASC LIMIT 5'
        );
        $st->execute([$adminId, $toTs, $fromTs]);
        $lines = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            foreach (self::parseTranscriptLines((int)$row['ID']) as $line) {
                $lines[] = $line;
                if (count($lines) >= self::MAX_LINES_PER_WINDOW) {
                    return $lines;
                }
            }
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private static function parseTranscriptLines(int $recordId): array
    {
        if ($recordId < 1 || !Database::tableExists('base_records')) {
            return [];
        }
        $st = Database::pdo()->prepare('SELECT TRANSCRIPTION FROM base_records WHERE ID=? LIMIT 1');
        $st->execute([$recordId]);
        $src = (string)$st->fetchColumn();
        if ($src === '' || strpos($src, '[') === false) {
            return [];
        }
        $trash = self::trashNeedles();
        $parts = explode('[', $src);
        array_shift($parts);
        $lines = [];
        $prev = '';
        foreach ($parts as $chunk) {
            if (strpos($chunk, ']') === false) {
                continue;
            }
            $pieces = explode(']', $chunk, 2);
            $text = rtrim($pieces[1] ?? '', "\r\n");
            if ($text === '' || $text === $prev) {
                continue;
            }
            if (mb_strlen($text, 'UTF-8') > 300) {
                continue;
            }
            $skip = false;
            foreach ($trash as $needle) {
                if ($needle !== '' && mb_strpos($text, $needle, 0, 'UTF-8') !== false) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }
            $prev = $text;
            $lines[] = $text;
            if (count($lines) >= self::MAX_LINES_PER_WINDOW * 2) {
                break;
            }
        }

        return $lines;
    }

    /** @return list<string> */
    private static function trashNeedles(): array
    {
        if (!Database::tableExists('base_records_trashes')) {
            return [];
        }
        $enabledCol = Database::columnExists('base_records_trashes', 'ENABLED') ? 'ENABLED' : null;
        $sql = 'SELECT NAME FROM base_records_trashes';
        if ($enabledCol !== null) {
            $sql .= ' WHERE ENABLED > 0';
        }
        $sql .= ' LIMIT 500';
        $out = [];
        foreach (Database::pdo()->query($sql)->fetchAll(PDO::FETCH_COLUMN) ?: [] as $name) {
            $name = trim((string)$name);
            if ($name !== '') {
                $out[] = $name;
            }
        }

        return $out;
    }
}
