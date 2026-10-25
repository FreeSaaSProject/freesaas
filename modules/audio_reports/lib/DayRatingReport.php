<?php
declare(strict_types=1);

/**
 * Staff day rating report from rated script collections (port of oper getReport).
 */
final class DayRatingReport
{
    private const TEAM_DAYS = 10;
    private const LEVEL_PCT = 0.09;

    /**
     * @return array{
     *   ok:bool,admin_id:int,date:string,admin_name:string,position_id:int,
     *   header:array<string,mixed>,rows:list<array<string,mixed>>,
     *   sessions:list<array<string,mixed>>,sessions_summary:array{finished:int,interrupted:int},
     *   procedures:array<string,mixed>|null,
     *   gated:list<array<string,mixed>>,total:array<string,mixed>,
     *   team:array<string,mixed>,summary:list<string>,sections_html:string
     * }
     */
    public static function build(int $adminId, string $date): array
    {
        $empty = [
            'ok' => false,
            'admin_id' => $adminId,
            'date' => $date,
            'admin_name' => '',
            'position_id' => 0,
            'header' => [],
            'rows' => [],
            'sessions' => [],
            'sessions_summary' => ['finished' => 0, 'interrupted' => 0],
            'procedures' => null,
            'quality' => null,
            'gated' => [],
            'total' => ['score' => null, 'score_w' => 0.0, 'score_sec' => 0],
            'team' => ['avg_score' => null],
            'summary' => [],
            'sections_html' => '',
        ];
        if ($adminId < 1 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $empty;
        }
        $dayStart = strtotime($date . ' 00:00:00');
        if ($dayStart === false) {
            return $empty;
        }
        $dayEnd = $dayStart + 86400 - 1;
        $bounds = AudioDayBounds::read();

        $adminName = '#' . $adminId;
        $positionId = 0;
        if (Database::tableExists('base_users')) {
            $st = Database::pdo()->prepare('SELECT name, login, ID_STATUS FROM base_users WHERE id = ? LIMIT 1');
            $st->execute([$adminId]);
            $u = $st->fetch(PDO::FETCH_ASSOC);
            if ($u) {
                $label = trim((string)($u['name'] ?? ''));
                if ($label === '') {
                    $label = trim((string)($u['login'] ?? ''));
                }
                if ($label !== '') {
                    $adminName = $label;
                }
                $positionId = (int)($u['ID_STATUS'] ?? 0);
            }
        }

        $header = self::buildHeader($adminId, (int)$dayStart, $dayEnd, $bounds);
        $rating = class_exists('RatingAggregates', false)
            ? RatingAggregates::forAdminRange($adminId, (int)$dayStart, $dayEnd, $bounds)
            : [
                'score_w' => 0.0,
                'score_sec' => 0,
                'coverage_sec' => 0,
                'idle_sec' => 0,
                'collections' => [],
                'by_script' => [],
            ];

        $header['work_sec'] = (int)($rating['coverage_sec'] ?? 0);
        $header['idle_sec'] = (int)($rating['idle_sec'] ?? 0);
        $dayScore = class_exists('RatingAggregates', false)
            ? RatingAggregates::avgScore($rating)
            : null;
        $header['score'] = $dayScore;
        $header['score_w'] = (float)($rating['score_w'] ?? 0);
        $header['score_sec'] = (int)($rating['score_sec'] ?? 0);

        $scripts = self::ratingScriptsForPosition($positionId);
        $byScript = is_array($rating['by_script'] ?? null) ? $rating['by_script'] : [];
        $accounted = max(0, (int)($rating['score_sec'] ?? 0));

        $rows = [];
        foreach ($scripts as $script) {
            $sid = (int)$script['id'];
            $stat = $byScript[$sid] ?? ['score_rate_w' => 0.0, 'duration' => 0, 'count' => 0];
            $contribW = (float)($stat['score_rate_w'] ?? 0);
            $dur = (int)($stat['duration'] ?? 0);
            $contrib = $accounted > 0 ? round($contribW / $accounted, 2) : 0.0;
            $valuePct = $accounted > 0 ? round(100.0 * $dur / $accounted, 1) : 0.0;
            $team = self::teamScriptAvg($sid, $positionId, $adminId, (int)$dayStart);
            $level = self::levelVsTeam($contrib, $team['avg_contrib']);
            $rows[] = [
                'script_id' => $sid,
                'name' => (string)$script['name'],
                'score_rate' => (int)($script['score_rate_sum'] ?? 0),
                'contrib' => $contrib,
                'value_pct' => $valuePct,
                'duration' => $dur,
                'count' => (int)($stat['count'] ?? 0),
                'team_avg' => $team['avg_contrib'],
                'level' => $level,
                'collections_href' => '/audio/collections?' . http_build_query([
                    'year' => (int)date('Y', $dayStart),
                    'month' => (int)date('n', $dayStart),
                    'day' => (int)date('j', $dayStart),
                    'admin' => $adminId,
                    'script' => $sid,
                ]),
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            return ($b['contrib'] <=> $a['contrib']) ?: strcmp((string)$a['name'], (string)$b['name']);
        });

        $sessions = self::buildSessions($adminId, (int)$dayStart, $dayEnd, $scripts);
        $sessSummary = ['finished' => 0, 'interrupted' => 0];
        foreach ($sessions as $sess) {
            if (!empty($sess['finished'])) {
                $sessSummary['finished']++;
            } else {
                $sessSummary['interrupted']++;
            }
        }

        $coverage = self::coverageConfigForAdmin($adminId);
        $procedures = null;
        if (!empty($coverage['match_procedures'])) {
            $procedures = self::buildProceduresCompare(
                $adminId,
                (int)$dayStart,
                $dayEnd,
                $sessions,
                (int)$coverage['min_procedure_sec'],
                (int)$coverage['procedure_buffer_sec']
            );
        } else {
            foreach ($sessions as &$sessNoMatch) {
                $sessNoMatch['procedure'] = null;
            }
            unset($sessNoMatch);
        }

        $gated = self::gatedCollections($adminId, (int)$dayStart, $dayEnd);
        $teamDay = self::teamDayAvg($positionId, $adminId, (int)$dayStart);
        $summary = self::buildSummary($rows, $dayScore, $teamDay['avg_score']);

        $quality = class_exists('DayQualityReport', false)
            ? DayQualityReport::build($adminId, $date, $sessions, $procedures, $coverage)
            : null;

        $sections = EventBus::emit('audio_reports.day_report.sections', [
            'admin_id' => $adminId,
            'date' => $date,
            'html' => '',
            'quality' => $quality,
        ]);
        $sectionsHtml = is_array($sections) ? (string)($sections['html'] ?? '') : '';

        return [
            'ok' => true,
            'admin_id' => $adminId,
            'date' => $date,
            'admin_name' => $adminName,
            'position_id' => $positionId,
            'header' => $header,
            'rows' => $rows,
            'sessions' => $sessions,
            'sessions_summary' => $sessSummary,
            'procedures' => $procedures,
            'quality' => $quality,
            'gated' => $gated,
            'total' => [
                'score' => $dayScore,
                'score_w' => (float)($rating['score_w'] ?? 0),
                'score_sec' => $accounted,
            ],
            'team' => $teamDay,
            'summary' => $summary,
            'sections_html' => $sectionsHtml,
        ];
    }

    /**
     * @return array{
     *   disable_outside_coverage:bool,
     *   match_procedures:bool,
     *   procedure_buffer_sec:int,
     *   min_procedure_sec:int,
     *   source?:string
     * }
     */
    private static function coverageConfigForAdmin(int $adminId): array
    {
        if (!class_exists('AudioCoverageConfig', false)) {
            $path = base_path('modules/audio_pipeline/lib/AudioCoverageConfig.php');
            if (is_file($path)) {
                require_once $path;
            }
        }
        if (!class_exists('AudioCoverageProfiles', false)) {
            $path = base_path('modules/audio_pipeline/lib/AudioCoverageProfiles.php');
            if (is_file($path)) {
                require_once $path;
            }
        }
        if (class_exists('AudioCoverageConfig', false)) {
            return AudioCoverageConfig::forAdmin($adminId);
        }

        return [
            'disable_outside_coverage' => false,
            'match_procedures' => false,
            'procedure_buffer_sec' => 1600,
            'min_procedure_sec' => 360,
            'source' => 'fallback',
        ];
    }

    /**
     * Rated sessions with part chains (+points / dashes).
     *
     * @param list<array{id:int,name:string,score_rate_sum:int}> $scripts
     * @return list<array<string,mixed>>
     */
    private static function buildSessions(int $adminId, int $dayStart, int $dayEnd, array $scripts): array
    {
        if ($scripts === [] || !Database::tableExists('base_records_collections')) {
            return [];
        }
        $scriptIds = [];
        $scriptNames = [];
        foreach ($scripts as $s) {
            $sid = (int)$s['id'];
            $scriptIds[] = $sid;
            $scriptNames[$sid] = (string)$s['name'];
        }
        $in = implode(',', array_map('intval', $scriptIds));
        $ratedCond = Database::columnExists('base_records_collections', 'RATED')
            ? 'AND c.RATED = 1' : '';
        $scoreCol = Database::columnExists('base_records_collections', 'SCORE_RATE')
            ? 'c.SCORE_RATE' : '0';
        $st = Database::pdo()->prepare(
            "SELECT c.ID AS id, c.ID_SCRIPT AS script_id, c.DT_START AS dt_start, c.DT_END AS dt_end,
                    {$scoreCol} AS score_rate
             FROM base_records_collections c
             WHERE c.ID_ADMIN = ? AND c.ID_SCRIPT IN ($in) {$ratedCond}
               AND c.DT_START <= ? AND (c.DT_END = 0 OR c.DT_END >= ?)
             ORDER BY c.DT_START ASC"
        );
        $st->execute([$adminId, $dayEnd, $dayStart]);
        $collections = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($collections === []) {
            return [];
        }

        $partsByScript = self::scriptPartsTemplates($scriptIds);
        $hitsByColl = self::collectionPartHits(array_map(static fn ($c) => (int)$c['id'], $collections));

        $out = [];
        foreach ($collections as $c) {
            $cid = (int)$c['id'];
            $sid = (int)$c['script_id'];
            $parts = $partsByScript[$sid] ?? [];
            $hits = $hitsByColl[$cid] ?? [];
            $chain = [];
            $finished = false;
            $hasFinishPart = false;
            foreach ($parts as $part) {
                $pid = (int)$part['id'];
                $hit = isset($hits[$pid]);
                if (!empty($part['finish'])) {
                    $hasFinishPart = true;
                    if ($hit) {
                        $finished = true;
                    }
                }
                $chain[] = [
                    'part_id' => $pid,
                    'name' => (string)$part['name'],
                    'score_rate' => (int)$part['score_rate'],
                    'finish' => !empty($part['finish']),
                    'killer' => !empty($part['killer']),
                    'hit' => $hit,
                ];
            }
            if (!$hasFinishPart) {
                // No FINISH part in template → treat as finished if any parts hit.
                $finished = $chain !== [] && count(array_filter($chain, static fn ($x) => $x['hit'])) > 0;
            }
            $out[] = [
                'id' => $cid,
                'script_id' => $sid,
                'name' => $scriptNames[$sid] ?? ('#' . $sid),
                'dt_start' => (int)$c['dt_start'],
                'dt_end' => (int)$c['dt_end'],
                'score_rate' => (int)$c['score_rate'],
                'finished' => $finished,
                'chain' => $chain,
                'href' => '/audio/collections/' . $cid,
            ];
        }

        return $out;
    }

    /**
     * @param list<int> $scriptIds
     * @return array<int, list<array{id:int,name:string,score_rate:int,finish:int,killer:int}>>
     */
    private static function scriptPartsTemplates(array $scriptIds): array
    {
        if ($scriptIds === [] || !Database::tableExists('base_records_scripts')) {
            return [];
        }
        $in = implode(',', array_map('intval', $scriptIds));
        $rateCol = Database::columnExists('base_records_scripts', 'SCORE_RATE')
            ? 'SCORE_RATE' : '0';
        $killerCol = Database::columnExists('base_records_scripts', 'COLLECTION_KILLER')
            ? 'COLLECTION_KILLER' : '0';
        $rows = Database::pdo()->query(
            "SELECT ID AS id, PID AS pid, NAME AS name, FINISH AS finish,
                    {$rateCol} AS score_rate, {$killerCol} AS killer
             FROM base_records_scripts
             WHERE PID IN ($in) AND DISABLED < 1
             ORDER BY PID ASC, NUM ASC, ID ASC"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $pid = (int)$row['pid'];
            if (!isset($out[$pid])) {
                $out[$pid] = [];
            }
            $out[$pid][] = [
                'id' => (int)$row['id'],
                'name' => trim((string)$row['name']) !== '' ? trim((string)$row['name']) : ('#' . (int)$row['id']),
                'score_rate' => (int)$row['score_rate'],
                'finish' => (int)$row['finish'],
                'killer' => (int)$row['killer'],
            ];
        }

        return $out;
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
            $cid = (int)$row['ID_COLLECTION'];
            $pid = (int)$row['ID_PART'];
            if (!isset($out[$cid])) {
                $out[$cid] = [];
            }
            $out[$cid][$pid] = true;
        }

        return $out;
    }

    /** Minimum hard-overlap seconds required for a hard bind. */
    private const MIN_HARD_OVERLAP_SEC = 60;

    /**
     * Paid procedures vs rated sessions — procedure-first gather.
     *
     * Pass 1: each procedure window ± procedure_buffer_sec gathers collections
     * (1:many). Contested hard overlap goes to the procedure with more hard-sec;
     * finished collections are scored as ending at the procedure end (early close).
     * Collections that start slightly before the procedure (within gap) are included.
     * Pass 2: leftover collections stay unbound (orphan sessions / near-gap on slots).
     *
     * @param list<array<string,mixed>> $sessions
     * @return array<string,mixed>|null
     */
    private static function buildProceduresCompare(
        int $adminId,
        int $dayStart,
        int $dayEnd,
        array &$sessions,
        int $minProcSec,
        int $procBuffer
    ): ?array {
        if (!class_exists('ModuleManager', false) || !ModuleManager::isEnabled('procedures')) {
            return null;
        }
        if (!Database::tableExists('base_procedures')) {
            return null;
        }
        $opCol = Database::columnExists('base_procedures', 'ID_OPERATOR')
            ? 'ID_OPERATOR' : 'ID_ADMIN';
        $joins = '';
        $nameExpr = 'NULL';
        // Prefer product title; type code (SPA/MSSG) only as fallback.
        if (Database::tableExists('base_products') && Database::columnExists('base_procedures', 'ID_GOOD')) {
            $joins .= ' LEFT JOIN base_products g ON g.id = p.ID_GOOD';
            $nameExpr = "NULLIF(TRIM(g.name), '')";
        }
        if (Database::tableExists('base_procedure_types') && Database::columnExists('base_procedures', 'TYPE')) {
            $joins .= ' LEFT JOIN base_procedure_types t ON t.ID = p.TYPE';
            $nameExpr = "COALESCE({$nameExpr}, NULLIF(TRIM(t.TYPE), ''))";
        }
        $st = Database::pdo()->prepare(
            "SELECT p.ID AS id, p.DTSTART AS dt_start, p.DTEND AS dt_end, p.SUMM AS summ,
                    COALESCE({$nameExpr}, CONCAT('#', p.ID)) AS name
             FROM base_procedures p
             {$joins}
             WHERE p.{$opCol} = ? AND p.ENABLED = 1 AND p.SUMM > 0
               AND p.DTSTART <= ? AND (p.DTEND = 0 OR p.DTEND >= ?)
             ORDER BY p.DTSTART ASC"
        );
        $st->execute([$adminId, $dayEnd, $dayStart]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $candidates = [];
        foreach ($rows as $row) {
            $ps = (int)$row['dt_start'];
            $pe = (int)$row['dt_end'];
            if ($pe < 1) {
                $pe = $ps;
            }
            $dur = max(0, $pe - $ps);
            if ($minProcSec > 0 && $dur < $minProcSec) {
                continue;
            }
            $name = trim((string)($row['name'] ?? ''));
            if ($name === '') {
                $name = '#' . (int)$row['id'];
            }
            $candidates[] = [
                'id' => (int)$row['id'],
                'name' => $name,
                'dt_start' => $ps,
                'dt_end' => $pe,
                'duration' => $dur,
                'summ' => (float)$row['summ'],
            ];
        }

        foreach ($sessions as &$sessInit) {
            $sessInit['procedure'] = null;
        }
        unset($sessInit);

        $minHard = self::MIN_HARD_OVERLAP_SEC;
        $gap = max(0, $procBuffer);
        $sessionBounds = [];
        foreach ($sessions as $idx => $sess) {
            $cs = (int)$sess['dt_start'];
            $ce = (int)$sess['dt_end'];
            if ($ce < 1) {
                $ce = $cs;
            }
            $sessionBounds[$idx] = [
                $cs,
                $ce,
                (int)($sess['id'] ?? 0),
                !empty($sess['finished']),
            ];
        }

        // Hard matrix: finished collections scored as ending at procedure end.
        /** @var array<int, array<int, int>> $hardMat */
        $hardMat = [];
        /** @var array<int, array{pi:int, hard:int}> $bestHardBySession */
        $bestHardBySession = [];
        foreach ($candidates as $pi => $cand) {
            $ps = (int)$cand['dt_start'];
            $pe = (int)$cand['dt_end'];
            foreach ($sessionBounds as $si => [$cs, $ce, $_sid, $finished]) {
                $effCe = $finished ? min($ce, $pe) : $ce;
                $hard = min($effCe, $pe) - max($cs, $ps);
                if ($hard < 1) {
                    continue;
                }
                $hardMat[$pi][$si] = $hard;
                if ($hard >= $minHard) {
                    $prev = $bestHardBySession[$si] ?? null;
                    if ($prev === null
                        || $hard > $prev['hard']
                        || ($hard === $prev['hard'] && $pi < $prev['pi'])
                    ) {
                        $bestHardBySession[$si] = ['pi' => $pi, 'hard' => $hard];
                    }
                }
            }
        }

        /** @var array<int, list<array{session_idx:int, overlap:int, kind:string}>> $bindsByProc */
        $bindsByProc = [];
        foreach ($candidates as $pi => $_) {
            $bindsByProc[$pi] = [];
        }
        $usedSessions = [];

        // Pass 1a — hard gathers (procedure owns sessions where it wins hard).
        foreach ($candidates as $pi => $_) {
            foreach ($bestHardBySession as $si => $best) {
                if ((int)$best['pi'] !== $pi || isset($usedSessions[$si])) {
                    continue;
                }
                $usedSessions[$si] = true;
                $bindsByProc[$pi][] = [
                    'session_idx' => $si,
                    'overlap' => (int)$best['hard'],
                    'kind' => 'hard',
                ];
            }
        }

        // Pass 1b — soft/pre-start gathers: start slightly early, or soft-hit core window.
        // Do not claim post-only spill past pe (leave for the next procedure / pass 2).
        foreach ($candidates as $pi => $cand) {
            $ps = (int)$cand['dt_start'];
            $pe = (int)$cand['dt_end'];
            $softLo = $ps - $gap;
            $extras = [];
            foreach ($sessionBounds as $si => [$cs, $ce, $_sid, $finished]) {
                if (isset($usedSessions[$si])) {
                    continue;
                }
                if (isset($bestHardBySession[$si]) && (int)$bestHardBySession[$si]['pi'] !== $pi) {
                    continue;
                }
                $effCe = $finished ? min($ce, $pe) : $ce;
                $hardHere = (int)($hardMat[$pi][$si] ?? 0);
                $coreOv = min($effCe, $pe) - max($cs, $ps);
                $preGap = $ce <= $ps ? ($ps - $ce) : -1;
                $isPreStart = $cs < $ps && $preGap >= 0 && $preGap <= $gap;
                $hitsCore = $coreOv > 0;
                $earlyIntoCore = $cs < $ps && $cs >= $softLo && $effCe > $ps;
                if (!$isPreStart && !$hitsCore && !$earlyIntoCore) {
                    continue;
                }
                $kind = ($isPreStart || ($earlyIntoCore && $hardHere < $minHard))
                    ? 'pre_start'
                    : ($hardHere >= $minHard ? 'hard' : 'soft');
                if ($hardHere > 0) {
                    $overlap = $hardHere;
                } elseif ($coreOv > 0) {
                    $overlap = $coreOv;
                } elseif ($isPreStart) {
                    // Seconds of the episode that sit in [ps−gap, ps].
                    $overlap = max(1, min($ce, $ps) - max($cs, $softLo));
                } else {
                    $overlap = max(1, min($effCe, $pe) - max($cs, $softLo));
                }
                $score = ($isPreStart ? 1_000_000 - $preGap : 0) + $overlap;
                $extras[] = [$score, $si, $overlap, $kind];
            }
            usort(
                $extras,
                static function (array $a, array $b): int {
                    if ($a[0] !== $b[0]) {
                        return $b[0] <=> $a[0];
                    }

                    return $a[1] <=> $b[1];
                }
            );
            foreach ($extras as [$_score, $si, $overlap, $kind]) {
                if (isset($usedSessions[$si])) {
                    continue;
                }
                $usedSessions[$si] = true;
                $bindsByProc[$pi][] = [
                    'session_idx' => (int)$si,
                    'overlap' => (int)$overlap,
                    'kind' => (string)$kind,
                ];
            }
        }

        // Pass 2 — leftover collections: leave unbound; near-gap on orphan slots.
        $slots = [];
        $matchedSlots = 0;
        foreach ($candidates as $pi => $cand) {
            $ps = (int)$cand['dt_start'];
            $pe = (int)$cand['dt_end'];
            $binds = $bindsByProc[$pi];
            usort(
                $binds,
                static function (array $a, array $b): int {
                    $ka = $a['kind'] === 'hard' ? 2 : ($a['kind'] === 'pre_start' ? 1 : 0);
                    $kb = $b['kind'] === 'hard' ? 2 : ($b['kind'] === 'pre_start' ? 1 : 0);
                    if ($ka !== $kb) {
                        return $kb <=> $ka;
                    }

                    return $b['overlap'] <=> $a['overlap'];
                }
            );
            $orphan = $binds === [];
            $sessionIds = [];
            $bindRows = [];
            $primaryId = null;
            $primaryOv = null;
            $primaryKind = null;
            foreach ($binds as $bind) {
                $si = (int)$bind['session_idx'];
                $ov = (int)$bind['overlap'];
                $kind = (string)$bind['kind'];
                [$_cs, $_ce, $sessId] = $sessionBounds[$si];
                $sid = $sessId > 0 ? $sessId : null;
                if ($sid !== null) {
                    $sessionIds[] = $sid;
                }
                $bindRows[] = [
                    'session_id' => $sid,
                    'overlap_sec' => $ov,
                    'match_kind' => $kind,
                ];
                if ($primaryId === null) {
                    $primaryId = $sid;
                    $primaryOv = $ov;
                    $primaryKind = $kind;
                }
                $sessions[$si]['procedure'] = [
                    'id' => (int)$cand['id'],
                    'name' => (string)$cand['name'],
                    'dt_start' => $ps,
                    'dt_end' => $pe,
                    'summ' => (float)$cand['summ'],
                    'overlap_sec' => $ov,
                    'match_kind' => $kind,
                ];
            }
            if (!$orphan) {
                $matchedSlots++;
            }
            $slot = [
                'id' => (int)$cand['id'],
                'name' => (string)$cand['name'],
                'dt_start' => $ps,
                'dt_end' => $pe,
                'duration' => (int)$cand['duration'],
                'summ' => (float)$cand['summ'],
                'orphan' => $orphan,
                'session_id' => $primaryId,
                'session_ids' => $sessionIds,
                'binds' => $bindRows,
                'overlap_sec' => $primaryOv,
                'match_kind' => $primaryKind,
                'near_session_id' => null,
                'near_gap_sec' => null,
            ];
            if ($orphan) {
                $near = self::nearestSessionGap($ps, $pe, $sessionBounds);
                if ($near !== null) {
                    $slot['near_session_id'] = $near['session_id'];
                    $slot['near_gap_sec'] = $near['gap_sec'];
                }
            }
            $slots[] = $slot;
        }

        $orphanSlots = count($slots) - $matchedSlots;
        $orphanSessionIds = [];
        foreach ($sessionBounds as $idx => [$_cs, $_ce, $sessId]) {
            if (!isset($usedSessions[$idx]) && $sessId > 0) {
                $orphanSessionIds[] = $sessId;
            }
        }

        return [
            'enabled' => true,
            'match_mode' => 'procedure_first',
            'min_procedure_sec' => $minProcSec,
            'min_hard_overlap_sec' => $minHard,
            'procedure_buffer_sec' => $gap,
            'procedure_count' => count($slots),
            'session_count' => count($sessions),
            'matched_slots' => $matchedSlots,
            'orphan_slots' => $orphanSlots,
            'orphan_sessions' => count($orphanSessionIds),
            'orphan_session_ids' => $orphanSessionIds,
            'slots' => $slots,
        ];
    }

    /**
     * Closest session by gap (0 = hard overlap below min threshold).
     *
     * @param array<int, array{0:int,1:int,2:int,3?:bool}> $sessionBounds
     * @return array{session_id:int|null, gap_sec:int}|null
     */
    private static function nearestSessionGap(int $ps, int $pe, array $sessionBounds): ?array
    {
        $bestGap = null;
        $bestSid = null;
        foreach ($sessionBounds as $bounds) {
            $cs = (int)$bounds[0];
            $ce = (int)$bounds[1];
            $sessId = (int)$bounds[2];
            if ($ce < $ps) {
                $gap = $ps - $ce;
            } elseif ($cs > $pe) {
                $gap = $cs - $pe;
            } else {
                $gap = 0;
            }
            if ($bestGap === null || $gap < $bestGap) {
                $bestGap = $gap;
                $bestSid = $sessId > 0 ? $sessId : null;
            }
        }
        if ($bestGap === null) {
            return null;
        }

        return ['session_id' => $bestSid, 'gap_sec' => $bestGap];
    }

    /**
     * @param array<string,int> $bounds
     * @return array<string,mixed>
     */
    private static function buildHeader(int $adminId, int $dayStart, int $dayEnd, array $bounds): array
    {
        $header = [
            'records' => 0,
            'playtime' => 0,
            'active_from' => null,
            'active_to' => null,
            'breaks_sec' => 0,
            'disabled_sec' => 0,
            'work_sec' => 0,
            'idle_sec' => 0,
            'score' => null,
        ];
        if (!Database::tableExists('base_records')) {
            return $header;
        }
        $hasDesc = Database::columnExists('base_records', 'DESCRIPTION');
        $sql = 'SELECT DT_RECORDED AS dt, PLAYTIME AS playtime, DISABLED AS disabled'
            . ($hasDesc ? ', DESCRIPTION AS description' : '')
            . ' FROM base_records
               WHERE ID_ADMIN = ? AND DT_RECORDED >= ? AND DT_RECORDED <= ?
               ORDER BY DT_RECORDED ASC';
        $st = Database::pdo()->prepare($sql);
        $st->execute([$adminId, $dayStart, $dayEnd]);
        $intervals = [];
        $disabledSec = 0;
        $first = null;
        $last = null;
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            if ($hasDesc && (string)($row['description'] ?? '') === 'BONUS TIME') {
                continue;
            }
            $dt = (int)$row['dt'];
            $play = max(0, (int)$row['playtime']);
            $disabled = (int)($row['disabled'] ?? 0);
            if ($disabled > 0) {
                $disabledSec += $play;
                continue;
            }
            if ($play < 1) {
                continue;
            }
            $header['records']++;
            $header['playtime'] += $play;
            $end = $dt + $play;
            if ($first === null || $dt < $first) {
                $first = $dt;
            }
            if ($last === null || $end > $last) {
                $last = $end;
            }
            $intervals[] = ['dtStart' => $dt, 'dtEnd' => $end];
        }
        $header['active_from'] = $first;
        $header['active_to'] = $last;
        $header['disabled_sec'] = $disabledSec;
        if ($first !== null && $last !== null && $last > $first) {
            $span = $last - $first;
            $covered = class_exists('RatingAggregates', false)
                ? RatingAggregates::mergedSeconds($intervals)
                : $header['playtime'];
            $header['breaks_sec'] = max(0, $span - $covered);
        }
        return $header;
    }

    /**
     * @return list<array{id:int,name:string,score_rate_sum:int}>
     */
    private static function ratingScriptsForPosition(int $positionId): array
    {
        if (!Database::tableExists('base_records_scripts')
            || !Database::columnExists('base_records_scripts', 'RATING_ENABLED')
        ) {
            return [];
        }
        $posIds = [$positionId];
        if ($positionId > 0 && class_exists('SummariesRepository', false)) {
            $eq = SummariesRepository::equivalentAudioPositionIds($positionId);
            if ($eq !== []) {
                $posIds = $eq;
            }
        }
        $pdo = Database::pdo();
        $st = $pdo->query(
            "SELECT ID AS id, NAME AS name, ID_POSITION AS id_position
             FROM base_records_scripts
             WHERE PID = 0 AND RATING_ENABLED = 1 AND DISABLED < 1
             ORDER BY NUM ASC, NAME ASC, ID ASC"
        );
        if (!$st) {
            return [];
        }
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $sid = (int)$row['id'];
            $posRaw = (string)($row['id_position'] ?? '');
            if ($posRaw !== '' && $posRaw !== '0' && $positionId > 0) {
                $ok = false;
                foreach ($posIds as $pid) {
                    $pid = (int)$pid;
                    if ($pid < 1) {
                        continue;
                    }
                    if ($posRaw === (string)$pid || strpos($posRaw, '#' . $pid . '#') !== false) {
                        $ok = true;
                        break;
                    }
                }
                if (!$ok) {
                    continue;
                }
            }
            $rateSum = 0;
            if (Database::columnExists('base_records_scripts', 'SCORE_RATE')) {
                $pst = $pdo->prepare(
                    'SELECT COALESCE(SUM(SCORE_RATE),0) FROM base_records_scripts WHERE PID = ? AND DISABLED < 1'
                );
                $pst->execute([$sid]);
                $rateSum = (int)$pst->fetchColumn();
            }
            $out[] = [
                'id' => $sid,
                'name' => trim((string)$row['name']) !== '' ? trim((string)$row['name']) : ('#' . $sid),
                'score_rate_sum' => $rateSum,
            ];
        }
        return $out;
    }

    /**
     * Cached team window: admin-day → RatingAggregates result.
     *
     * @return array<string, array{score_w:float,score_sec:int,by_script:array<int,array{score_rate_w:float}>}>
     */
    private static function teamWindowCache(int $positionId, int $excludeAdmin, int $dayStart): array
    {
        static $cache = [];
        $key = $positionId . ':' . $excludeAdmin . ':' . $dayStart;
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        $out = [];
        if (!class_exists('RatingAggregates', false)) {
            return $cache[$key] = $out;
        }
        $admins = self::teamAdminIds($positionId, $excludeAdmin);
        if ($admins === []) {
            return $cache[$key] = $out;
        }
        $bounds = AudioDayBounds::read();
        $from = $dayStart - self::TEAM_DAYS * 86400;
        foreach ($admins as $aid) {
            for ($d = 0; $d < self::TEAM_DAYS; $d++) {
                $ds = $from + $d * 86400;
                $de = $ds + 86400 - 1;
                $r = RatingAggregates::forAdminRange($aid, $ds, $de, $bounds);
                if ((int)($r['score_sec'] ?? 0) < 1) {
                    continue;
                }
                $out[$aid . ':' . $ds] = [
                    'score_w' => (float)($r['score_w'] ?? 0),
                    'score_sec' => (int)$r['score_sec'],
                    'by_script' => is_array($r['by_script'] ?? null) ? $r['by_script'] : [],
                ];
            }
        }
        return $cache[$key] = $out;
    }

    /**
     * @return array{avg_contrib:float|null,samples:int}
     */
    private static function teamScriptAvg(int $scriptId, int $positionId, int $excludeAdmin, int $dayStart): array
    {
        $empty = ['avg_contrib' => null, 'samples' => 0];
        if ($scriptId < 1) {
            return $empty;
        }
        $sumContrib = 0.0;
        $samples = 0;
        foreach (self::teamWindowCache($positionId, $excludeAdmin, $dayStart) as $r) {
            $sec = (int)$r['score_sec'];
            if ($sec < 1) {
                continue;
            }
            $sw = (float)(($r['by_script'][$scriptId]['score_rate_w'] ?? 0));
            $sumContrib += $sw / $sec;
            $samples++;
        }
        if ($samples < 1) {
            return $empty;
        }
        return ['avg_contrib' => round($sumContrib / $samples, 2), 'samples' => $samples];
    }

    /**
     * @return array{avg_score:float|null,samples:int}
     */
    private static function teamDayAvg(int $positionId, int $excludeAdmin, int $dayStart): array
    {
        $sum = 0.0;
        $n = 0;
        if (!class_exists('RatingAggregates', false)) {
            return ['avg_score' => null, 'samples' => 0];
        }
        foreach (self::teamWindowCache($positionId, $excludeAdmin, $dayStart) as $r) {
            $avg = RatingAggregates::avgScore($r);
            if ($avg === null) {
                continue;
            }
            $sum += $avg;
            $n++;
        }
        if ($n < 1) {
            return ['avg_score' => null, 'samples' => 0];
        }
        return ['avg_score' => round($sum / $n, 2), 'samples' => $n];
    }

    /** @return list<int> */
    private static function teamAdminIds(int $positionId, int $excludeAdmin): array
    {
        if ($positionId < 1 || !Database::tableExists('base_users')) {
            return [];
        }
        $posIds = [$positionId];
        if (class_exists('SummariesRepository', false)) {
            $eq = SummariesRepository::equivalentAudioPositionIds($positionId);
            if ($eq !== []) {
                $posIds = $eq;
            }
        }
        $ph = implode(',', array_fill(0, count($posIds), '?'));
        $params = $posIds;
        $sql = "SELECT id FROM base_users WHERE ID_STATUS IN ($ph) AND enabled = 1";
        if ($excludeAdmin > 0) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeAdmin;
        }
        $sql .= ' ORDER BY id ASC LIMIT 40';
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    private static function levelVsTeam(float $value, ?float $teamAvg): string
    {
        // No team baseline for 10d → level is unknown (not «ok»).
        if ($teamAvg === null) {
            return 'unknown';
        }
        if (abs($teamAvg) < 0.0001) {
            return abs($value) < 0.0001 ? 'ok' : 'unknown';
        }
        $delta = ($value - $teamAvg) / abs($teamAvg);
        if ($delta > self::LEVEL_PCT) {
            return 'above';
        }
        if ($delta < -self::LEVEL_PCT) {
            return 'below';
        }
        return 'ok';
    }

    /**
     * @return list<array{id:int,script_id:int,script_name:string,gate_reason:string,dt_start:int,dt_end:int}>
     */
    private static function gatedCollections(int $adminId, int $dayStart, int $dayEnd): array
    {
        if (!Database::tableExists('base_records_collections')
            || !Database::columnExists('base_records_collections', 'RATED')
        ) {
            return [];
        }
        $reasonCol = Database::columnExists('base_records_collections', 'GATE_REASON')
            ? 'c.GATE_REASON' : "''";
        $st = Database::pdo()->prepare(
            "SELECT c.ID AS id, c.ID_SCRIPT AS script_id, c.DT_START AS dt_start, c.DT_END AS dt_end,
                    {$reasonCol} AS gate_reason, s.NAME AS script_name
             FROM base_records_collections c
             LEFT JOIN base_records_scripts s ON s.ID = c.ID_SCRIPT
             WHERE c.ID_ADMIN = ? AND c.RATED = 0
               AND c.DT_START <= ? AND (c.DT_END = 0 OR c.DT_END >= ?)
             ORDER BY c.DT_START ASC
             LIMIT 100"
        );
        $st->execute([$adminId, $dayEnd, $dayStart]);
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $reason = trim((string)($row['gate_reason'] ?? ''));
            if ($reason === '') {
                continue;
            }
            $out[] = [
                'id' => (int)$row['id'],
                'script_id' => (int)$row['script_id'],
                'script_name' => trim((string)($row['script_name'] ?? '')) ?: ('#' . (int)$row['script_id']),
                'gate_reason' => $reason,
                'dt_start' => (int)$row['dt_start'],
                'dt_end' => (int)$row['dt_end'],
            ];
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<string>
     */
    private static function buildSummary(array $rows, ?float $dayScore, ?float $teamScore): array
    {
        $out = [];
        if ($dayScore !== null) {
            $line = '® ' . $dayScore;
            if ($teamScore !== null) {
                $line .= ' / team ' . $teamScore;
            }
            $out[] = $line;
        }
        $top = array_slice($rows, 0, 3);
        foreach ($top as $r) {
            if ((float)($r['contrib'] ?? 0) == 0.0) {
                continue;
            }
            $line = (string)$r['name'] . ': +' . $r['contrib'] . ' (' . $r['value_pct'] . '%';
            $lvl = (string)($r['level'] ?? 'ok');
            if ($lvl !== '' && $lvl !== 'unknown') {
                $line .= ', ' . t('audio_reports.day_report.level.' . $lvl);
            }
            $line .= ')';
            $out[] = $line;
        }
        return $out;
    }
}
