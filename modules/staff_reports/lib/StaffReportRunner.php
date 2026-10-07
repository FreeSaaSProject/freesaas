<?php
declare(strict_types=1);

/** Build report comment body + run day/month generation. */
final class StaffReportRunner
{
    /** @var list<string> */
    private static $log = [];

    /** @var callable|null */
    private static $logSink = null;

    public static function setLogSink(?callable $fn): void
    {
        self::$logSink = $fn;
    }

    public static function resetLog(): void
    {
        self::$log = [];
    }

    /** @return list<string> */
    public static function logLines(): array
    {
        return self::$log;
    }

    public static function log(string $msg): void
    {
        self::$log[] = $msg;
        if (self::$logSink !== null) {
            (self::$logSink)($msg);
        }
    }

    public static function lastAllowedDayStart(): int
    {
        return (int)mktime(0, 0, 0, (int)date('n'), (int)date('j') - 1, (int)date('Y'));
    }

    /**
     * @return array{day_start:int,day_end:int,day_text:string,date:string}|null
     */
    public static function parseDay(string $day): ?array
    {
        $day = trim($day);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $day, $m)) {
            $y = (int)$m[1];
            $mo = (int)$m[2];
            $d = (int)$m[3];
        } elseif (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $day, $m)) {
            $d = (int)$m[1];
            $mo = (int)$m[2];
            $y = (int)$m[3];
        } else {
            return null;
        }
        $start = mktime(0, 0, 0, $mo, $d, $y);
        if ($start === false) {
            return null;
        }
        return [
            'day_start' => (int)$start,
            'day_end' => (int)$start + 86399,
            'day_text' => sprintf('%02d.%02d.%04d', $d, $mo, $y),
            'date' => sprintf('%04d-%02d-%02d', $y, $mo, $d),
        ];
    }

    /**
     * @return array{day_text?:string,date?:string,error?:string,stats?:array,results?:array}
     */
    public static function runDay(string $day, int $employeeId = 0): array
    {
        $bounds = self::parseDay($day);
        if ($bounds === null) {
            return ['error' => t('staff_reports.err.bad_day')];
        }
        if ($bounds['day_start'] > self::lastAllowedDayStart()) {
            return [
                'day_text' => $bounds['day_text'],
                'date' => $bounds['date'],
                'error' => t('staff_reports.err.future'),
            ];
        }

        self::log('start ' . $bounds['day_text'] . ($employeeId > 0 ? ' emp#' . $employeeId : ''));
        $emps = self::employeesForDay($bounds['day_start'], $bounds['day_end'], $employeeId);
        if ($emps === []) {
            self::log('no employees');
            return [
                'day_text' => $bounds['day_text'],
                'date' => $bounds['date'],
                'stats' => ['ok' => 0, 'skipped' => 0, 'error' => 0],
                'results' => [],
            ];
        }

        $stats = ['ok' => 0, 'skipped' => 0, 'error' => 0];
        $results = [];
        foreach ($emps as $empId) {
            $r = self::generateForEmployee($empId, $bounds);
            $status = (string)($r['status'] ?? 'error');
            if (isset($stats[$status])) {
                $stats[$status]++;
            } else {
                $stats['error']++;
            }
            $results[$empId] = $r;
        }
        self::log('done ' . $bounds['day_text'] . ' ok=' . $stats['ok'] . ' skipped=' . $stats['skipped'] . ' error=' . $stats['error']);
        return [
            'day_text' => $bounds['day_text'],
            'date' => $bounds['date'],
            'stats' => $stats,
            'results' => $results,
        ];
    }

    /**
     * @return array{year:int,month:int,days:list<array>,stats:array}
     */
    public static function runMonth(int $year, int $month): array
    {
        $daysIn = (int)date('t', mktime(0, 0, 0, $month, 1, $year) ?: time());
        $last = self::lastAllowedDayStart();
        $agg = ['ok' => 0, 'skipped' => 0, 'error' => 0];
        $dayResults = [];
        for ($d = 1; $d <= $daysIn; $d++) {
            $ts = mktime(0, 0, 0, $month, $d, $year);
            if ($ts === false || $ts > $last) {
                break;
            }
            $day = sprintf('%04d-%02d-%02d', $year, $month, $d);
            $run = self::runDay($day, 0);
            if (isset($run['stats']) && is_array($run['stats'])) {
                foreach (['ok', 'skipped', 'error'] as $k) {
                    $agg[$k] += (int)($run['stats'][$k] ?? 0);
                }
            }
            $dayResults[] = $run;
        }
        return ['year' => $year, 'month' => $month, 'days' => $dayResults, 'stats' => $agg];
    }

    /**
     * @param array{day_start:int,day_end:int,day_text:string,date:string} $bounds
     * @return array<string,mixed>
     */
    public static function generateForEmployee(int $employeeId, array $bounds): array
    {
        $name = TicketWriter::employeeName($employeeId);
        $profile = StaffReportProfiles::forEmployee($employeeId);
        if ($profile === null) {
            self::log("$name: " . t('staff_reports.err.no_profile'));
            return ['status' => 'skipped', 'reason' => 'no_profile'];
        }
        $config = $profile['config'];
        $mode = (string)($config['analysis']['mode'] ?? StaffReportConfig::MODE_PROCEDURE_SLOT);

        $audioEnabled = self::isAudioEnabled($employeeId);
        $parse = self::parseStatus($employeeId, $bounds['day_start'], $bounds['day_end']);
        $hasRecords = ((int)$parse['total'] > 0);
        self::log("$name: records={$parse['total']} parsed={$parse['parsed']} pending={$parse['pending']}");

        if ($audioEnabled && !empty($config['require_full_parse']) && !$parse['is_ready'] && $hasRecords) {
            self::log("$name: skip not_parsed");
            return ['status' => 'skipped', 'reason' => 'not_parsed', 'has_records' => $hasRecords];
        }

        $dayData = DayCollector::collect($employeeId, $bounds['day_start'], $bounds['day_end'], $config);
        $windows = $dayData['windows'];
        $uniqueClients = (int)$dayData['unique_clients'];

        $audioEvaluated = false;
        $audioMessage = '';
        $coverage = null;
        $criteriaResults = [
            'criteria' => [],
            'has_red' => false,
            'has_scoring_groups' => false,
            'scoring_group_count' => 0,
        ];

        if ($audioEnabled && $hasRecords) {
            $types = CriteriaAnalyzer::loadCriteriaTypes($employeeId, $config);
            $criteriaResults = CriteriaAnalyzer::analyze(
                $employeeId,
                $windows,
                $types,
                $uniqueClients,
                $config,
                $mode,
                $bounds['day_start'],
                $bounds['day_end']
            );
            if ($mode !== StaffReportConfig::MODE_SCORE_TIME && $windows !== []) {
                $coverage = CoverageCalculator::compute(
                    $employeeId,
                    $windows,
                    $bounds['day_start'],
                    $bounds['day_end'],
                    $config
                );
            }
            $audioEvaluated = true;
        } elseif ($audioEnabled && !$hasRecords) {
            $audioMessage = t('staff_reports.report.audio_skip_empty');
        } else {
            $audioMessage = t('staff_reports.report.audio_skip_off');
        }

        $ctx = [
            'employee_id' => $employeeId,
            'bounds' => $bounds,
            'config' => $config,
            'day_data' => $dayData,
            'criteria' => $criteriaResults,
            'coverage' => $coverage,
            'audio_evaluated' => $audioEvaluated,
            'audio_message' => $audioMessage,
            'has_records' => $hasRecords,
        ];
        $ctx = EventBus::emit('staff_reports.day.after_analyze', $ctx);
        if (is_array($ctx)) {
            $criteriaResults = is_array($ctx['criteria'] ?? null) ? $ctx['criteria'] : $criteriaResults;
            $coverage = $ctx['coverage'] ?? $coverage;
            $audioEvaluated = !empty($ctx['audio_evaluated']);
            $audioMessage = (string)($ctx['audio_message'] ?? $audioMessage);
        }

        $audioPassed = $audioEvaluated
            && !empty($criteriaResults['has_scoring_groups'])
            && empty($criteriaResults['has_red']);
        $rewardSum = (int)($config['contract_reward_sum'] ?? 500);
        $rewardGranted = $audioPassed;

        $ticketId = TicketWriter::findOrCreateTicket($employeeId, $config);
        if ($ticketId < 1) {
            self::log("$name: ticket error");
            return ['status' => 'error', 'reason' => 'ticket', 'has_records' => $hasRecords];
        }

        $monthTotals = TicketWriter::monthRewardTotals(
            $ticketId,
            $bounds['day_start'],
            $rewardGranted,
            $rewardSum
        );

        $markerRu = 'Отчёт за ' . $bounds['day_text'];

        $text = self::formatReport(
            $markerRu,
            $dayData,
            $criteriaResults,
            [
                'audio_evaluated' => $audioEvaluated,
                'audio_message' => $audioMessage,
                'audio_passed' => $audioPassed,
                'coverage' => $coverage,
                'reward_granted' => $rewardGranted,
                'reward_sum' => $rewardSum,
                'reward_month_totals' => $monthTotals,
                'day_text' => $bounds['day_text'],
            ]
        );

        $pub = [
            'employee_id' => $employeeId,
            'ticket_id' => $ticketId,
            'text' => $text,
            'marker' => $markerRu,
            'reward_granted' => $rewardGranted,
            'config' => $config,
        ];
        $pub = EventBus::emit('staff_reports.day.before_publish', $pub);
        if (is_array($pub)) {
            $text = (string)($pub['text'] ?? $text);
            $rewardGranted = !empty($pub['reward_granted']);
        }

        $authorId = TicketWriter::robotId();
        if ($authorId < 1) {
            $authorId = $employeeId;
        }
        $up = TicketWriter::upsertReportComment($ticketId, $authorId, $markerRu, $text);
        self::log("$name: " . ($rewardGranted ? 'reward' : 'no-reward') . ' comment=' . $up['comment_id']);

        $after = [
            'employee_id' => $employeeId,
            'ticket_id' => $ticketId,
            'comment_id' => (int)$up['comment_id'],
            'day_data' => $dayData,
            'bounds' => $bounds,
            'config' => $config,
            'name' => $name,
            'reward_granted' => $rewardGranted,
        ];
        $after = EventBus::emit('staff_reports.day.after_publish', $after);
        $cyclic = is_array($after['cyclic_llm'] ?? null) ? $after['cyclic_llm'] : null;
        if (is_array($cyclic) && ((int)($cyclic['processed'] ?? 0) > 0 || (int)($cyclic['skipped'] ?? 0) > 0)) {
            self::log(
                "$name: cyclic LLM processed=" . (int)($cyclic['processed'] ?? 0)
                . ' skipped=' . (int)($cyclic['skipped'] ?? 0)
                . ' contracts=' . (int)($cyclic['contracts'] ?? 0)
            );
        }

        return [
            'status' => 'ok',
            'ticket_id' => $ticketId,
            'comment_id' => $up['comment_id'],
            'changed' => $up['changed'],
            'reward_granted' => $rewardGranted,
            'has_records' => $hasRecords,
            'has_red' => !empty($criteriaResults['has_red']),
            'criteria' => $criteriaResults,
            'cyclic_llm' => $cyclic,
        ];
    }

    /**
     * @param array{unique_clients:int} $dayData
     * @param array{criteria:list} $criteriaResults
     * @param array<string,mixed> $context
     */
    public static function formatReport(string $markerLine, array $dayData, array $criteriaResults, array $context): string
    {
        $lines = [];
        $lines[] = $markerLine;
        $lines[] = '';
        $lines[] = t('staff_reports.report.clients', ['n' => (string)(int)($dayData['unique_clients'] ?? 0)]);
        $lines[] = '';
        $lines[] = '<b>' . t('staff_reports.report.standards') . '</b>';

        $audioEvaluated = !empty($context['audio_evaluated']);
        if (!$audioEvaluated) {
            $lines[] = (string)($context['audio_message'] ?? t('staff_reports.report.audio_skip_off'));
        } else {
            $coverage = is_array($context['coverage'] ?? null) ? $context['coverage'] : null;
            if ($coverage && (int)($coverage['procedures_total'] ?? 0) > 0) {
                $lines[] = t('staff_reports.report.coverage', [
                    'covered' => (string)(int)$coverage['procedures_covered'],
                    'total' => (string)(int)$coverage['procedures_total'],
                    'pct' => (string)$coverage['overall_percent'],
                ]);
            }
            $groups = is_array($criteriaResults['criteria'] ?? null) ? $criteriaResults['criteria'] : [];
            if ($groups === []) {
                $lines[] = t('staff_reports.report.no_criteria');
            }
            foreach ($groups as $group) {
                $parts = [];
                foreach ($group['members'] as $member) {
                    $part = $member['name'] . ' ' . $member['numerator'] . '/' . $member['denominator'];
                    if (empty($member['is_scoring'])) {
                        $part .= ' ' . t('staff_reports.report.ref');
                        $part = '<i>' . $part . '</i>';
                    }
                    $parts[] = $part;
                }
                $lines[] = implode(' или ', $parts) . ' ' . ($group['emoji'] ?? '');
            }
        }

        if (!$audioEvaluated) {
            $lines[] = t('staff_reports.report.bonus_no');
        } elseif (!empty($context['audio_passed'])) {
            $lines[] = t('staff_reports.report.bonus_ok');
        } else {
            $lines[] = t('staff_reports.report.bonus_no');
        }

        $lines[] = '';
        $dayText = (string)($context['day_text'] ?? '');
        $sum = (int)($context['reward_sum'] ?? 0);
        $totals = is_array($context['reward_month_totals'] ?? null) ? $context['reward_month_totals'] : [];
        // Machine-stable reward lines (RU keywords) for MonthRewards / totals parsers.
        if (!empty($context['reward_granted'])) {
            $lines[] = '<b>Вознаграждение за ' . $dayText . ' в ' . $sum . 'р - НАЧИСЛЯЕТСЯ! ✅</b>';
            $lines[] = t('staff_reports.report.earned_month', ['n' => (string)(int)($totals['earned'] ?? 0)]);
        } else {
            $lines[] = '<b>Вознаграждение за ' . $dayText . ' в ' . $sum . 'р - НЕ начисляется! ❌</b>';
            $lines[] = t('staff_reports.report.missed_month', ['n' => (string)(int)($totals['missed'] ?? 0)]);
        }

        return implode("\n", $lines);
    }

    /** @return list<int> */
    public static function employeesForDay(int $dayStart, int $dayEnd, int $onlyEmp = 0): array
    {
        $posSet = StaffReportProfiles::enabledPositionSet();
        if ($posSet === []) {
            return [];
        }
        if ($onlyEmp > 0) {
            $p = StaffReportProfiles::forEmployee($onlyEmp);
            return $p !== null ? [$onlyEmp] : [];
        }
        if (!Database::tableExists('base_records') || !Database::tableExists('base_users')) {
            return [];
        }
        $posIn = implode(',', array_map('intval', array_keys($posSet)));
        $sql = "SELECT DISTINCT r.ID_ADMIN AS id
                FROM base_records r
                INNER JOIN base_users u ON u.id = r.ID_ADMIN
                WHERE r.DT_RECORDED >= ? AND r.DT_RECORDED <= ?
                  AND (r.DISABLED IS NULL OR r.DISABLED < 1)
                  AND u.ID_STATUS IN ($posIn)
                  AND u.enabled = 1";
        $st = Database::pdo()->prepare($sql);
        $st->execute([$dayStart, $dayEnd]);
        $ids = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $id = (int)($row['id'] ?? 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        // Also include employees with procedures that day for booking_create profiles without audio yet.
        if (Database::tableExists('base_procedures')) {
            $sql2 = "SELECT DISTINCT p.ID_OPERATOR AS id FROM base_procedures p
                     INNER JOIN base_users u ON u.id = p.ID_OPERATOR
                     WHERE p.DTSTART >= ? AND p.DTSTART <= ? AND p.ENABLED = 1 AND p.SUMM > 0
                       AND u.ID_STATUS IN ($posIn) AND u.enabled = 1";
            $st2 = Database::pdo()->prepare($sql2);
            $st2->execute([$dayStart, $dayEnd]);
            while ($row = $st2->fetch(PDO::FETCH_ASSOC)) {
                $id = (int)($row['id'] ?? 0);
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
            if (Database::columnExists('base_procedures', 'ID_ADMIN')) {
                $sql3 = "SELECT DISTINCT p.ID_ADMIN AS id FROM base_procedures p
                         INNER JOIN base_users u ON u.id = p.ID_ADMIN
                         WHERE p.DTSTART >= ? AND p.DTSTART <= ? AND p.ENABLED = 1 AND p.SUMM > 0
                           AND u.ID_STATUS IN ($posIn) AND u.enabled = 1 AND p.ID_ADMIN > 0";
                $st3 = Database::pdo()->prepare($sql3);
                $st3->execute([$dayStart, $dayEnd]);
                while ($row = $st3->fetch(PDO::FETCH_ASSOC)) {
                    $id = (int)($row['id'] ?? 0);
                    if ($id > 0) {
                        $ids[$id] = $id;
                    }
                }
            }
        }
        return array_values($ids);
    }

    public static function isAudioEnabled(int $employeeId): bool
    {
        if ($employeeId < 1 || !Database::tableExists('base_users')) {
            return false;
        }
        // Treat presence of ID_AUDIO_POSITION / any records types for position as audio-capable;
        // fall back to true if employee has records (legacy checked audio position flag).
        if (Database::columnExists('base_users', 'ID_AUDIO') && false) {
            // reserved
        }
        if (!Database::tableExists('base_admin_statuses')) {
            return true;
        }
        $st = Database::pdo()->prepare(
            'SELECT s.ID FROM base_users u
             INNER JOIN base_admin_statuses s ON s.ID = u.ID_STATUS
             WHERE u.id = ? LIMIT 1'
        );
        $st->execute([$employeeId]);
        return (int)$st->fetchColumn() > 0;
    }

    /** @return array{total:int,parsed:int,pending:int,is_ready:bool} */
    public static function parseStatus(int $employeeId, int $dayStart, int $dayEnd): array
    {
        $out = ['total' => 0, 'parsed' => 0, 'pending' => 0, 'is_ready' => true];
        if ($employeeId < 1 || !Database::tableExists('base_records')) {
            return $out;
        }
        $hasParsed = Database::columnExists('base_records', 'DT_PARSED');
        $sql = 'SELECT COUNT(*) AS total';
        if ($hasParsed) {
            $sql .= ', SUM(CASE WHEN DT_PARSED > 0 THEN 1 ELSE 0 END) AS parsed';
        }
        $sql .= ' FROM base_records
                 WHERE ID_ADMIN = ? AND DT_RECORDED >= ? AND DT_RECORDED <= ?
                   AND (DISABLED IS NULL OR DISABLED < 1)';
        $st = Database::pdo()->prepare($sql);
        $st->execute([$employeeId, $dayStart, $dayEnd]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $out['total'] = (int)($row['total'] ?? 0);
        $out['parsed'] = $hasParsed ? (int)($row['parsed'] ?? 0) : $out['total'];
        $out['pending'] = max(0, $out['total'] - $out['parsed']);
        $out['is_ready'] = $out['pending'] === 0;
        return $out;
    }
}
