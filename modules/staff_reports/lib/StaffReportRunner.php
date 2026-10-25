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

    /**
     * Latest day that may be published.
     * Default: yesterday. With publisher allow_same_day_publish: today (future still blocked).
     */
    public static function lastAllowedDayStart(): int
    {
        $today = (int)mktime(0, 0, 0, (int)date('n'), (int)date('j'), (int)date('Y'));
        $cfg = StaffReportPublisherConfig::read();
        if (!empty($cfg['allow_same_day_publish'])) {
            return $today;
        }

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
            $today = (int)mktime(0, 0, 0, (int)date('n'), (int)date('j'), (int)date('Y'));
            $err = $bounds['day_start'] === $today
                ? t('staff_reports.err.same_day')
                : t('staff_reports.err.future');

            return [
                'day_text' => $bounds['day_text'],
                'date' => $bounds['date'],
                'error' => $err,
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
        $rewardedEmpIds = [];
        $authorId = 0;
        foreach ($emps as $empId) {
            $r = self::generateForEmployee($empId, $bounds);
            $status = (string)($r['status'] ?? 'error');
            if (isset($stats[$status])) {
                $stats[$status]++;
            } else {
                $stats['error']++;
            }
            $results[$empId] = $r;
            if ($status === 'ok' && !empty($r['reward_granted'])) {
                $rewardedEmpIds[] = (int)$empId;
            }
        }
        // One board rebuild after all day comments are written (pass who got bonus this run for «+»).
        $pub = StaffReportPublisherConfig::asTicketConfig();
        $newsId = (int)($pub['gratitude_news_id'] ?? 0);
        if ($newsId > 0) {
            $authorId = TicketWriter::robotId();
            GratitudeBoard::refreshMonth($newsId, $bounds['day_start'], $authorId, $rewardedEmpIds);
        }
        self::log('done ' . $bounds['day_text'] . ' ok=' . $stats['ok'] . ' skipped=' . $stats['skipped'] . ' error=' . $stats['error']
            . ' rewarded=' . count($rewardedEmpIds));
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
        if (!self::employeeHasDayBonusScripts($employeeId)) {
            self::log("$name: skip no_day_bonus_script");
            return ['status' => 'skipped', 'reason' => 'no_day_bonus_script'];
        }

        $config = StaffReportPublisherConfig::asTicketConfig();
        $parse = self::parseStatus($employeeId, $bounds['day_start'], $bounds['day_end']);
        $hasRecords = ((int)$parse['total'] > 0);
        self::log("$name: records={$parse['total']} parsed={$parse['parsed']} pending={$parse['pending']}");

        if (!empty($config['require_full_parse']) && !$parse['is_ready'] && $hasRecords) {
            // Day-quality bonuses use existing collections; one stuck pending record
            // must not block the ticket/PRICE_OFFER when enough audio already parsed.
            if ((int)$parse['parsed'] < 1) {
                self::log("$name: skip not_parsed");
                return ['status' => 'skipped', 'reason' => 'not_parsed', 'has_records' => $hasRecords];
            }
            self::log("$name: warn partial_parse pending={$parse['pending']} (publish anyway)");
        }

        if (!class_exists('DayQualityReport', false)) {
            $path = base_path('modules/audio_reports/lib/DayQualityReport.php');
            if (is_file($path)) {
                require_once $path;
            }
        }
        if (!class_exists('DayQualityReport', false)) {
            self::log("$name: DayQualityReport missing");
            return ['status' => 'error', 'reason' => 'quality_missing', 'has_records' => $hasRecords];
        }

        $quality = DayQualityReport::build($employeeId, $bounds['date']);
        if (empty($quality['publish'])) {
            self::log("$name: skip no_publish");
            return ['status' => 'skipped', 'reason' => 'no_publish', 'has_records' => $hasRecords];
        }

        $criteriaResults = [
            'criteria' => self::qualityPartsAsCriteria($quality),
            'has_red' => !empty($quality['has_red']),
            'has_scoring_groups' => !empty($quality['parts']),
            'scoring_group_count' => count($quality['scripts'] ?? []),
            'quality' => $quality,
        ];

        $ctx = [
            'employee_id' => $employeeId,
            'bounds' => $bounds,
            'config' => $config,
            'day_data' => [
                'unique_clients' => (int)($quality['procedure_count'] ?? 0),
                'windows' => [],
            ],
            'criteria' => $criteriaResults,
            'coverage' => null,
            'quality' => $quality,
            'audio_evaluated' => true,
            'audio_message' => '',
            'has_records' => $hasRecords,
        ];
        $ctx = EventBus::emit('staff_reports.day.after_analyze', $ctx);
        if (is_array($ctx)) {
            $criteriaResults = is_array($ctx['criteria'] ?? null) ? $ctx['criteria'] : $criteriaResults;
            $quality = is_array($ctx['quality'] ?? null) ? $ctx['quality'] : $quality;
        }

        $rewardSum = max(0, (int)($quality['bonus_sum'] ?? 0));
        $rewardPotential = max($rewardSum, (int)($quality['bonus_sum_potential'] ?? $rewardSum));
        $rewardMissed = max(0, (int)($quality['bonus_sum_missed'] ?? ($rewardPotential - $rewardSum)));
        $rewardGranted = !empty($quality['bonus_eligible']) && $rewardSum > 0;
        // Contract + reward footer use eligible sum; full-fail day uses potential for missed totals.
        $rewardDisplaySum = $rewardGranted ? $rewardSum : $rewardPotential;

        $ticketId = TicketWriter::findOrCreateTicket($employeeId, $config);
        if ($ticketId < 1) {
            self::log("$name: ticket error");
            return ['status' => 'error', 'reason' => 'ticket', 'has_records' => $hasRecords];
        }

        $monthTotals = TicketWriter::monthRewardTotals(
            $ticketId,
            $bounds['day_start'],
            $rewardGranted,
            $rewardGranted ? $rewardSum : $rewardPotential,
            $rewardGranted ? $rewardMissed : 0
        );

        $marker = StaffReportTicketText::dayMarker($bounds['day_text']);

        $text = self::formatQualityReport(
            $marker,
            $quality,
            [
                'reward_granted' => $rewardGranted,
                'reward_sum' => $rewardDisplaySum,
                'reward_month_totals' => $monthTotals,
                'day_text' => $bounds['day_text'],
            ]
        );

        $pub = [
            'employee_id' => $employeeId,
            'ticket_id' => $ticketId,
            'text' => $text,
            'marker' => $marker,
            'reward_granted' => $rewardGranted,
            'config' => $config,
            'quality' => $quality,
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
        $newOfferSum = $rewardGranted ? $rewardSum : 0;
        $prevOffer = DayRewardContract::offerState($ticketId, $bounds['day_text']);
        $sumChanged = (int)$prevOffer['sum'] !== $newOfferSum;
        // Read marks: clear only when eligible reward sum changes (not on text-only reparse).
        $up = TicketWriter::upsertReportComment(
            $ticketId,
            $authorId,
            $marker,
            $text,
            $sumChanged
        );
        self::log(
            "$name: " . ($rewardGranted ? 'reward' : 'no-reward')
            . ' comment=' . $up['comment_id']
            . ($sumChanged ? ' sum_changed' : ' sum_same')
            . (!empty($up['views_cleared']) ? ' views_cleared' : '')
        );

        $workingDays = max(0, (int)($config['contract_working_days'] ?? 5));
        $contract = DayRewardContract::sync(
            $ticketId,
            $employeeId,
            (int)$up['comment_id'],
            $bounds['day_text'],
            $rewardGranted,
            $rewardSum, // eligible only
            $workingDays
        );
        $cAction = (string)($contract['action'] ?? '');
        if ($cAction === 'created') {
            self::log("$name: reward contract created #" . (int)$contract['comment_id'] . " sum=$rewardSum");
        } elseif ($cAction === 'updated') {
            self::log("$name: reward contract updated #" . (int)$contract['comment_id'] . " sum=$rewardSum");
        } elseif ($cAction === 'recreated') {
            $del = is_array($contract['payment_deleted'] ?? null) ? $contract['payment_deleted'] : [];
            self::log(
                "$name: reward contract recreated #" . (int)$contract['comment_id']
                . " sum=$rewardSum payments_deleted=" . implode(',', $del)
            );
        } elseif ($cAction === 'disabled') {
            self::log("$name: reward contract disabled #" . (int)$contract['comment_id']);
        } elseif ($cAction === 'unchanged') {
            self::log("$name: reward contract unchanged #" . (int)$contract['comment_id']);
        }

        // Gratitude board is refreshed once from runDay() after the full employee loop.

        $after = [
            'employee_id' => $employeeId,
            'ticket_id' => $ticketId,
            'comment_id' => (int)$up['comment_id'],
            'day_data' => $ctx['day_data'] ?? [],
            'bounds' => $bounds,
            'config' => $config,
            'name' => $name,
            'reward_granted' => $rewardGranted,
            'quality' => $quality,
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
            'has_red' => !empty($quality['has_red']),
            'criteria' => $criteriaResults,
            'quality' => $quality,
            'contract' => $contract,
            'cyclic_llm' => $cyclic,
        ];
    }

    /**
     * @param array<string,mixed> $quality
     * @return list<array<string,mixed>>
     */
    private static function qualityPartsAsCriteria(array $quality): array
    {
        $out = [];
        foreach (is_array($quality['scripts'] ?? null) ? $quality['scripts'] : [] as $script) {
            foreach (is_array($script['parts'] ?? null) ? $script['parts'] : [] as $part) {
                $color = (string)($part['color'] ?? 'none');
                $emoji = $color === 'red' ? '🔴' : ($color === 'yellow' ? '🟡' : ($color === 'green' ? '🟢' : ''));
                $out[] = [
                    'group_key' => (string)($part['name'] ?? ''),
                    'members' => [[
                        'type_id' => (int)($part['part_id'] ?? 0),
                        'name' => (string)($part['name'] ?? ''),
                        'numerator' => (int)($part['numerator'] ?? 0),
                        'denominator' => (int)($part['denominator'] ?? 0),
                        'is_scoring' => !empty($part['is_scoring']),
                    ]],
                    'numerator' => (int)($part['numerator'] ?? 0),
                    'denominator' => (int)($part['denominator'] ?? 0),
                    'percent' => (float)($part['percent'] ?? 0),
                    'color' => $color,
                    'emoji' => $emoji,
                    'is_scoring' => !empty($part['is_scoring']),
                ];
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $quality
     * @param array<string,mixed> $context
     */
    public static function formatQualityReport(string $markerLine, array $quality, array $context): string
    {
        $lines = [];
        $lines[] = $markerLine;
        $lines[] = '';
        $lines[] = '<b>' . t('staff_reports.report.standards') . '</b>';

        $scripts = is_array($quality['scripts'] ?? null) ? $quality['scripts'] : [];
        if ($scripts === []) {
            $lines[] = t('staff_reports.report.no_criteria');
        }
        foreach ($scripts as $script) {
            $lines[] = '<b>' . e((string)($script['name'] ?? '')) . '</b>';
            $dayGates = is_array($script['day_gates'] ?? null) ? $script['day_gates'] : [];
            if ($dayGates !== []) {
                foreach ($dayGates as $dg) {
                    if (!is_array($dg)) {
                        continue;
                    }
                    $label = trim((string)($dg['label'] ?? ''));
                    if ($label === '') {
                        $metricId = (string)($dg['metric'] ?? '');
                        $label = class_exists('DayQualityMetrics', false)
                            ? DayQualityMetrics::metricLabel($metricId)
                            : '';
                        if ($label === '') {
                            $label = $metricId;
                        }
                    }
                    if (!empty($dg['skipped'])) {
                        $lines[] = '○ ' . e($label) . ' ' . t('staff_reports.report.gate_skip');
                        continue;
                    }
                    $color = (string)($dg['color'] ?? '');
                    $emoji = $color === 'red' ? '🔴 ' : ($color === 'green' ? '🟢 ' : '');
                    $opSym = class_exists('DayQualityMetrics', false)
                        ? DayQualityMetrics::opSymbol((string)($dg['op'] ?? 'gte'))
                        : (string)($dg['op'] ?? 'gte');
                    $lines[] = $emoji . e($label) . ' '
                        . e((string)($dg['value'] ?? '')) . ' '
                        . e($opSym) . ' '
                        . e((string)($dg['threshold'] ?? ''));
                }
            } elseif ((int)($script['proc_ratio_min'] ?? 0) > 0 && $script['proc_ratio_pct'] !== null) {
                $emoji = ((string)($script['proc_ratio_color'] ?? '') === 'red') ? '🔴 ' : '';
                $lines[] = $emoji . t('staff_reports.report.proc_ratio', [
                    'pct' => (string)$script['proc_ratio_pct'],
                    'min' => (string)(int)$script['proc_ratio_min'],
                ]);
            }
            foreach (is_array($script['parts'] ?? null) ? $script['parts'] : [] as $part) {
                $color = (string)($part['color'] ?? 'none');
                $emoji = $color === 'red' ? '🔴' : ($color === 'yellow' ? '🟡' : ($color === 'green' ? '🟢' : ''));
                $body = (string)$part['name'] . ' ' . (int)$part['numerator'] . '/' . (int)$part['denominator'];
                if (empty($part['is_scoring'])) {
                    $lines[] = '<i>' . $body . ' ' . t('staff_reports.report.ref') . '</i>';
                } else {
                    $lines[] = ($emoji !== '' ? $emoji . ' ' : '') . $body;
                }
            }
            $scriptSum = (int)($script['day_bonus_sum'] ?? 0);
            if ($scriptSum > 0 || array_key_exists('bonus_eligible', $script)) {
                if (!empty($script['bonus_eligible'])) {
                    $lines[] = t('staff_reports.report.script_bonus_ok', [
                        'name' => (string)($script['name'] ?? ''),
                        'sum' => (string)$scriptSum,
                    ]);
                } else {
                    $lines[] = t('staff_reports.report.script_bonus_no', [
                        'name' => (string)($script['name'] ?? ''),
                        'sum' => (string)$scriptSum,
                    ]);
                }
            }
        }

        $lines[] = '';
        $dayText = (string)($context['day_text'] ?? '');
        $sum = (int)($context['reward_sum'] ?? 0);
        $totals = is_array($context['reward_month_totals'] ?? null) ? $context['reward_month_totals'] : [];
        $granted = !empty($context['reward_granted']);
        $lines[] = '<b>' . e(StaffReportTicketText::rewardFooter($dayText, $sum, $granted)) . '</b>';
        if ($granted) {
            $lines[] = t('staff_reports.report.earned_month', ['n' => (string)(int)($totals['earned'] ?? 0)]);
            $missedN = (int)($totals['missed'] ?? 0);
            if ($missedN > 0) {
                $lines[] = t('staff_reports.report.missed_month', ['n' => (string)$missedN]);
            }
        } else {
            $lines[] = t('staff_reports.report.missed_month', ['n' => (string)(int)($totals['missed'] ?? 0)]);
        }

        return implode("\n", $lines);
    }

    public static function employeeHasDayBonusScripts(int $employeeId): bool
    {
        if (!class_exists('DayQualityReport', false)) {
            $path = base_path('modules/audio_reports/lib/DayQualityReport.php');
            if (is_file($path)) {
                require_once $path;
            }
        }
        if (!class_exists('DayQualityReport', false)) {
            return false;
        }
        foreach (DayQualityReport::loadScriptsForAdmin($employeeId) as $script) {
            if (!empty($script['day_bonus'])) {
                return true;
            }
        }

        return false;
    }

    /** @return list<int> */
    public static function employeesForDay(int $dayStart, int $dayEnd, int $onlyEmp = 0): array
    {
        if ($onlyEmp > 0) {
            return self::employeeHasDayBonusScripts($onlyEmp) ? [$onlyEmp] : [];
        }
        if (!Database::tableExists('base_records') || !Database::tableExists('base_users')) {
            return [];
        }
        // enabled is CHAR in legacy DBs — must compare as string ('1'), not int 1.
        $st = Database::pdo()->prepare(
            "SELECT DISTINCT r.ID_ADMIN AS id
             FROM base_records r
             INNER JOIN base_users u ON u.id = r.ID_ADMIN
             WHERE r.DT_RECORDED >= ? AND r.DT_RECORDED <= ?
               AND (r.DISABLED IS NULL OR r.DISABLED < 1)
               AND u.enabled = '1'"
        );
        $st->execute([$dayStart, $dayEnd]);
        $ids = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $id = (int)($row['id'] ?? 0);
            if ($id > 0 && self::employeeHasDayBonusScripts($id)) {
                $ids[$id] = $id;
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
