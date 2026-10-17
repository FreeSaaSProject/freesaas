<?php
declare(strict_types=1);

/**
 * Monthly gratitude summary comment on a configured news/ticket id.
 * Idempotent upsert by month marker. Per-employee bonus symbols + highlight for publish day.
 */
final class GratitudeBoard
{
    /**
     * @param list<int> $highlightEmpIds Employees who received a reward in the current publish run
     *        (forces «+» even if MonthRewards re-read is flaky). Empty = derive from $dayStart only.
     */
    public static function refreshMonth(int $newsId, int $dayStart, int $authorId, array $highlightEmpIds = []): void
    {
        if ($newsId < 1 || $dayStart < 1 || !Database::tableExists('base_news')) {
            return;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID FROM base_news WHERE ID = ? AND DISABLED = 0 LIMIT 1'
        );
        $st->execute([$newsId]);
        if ((int)$st->fetchColumn() < 1) {
            return;
        }

        MonthRewards::clearCache();

        $y = (int)date('Y', $dayStart);
        $m = (int)date('n', $dayStart);
        $highlightDay = (int)date('j', $dayStart);
        $emoji = StaffReportPublisherConfig::gratitudeMarkEmoji();
        $rewards = MonthRewards::forMonth($y, $m);

        $forceHighlight = [];
        foreach ($highlightEmpIds as $hid) {
            $hid = (int)$hid;
            if ($hid > 0) {
                $forceHighlight[$hid] = true;
            }
        }

        /** @var array<int, int> $counts */
        $counts = [];
        /** @var array<int, bool> $hasHighlight */
        $hasHighlight = [];

        foreach ($rewards as $dayNum => $byEmp) {
            $dayNum = (int)$dayNum;
            foreach ($byEmp as $empId => $meta) {
                if (empty($meta['reward'])) {
                    continue;
                }
                $empId = (int)$empId;
                if ($empId < 1) {
                    continue;
                }
                if (!isset($counts[$empId])) {
                    $counts[$empId] = 0;
                    $hasHighlight[$empId] = false;
                }
                $counts[$empId]++;
                if ($dayNum === $highlightDay) {
                    $hasHighlight[$empId] = true;
                }
            }
        }

        // Ensure people who got a reward in this run appear even if MonthRewards missed the new comment.
        foreach ($forceHighlight as $empId => $_) {
            if (!isset($counts[$empId])) {
                $counts[$empId] = 1;
                $hasHighlight[$empId] = true;
            } elseif (empty($hasHighlight[$empId])) {
                $counts[$empId]++;
                $hasHighlight[$empId] = true;
            }
        }

        /** @var list<array{name:string,n:int,highlight:bool}> $rows */
        $rows = [];
        foreach ($counts as $empId => $n) {
            if ($n < 1) {
                continue;
            }
            $rows[] = [
                'name' => TicketWriter::employeeName((int)$empId),
                'n' => (int)$n,
                'highlight' => !empty($hasHighlight[$empId]) || !empty($forceHighlight[$empId]),
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            if ($a['n'] !== $b['n']) {
                return $b['n'] <=> $a['n'];
            }
            return strcmp($a['name'], $b['name']);
        });

        $monthName = t('staff_reports.gratitude.month.' . $m);
        if ($monthName === 'staff_reports.gratitude.month.' . $m) {
            $monthName = (string)$m;
        }
        $marker = '🏆 ' . $monthName . ' ' . $y;
        $lines = [$marker];
        $newCount = 0;
        foreach ($rows as $row) {
            if (!empty($row['highlight'])) {
                $newCount++;
            }
            $lines[] = $row['name'] . ': ' . self::formatMarks($emoji, (int)$row['n'], !empty($row['highlight']));
        }
        $when = I18n::formatDate(time(), 'datetime');
        $updated = t('staff_reports.gratitude.updated', ['when' => $when]);
        if ($newCount > 0 && $emoji !== '') {
            $updated .= ' +' . $newCount . $emoji;
        }
        $lines[] = '';
        $lines[] = '<i>' . $updated . '</i>';
        $body = implode("\n", $lines);

        if ($authorId < 1) {
            $authorId = TicketWriter::robotId();
        }
        if ($authorId < 1) {
            return;
        }
        TicketWriter::upsertReportComment($newsId, $authorId, $marker, $body);
    }

    private static function formatMarks(string $emoji, int $n, bool $highlightLast): string
    {
        if ($n < 1 || $emoji === '') {
            return '';
        }
        // Highlight = bonus for the published day: previous days, then + new mark.
        // First bonus of the month → "+🎧" so the plus is still visible.
        if ($highlightLast) {
            if ($n === 1) {
                return '+' . $emoji;
            }
            return str_repeat($emoji, $n - 1) . '+' . $emoji;
        }
        return str_repeat($emoji, $n);
    }
}
