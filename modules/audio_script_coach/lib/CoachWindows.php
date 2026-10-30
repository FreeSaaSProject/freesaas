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
     * @param array<string,mixed>|null $verdicts from CoachVerdicts::build
     * @return array{windows:list<array<string,mixed>>}
     */
    public static function build(array $catalog, array $facts, ?array $verdicts = null): array
    {
        $windows = [];
        // Prefer semantic leftovers from verdicts (LLM only for those).
        if (is_array($verdicts)) {
            foreach (is_array($verdicts['semantic_windows'] ?? null) ? $verdicts['semantic_windows'] : [] as $sw) {
                if (!is_array($sw) || empty($sw['lines'])) {
                    continue;
                }
                $windows[] = $sw;
                if (count($windows) >= self::MAX_WINDOWS) {
                    return ['windows' => $windows];
                }
            }
            // When quality_red drove the run, do not flood with legacy trigger windows.
            $hasRed = is_array($facts['quality_red'] ?? null) && $facts['quality_red'] !== [];
            if ($hasRed) {
                return ['windows' => $windows];
            }
        }

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
            $triggerPartIds = array_map(
                static fn ($tp) => (int)($tp['part_id'] ?? 0),
                is_array($sf['trigger_parts'] ?? null) ? $sf['trigger_parts'] : []
            );

            // Prefer evidence-rich session windows first (incomplete / stage_gaps),
            // then procedure missed_starts, then generic day-head trigger fillers.
            // Old order filled MAX_WINDOWS with trigger_gap and starved stage_gaps.
            $missedUsed = [];
            $hasSessionWork = (is_array($sf['incomplete'] ?? null) && $sf['incomplete'] !== [])
                || (is_array($sf['stage_gaps'] ?? null) && $sf['stage_gaps'] !== [])
                || !empty($sf['force_enrich']);

            foreach (is_array($sf['incomplete'] ?? null) ? $sf['incomplete'] : [] as $inc) {
                if (count($windows) >= self::MAX_WINDOWS) {
                    break 2;
                }
                $missIds = is_array($inc['miss_part_ids'] ?? null) ? $inc['miss_part_ids'] : [];
                if ($missIds === []) {
                    continue;
                }
                $sessionWin = self::sessionWindow(
                    (int)($facts['admin_id'] ?? 0),
                    $inc,
                    $sid,
                    (string)($sf['name'] ?? ''),
                    'incomplete',
                    array_values(array_map('intval', $missIds)),
                    ''
                );
                if ($sessionWin !== null) {
                    $windows[] = $sessionWin;
                }
            }

            // Hard: finished episodes that still miss mid/late stages.
            foreach (is_array($sf['stage_gaps'] ?? null) ? $sf['stage_gaps'] : [] as $gap) {
                if (count($windows) >= self::MAX_WINDOWS) {
                    break 2;
                }
                $missIds = is_array($gap['miss_part_ids'] ?? null) ? $gap['miss_part_ids'] : [];
                if ($missIds === []) {
                    continue;
                }
                $sessionWin = self::sessionWindow(
                    (int)($facts['admin_id'] ?? 0),
                    $gap,
                    $sid,
                    (string)($sf['name'] ?? ''),
                    'stage_gap',
                    array_values(array_map('intval', $missIds)),
                    'Finished session still missing these stages. Mine ASR forms that match '
                        . 'sample_keys for the missed parts.'
                );
                if ($sessionWin !== null) {
                    $windows[] = $sessionWin;
                }
            }

            // Hard + fully covered day: still sample finished sessions for every enrichable part.
            if (!empty($sf['force_enrich'])) {
                $allPartIds = is_array($sf['enrichable_part_ids'] ?? null)
                    ? array_values(array_map('intval', $sf['enrichable_part_ids']))
                    : [];
                $nForce = 0;
                foreach (is_array($sf['force_sessions'] ?? null) ? $sf['force_sessions'] : [] as $fs) {
                    if (count($windows) >= self::MAX_WINDOWS || $nForce >= 4) {
                        break;
                    }
                    if ($allPartIds === []) {
                        break;
                    }
                    $sessionWin = self::sessionWindow(
                        (int)($facts['admin_id'] ?? 0),
                        $fs,
                        $sid,
                        (string)($sf['name'] ?? ''),
                        'stage_enrich',
                        $allPartIds,
                        'Day looks covered (starts/finishes OK). Still look for ASR variants '
                            . 'that could strengthen each stage dictionary — compare lines to sample_keys.'
                    );
                    if ($sessionWin === null) {
                        continue;
                    }
                    $windows[] = $sessionWin;
                    $nForce++;
                }
            }

            // Procedure gaps without start-gate hits (likely ASR ≠ dictionary samples).
            foreach (is_array($sf['missed_starts'] ?? null) ? $sf['missed_starts'] : [] as $ms) {
                if (count($windows) >= self::MAX_WINDOWS) {
                    break;
                }
                $msRecords = is_array($ms['record_ids'] ?? null) ? $ms['record_ids'] : [];
                $added = 0;
                foreach ($msRecords as $rid) {
                    if (count($windows) >= self::MAX_WINDOWS || $added >= 2) {
                        break;
                    }
                    $rid = (int)$rid;
                    if ($rid < 1 || isset($missedUsed[$rid])) {
                        continue;
                    }
                    $lines = self::recordHeadLines($rid, self::HEAD_LINES);
                    if ($lines === []) {
                        continue;
                    }
                    $missedUsed[$rid] = true;
                    $added++;
                    $windows[] = [
                        'kind' => 'missed_start',
                        'script_id' => $sid,
                        'script_name' => (string)($sf['name'] ?? ''),
                        'record_id' => $rid,
                        'procedure_id' => (int)($ms['procedure_id'] ?? 0),
                        'procedure_name' => (string)($ms['procedure_name'] ?? ''),
                        'part_ids' => $triggerPartIds,
                        'lines' => $lines,
                        'hint' => 'Paid procedure without a script session: no start-gate hits near the start. '
                            . 'Compare these lines to trigger dictionary sample_keys; propose ASR forms '
                            . 'that match sample intent but were not detected.',
                    ];
                }
            }

            $needGenericStart = !empty($sf['silent']) || !empty($sf['weak_start'])
                || (!empty($sf['trigger_gap']) && $missedUsed === []);
            if ($needGenericStart) {
                // Head of day records — missing / incomplete start phrasing.
                // Cap lower when we already have session windows so soft stage work fits.
                $kind = !empty($sf['weak_start']) ? 'weak_start' : 'trigger_gap';
                $startCap = $hasSessionWork ? 2 : 4;
                $n = 0;
                foreach ($recordIds as $rid) {
                    if (count($windows) >= self::MAX_WINDOWS) {
                        break;
                    }
                    $rid = (int)$rid;
                    if (isset($missedUsed[$rid])) {
                        continue;
                    }
                    $lines = self::recordHeadLines($rid, self::HEAD_LINES);
                    if ($lines === []) {
                        continue;
                    }
                    $windows[] = [
                        'kind' => $kind,
                        'script_id' => $sid,
                        'script_name' => (string)($sf['name'] ?? ''),
                        'record_id' => $rid,
                        'part_ids' => $triggerPartIds,
                        'lines' => $lines,
                        'hint' => $kind === 'weak_start'
                            ? 'Trigger dict keys already hit often, but few script collections started. Propose synonyms so BOTH start-gate parts can meet diversity/min in the same talk.'
                            : 'Start-gate phrases may be missing from speech.',
                    ];
                    $n++;
                    if ($n >= $startCap) {
                        break;
                    }
                }
            }
        }

        return ['windows' => $windows];
    }

    /**
     * @param array<string,mixed> $session
     * @param list<int> $partIds
     * @return array<string,mixed>|null
     */
    private static function sessionWindow(
        int $adminId,
        array $session,
        int $scriptId,
        string $scriptName,
        string $kind,
        array $partIds,
        string $hint
    ): ?array {
        if ($partIds === []) {
            return null;
        }
        $dtStart = (int)($session['dt_start'] ?? 0);
        $dtEnd = (int)($session['dt_end'] ?? 0);
        if ($dtEnd < 1) {
            $dtEnd = $dtStart + 600;
        }
        $lines = self::linesAroundTime($adminId, $dtStart - 30, $dtEnd + 30);
        if ($lines === []) {
            return null;
        }
        $out = [
            'kind' => $kind,
            'script_id' => $scriptId,
            'script_name' => $scriptName,
            'collection_id' => (int)($session['collection_id'] ?? 0),
            'part_ids' => $partIds,
            'lines' => $lines,
        ];
        if ($hint !== '') {
            $out['hint'] = $hint;
        }

        return $out;
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
