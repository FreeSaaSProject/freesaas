<?php
declare(strict_types=1);

/** Collect published staff-report reward markers from tickets/comments. */
final class MonthRewards
{
    /** @var array<string, array<int, array<int, array{reward:bool,ticket:int,no_records:bool,comment_id?:int}>>> */
    private static $forMonthCache = [];

    /**
     * @return array<int, array<int, array{reward:bool,ticket:int,no_records:bool,comment_id?:int}>>
     *         dayNum => adminId => meta
     */
    public static function forMonth(int $year, int $month): array
    {
        if ($year < 2000 || $month < 1 || $month > 12) {
            return [];
        }
        $cacheKey = $year . '-' . $month;
        if (isset(self::$forMonthCache[$cacheKey])) {
            return self::$forMonthCache[$cacheKey];
        }
        if (!Database::tableExists('base_news') || !Database::tableExists('base_comments')) {
            return self::$forMonthCache[$cacheKey] = [];
        }
        $prefix = '[Отчеты:';
        if (class_exists('StaffReportPublisherConfig', false)) {
            $pfx = (string)(StaffReportPublisherConfig::read()['ticket_title_prefix'] ?? '');
            if ($pfx !== '') {
                $prefix = $pfx;
            }
        }

        $tickets = [];
        $st = Database::pdo()->prepare(
            "SELECT ID, NAME FROM base_news
             WHERE TYPE = '2' AND DISABLED = '0' AND NAME LIKE ?"
        );
        $st->execute([$prefix . '%']);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            if (preg_match('/\[Отчеты:(\d+)\]/u', (string)$row['NAME'], $m)
                || preg_match('/\[Reports:(\d+)\]/ui', (string)$row['NAME'], $m)) {
                $tickets[(int)$row['ID']] = (int)$m[1];
            } elseif (preg_match('/:(\d+)\]/u', (string)$row['NAME'], $m)) {
                $tickets[(int)$row['ID']] = (int)$m[1];
            }
        }
        if ($tickets === []) {
            return self::$forMonthCache[$cacheKey] = [];
        }

        $ids = implode(',', array_map('intval', array_keys($tickets)));
        $data = [];
        $sql = "SELECT ID, ID_OBJECT, TEXT FROM base_comments
                WHERE ID_OBJECT IN ($ids) AND TEXT LIKE '%Отчёт за %'";
        $res = Database::pdo()->query($sql);
        if (!$res) {
            return self::$forMonthCache[$cacheKey] = [];
        }
        while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
            $text = (string)$row['TEXT'];
            $plain = html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8');
            if (!preg_match('/Отчёт за (\d{2})\.(\d{2})\.(\d{4})/u', $plain, $m)) {
                continue;
            }
            $d = (int)$m[1];
            $mo = (int)$m[2];
            $y = (int)$m[3];
            if ($mo !== $month || $y !== $year) {
                continue;
            }
            $ticketId = (int)$row['ID_OBJECT'];
            $empId = $tickets[$ticketId] ?? 0;
            if ($empId < 1) {
                continue;
            }
            $reward = (strpos($plain, 'НАЧИСЛЯЕТСЯ') !== false);
            $noRecords = (mb_stripos($plain, 'нет аудиозаписей за день') !== false)
                || (stripos($plain, 'no audio records') !== false);
            if (!isset($data[$d])) {
                $data[$d] = [];
            }
            if (!isset($data[$d][$empId])) {
                $data[$d][$empId] = [
                    'reward' => $reward,
                    'ticket' => $ticketId,
                    'no_records' => $noRecords,
                    'comment_id' => (int)$row['ID'],
                ];
            } else {
                if ($reward) {
                    $data[$d][$empId]['reward'] = true;
                }
                $data[$d][$empId]['no_records'] = $noRecords;
                $data[$d][$empId]['comment_id'] = (int)$row['ID'];
            }
        }
        return self::$forMonthCache[$cacheKey] = $data;
    }

    /**
     * Flat by_admin_day for agent / summary external.
     * @return array{by_admin_day:array<int,array<string,array>>, by_admin:array<int,array{reward_days:int,no_reward_days:int,sum_granted:int}>}
     */
    public static function aggregates(int $year, int $month): array
    {
        $raw = self::forMonth($year, $month);
        $byAdminDay = [];
        $byAdmin = [];
        foreach ($raw as $day => $emps) {
            $date = sprintf('%04d-%02d-%02d', $year, $month, (int)$day);
            foreach ($emps as $adminId => $meta) {
                $adminId = (int)$adminId;
                $byAdminDay[$adminId][$date] = $meta;
                if (!isset($byAdmin[$adminId])) {
                    $byAdmin[$adminId] = ['reward_days' => 0, 'no_reward_days' => 0, 'sum_granted' => 0];
                }
                if (!empty($meta['reward'])) {
                    $byAdmin[$adminId]['reward_days']++;
                } else {
                    $byAdmin[$adminId]['no_reward_days']++;
                }
            }
        }
        return ['by_admin_day' => $byAdminDay, 'by_admin' => $byAdmin, 'days' => $raw];
    }

    /**
     * Published day reports (read-only) for assistant / agents.
     *
     * @return list<array{
     *   emp_id:int,emp_name:string,ticket_id:int,comment_id:int,
     *   reward:bool,no_records:bool,text_plain:string
     * }>
     */
    public static function dayReports(string $dayYmd, int $empFilter = 0): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dayYmd)) {
            return [];
        }
        $year = (int)substr($dayYmd, 0, 4);
        $month = (int)substr($dayYmd, 5, 2);
        $dayNum = (int)substr($dayYmd, 8, 2);
        $raw = self::forMonth($year, $month);
        $emps = is_array($raw[$dayNum] ?? null) ? $raw[$dayNum] : [];
        $out = [];
        foreach ($emps as $empId => $meta) {
            $empId = (int)$empId;
            if ($empFilter > 0 && $empId !== $empFilter) {
                continue;
            }
            if (!is_array($meta)) {
                continue;
            }
            $commentId = (int)($meta['comment_id'] ?? 0);
            $plain = '';
            if ($commentId > 0 && class_exists('CommentsRepository', false)) {
                $row = CommentsRepository::find($commentId);
                if (is_array($row)) {
                    $plain = html_entity_decode(strip_tags((string)($row['TEXT'] ?? '')), ENT_QUOTES, 'UTF-8');
                    if (mb_strlen($plain) > 4000) {
                        $plain = mb_substr($plain, 0, 4000) . '…';
                    }
                }
            }
            $out[] = [
                'emp_id' => $empId,
                'emp_name' => class_exists('TicketWriter', false)
                    ? TicketWriter::employeeName($empId)
                    : ('#' . $empId),
                'ticket_id' => (int)($meta['ticket'] ?? 0),
                'comment_id' => $commentId,
                'reward' => !empty($meta['reward']),
                'no_records' => !empty($meta['no_records']),
                'text_plain' => $plain,
            ];
        }
        return $out;
    }
}
