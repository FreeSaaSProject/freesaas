<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Light what-if: if proposed phrases existed, how many missed red sessions would flip.
 * Does not run full stem/collect (v1).
 */
final class CoachWhatIf
{
    /**
     * @param list<array{part_id:int,phrase:string,dict_id?:int}> $pending
     * @param array<string,mixed> $facts
     * @return array<string,mixed>
     */
    public static function simulate(int $adminId, string $date, array $pending, array $facts): array
    {
        $out = [
            'ok' => true,
            'parts' => [],
            'bonus_before' => 0,
            'bonus_after_est' => 0,
            'sessions_flipped' => 0,
            'note' => 'light',
        ];
        $red = is_array($facts['quality_red'] ?? null) ? $facts['quality_red'] : [];
        if ($red === [] || $pending === []) {
            $out['ok'] = false;
            $out['note'] = 'nothing';

            return $out;
        }

        $byPart = [];
        foreach ($pending as $row) {
            $pid = (int)($row['part_id'] ?? 0);
            $ph = trim((string)($row['phrase'] ?? ''));
            if ($pid < 1 || $ph === '') {
                continue;
            }
            $tokens = class_exists('KeyStemConflict', false)
                ? KeyStemConflict::tokenizeFast($ph)
                : self::fallbackTokens($ph);
            if ($tokens === []) {
                continue;
            }
            $byPart[$pid][] = [
                'phrase' => $ph,
                'tokens' => $tokens,
                'dict_id' => (int)($row['dict_id'] ?? 0),
            ];
        }
        if ($byPart === []) {
            $out['ok'] = false;
            $out['note'] = 'no_phrases';

            return $out;
        }

        // Baseline bonus from DayQuality when available.
        $bonusBefore = (int)($facts['quality_bonus_missed'] ?? 0);
        $bonusPotential = 0;
        if (class_exists('DayQualityReport', false)) {
            try {
                $q = DayQualityReport::build($adminId, $date);
                if (!empty($q['ok'])) {
                    $bonusBefore = max(0, (int)($q['bonus_sum_missed'] ?? $bonusBefore));
                    $bonusPotential = (int)($q['bonus_sum_potential'] ?? 0);
                    $out['bonus_eligible_now'] = (int)($q['bonus_sum'] ?? 0);
                }
            } catch (Throwable $e) {
                // keep facts values
            }
        }
        $out['bonus_before_missed'] = $bonusBefore;

        $flippedTotal = 0;
        $partsOut = [];
        foreach ($red as $rp) {
            if (!is_array($rp)) {
                continue;
            }
            $pid = (int)($rp['part_id'] ?? 0);
            $cands = $byPart[$pid] ?? [];
            if ($cands === []) {
                continue;
            }
            $num = (int)($rp['numerator'] ?? 0);
            $den = (int)($rp['denominator'] ?? 0);
            $redMax = (float)($rp['gate_red_max'] ?? 0);
            $yellowMax = (float)($rp['gate_yellow_max'] ?? 0);
            $missed = is_array($rp['missed_sessions'] ?? null) ? $rp['missed_sessions'] : [];
            $flip = 0;
            foreach ($missed as $sess) {
                if (!is_array($sess)) {
                    continue;
                }
                if (self::sessionWouldHit($adminId, $sess, $cands)) {
                    $flip++;
                }
            }
            $numAfter = $num + $flip;
            $pctBefore = $den > 0 ? round(100.0 * $num / $den, 1) : 0.0;
            $pctAfter = $den > 0 ? round(100.0 * $numAfter / $den, 1) : 0.0;
            $colorBefore = self::color($pctBefore, $redMax, $yellowMax);
            $colorAfter = self::color($pctAfter, $redMax, $yellowMax);
            $flippedTotal += $flip;
            $partsOut[] = [
                'part_id' => $pid,
                'part_name' => (string)($rp['part_name'] ?? ''),
                'script_id' => (int)($rp['script_id'] ?? 0),
                'numerator_before' => $num,
                'numerator_after' => $numAfter,
                'denominator' => $den,
                'percent_before' => $pctBefore,
                'percent_after' => $pctAfter,
                'color_before' => $colorBefore,
                'color_after' => $colorAfter,
                'sessions_flipped' => $flip,
            ];
        }

        $out['parts'] = $partsOut;
        $out['sessions_flipped'] = $flippedTotal;
        // Rough bonus: if all red parts on a script become non-red, that script's bonus returns.
        // Light estimate: any part that leaves red reduces missed proportionally when all reds clear.
        $stillRed = 0;
        foreach ($partsOut as $p) {
            if (($p['color_after'] ?? '') === 'red') {
                $stillRed++;
            }
        }
        $redBefore = count($partsOut);
        if ($redBefore > 0 && $stillRed === 0 && $bonusBefore > 0) {
            $out['bonus_after_est'] = $out['bonus_eligible_now'] ?? 0;
            $out['bonus_missed_after_est'] = 0;
            $out['bonus_delta_est'] = $bonusBefore;
        } else {
            $out['bonus_missed_after_est'] = $bonusBefore;
            $out['bonus_delta_est'] = 0;
            $out['bonus_after_est'] = $out['bonus_eligible_now'] ?? 0;
        }
        $out['bonus_potential'] = $bonusPotential;

        return $out;
    }

    /**
     * @param array<string,mixed> $sess
     * @param list<array{phrase:string,tokens:list<string>,dict_id:int}> $cands
     */
    private static function sessionWouldHit(int $adminId, array $sess, array $cands): bool
    {
        $dtStart = (int)($sess['dt_start'] ?? 0);
        $dtEnd = (int)($sess['dt_end'] ?? 0);
        if ($dtEnd < 1) {
            $dtEnd = $dtStart + 600;
        }
        $lines = self::linesAround($adminId, $dtStart - 30, $dtEnd + 30);
        foreach ($lines as $line) {
            $lt = class_exists('KeyStemConflict', false)
                ? KeyStemConflict::tokenizeFast($line)
                : self::fallbackTokens($line);
            if ($lt === []) {
                continue;
            }
            foreach ($cands as $c) {
                $st = $c['tokens'];
                $ns = count($st);
                if ($ns < 1) {
                    continue;
                }
                // Contiguous match of candidate tokens in line (stem window style).
                if (count($lt) >= $ns) {
                    for ($i = 0; $i <= count($lt) - $ns; $i++) {
                        $ok = true;
                        for ($k = 0; $k < $ns; $k++) {
                            if ($lt[$i + $k] !== $st[$k]) {
                                $ok = false;
                                break;
                            }
                        }
                        if ($ok) {
                            // Skip if stem conflict would block in real life.
                            $dictId = (int)($c['dict_id'] ?? 0);
                            if ($dictId > 0 && class_exists('KeyStemConflict', false)
                                && KeyStemConflict::hasConflicts((string)$c['phrase'], $dictId)
                            ) {
                                continue;
                            }

                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    private static function color(float $pct, float $redMax, float $yellowMax): string
    {
        if ($redMax <= 0.0 && $yellowMax <= 0.0) {
            return 'none';
        }
        if ($pct < $redMax) {
            return 'red';
        }
        if ($pct <= $yellowMax) {
            return 'yellow';
        }

        return 'green';
    }

    /** @return list<string> */
    private static function linesAround(int $adminId, int $fromTs, int $toTs): array
    {
        if ($adminId < 1 || !Database::tableExists('base_records') || $toTs < $fromTs) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT ID FROM base_records
             WHERE ID_ADMIN=? AND DT_RECORDED <= ? AND (DT_RECORDED + COALESCE(PLAYTIME, 600)) >= ?
               AND TRANSCRIPTION IS NOT NULL AND TRANSCRIPTION != \'\'
             ORDER BY DT_RECORDED ASC LIMIT 5'
        );
        $st->execute([$adminId, $toTs, $fromTs]);
        $lines = [];
        while ($id = $st->fetchColumn()) {
            foreach (self::parseLines((int)$id) as $line) {
                $lines[] = $line;
                if (count($lines) >= 40) {
                    return $lines;
                }
            }
        }

        return $lines;
    }

    /** @return list<string> */
    private static function parseLines(int $recordId): array
    {
        $st = Database::pdo()->prepare('SELECT TRANSCRIPTION FROM base_records WHERE ID=? LIMIT 1');
        $st->execute([$recordId]);
        $src = (string)$st->fetchColumn();
        if ($src === '' || strpos($src, '[') === false) {
            return [];
        }
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
            $prev = $text;
            $lines[] = $text;
            if (count($lines) >= 40) {
                break;
            }
        }

        return $lines;
    }

    /** @return list<string> */
    private static function fallbackTokens(string $phrase): array
    {
        $norm = class_exists('RecordsKeysRepository', false)
            ? RecordsKeysRepository::normalizeName($phrase)
            : mb_strtolower(trim($phrase), 'UTF-8');
        if ($norm === '') {
            return [];
        }
        $out = [];
        foreach (preg_split('/\s+/u', $norm) ?: [] as $p) {
            $p = trim((string)$p);
            if ($p !== '') {
                $out[] = $p;
            }
        }

        return $out;
    }
}
