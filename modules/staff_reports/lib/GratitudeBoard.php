<?php
declare(strict_types=1);

/**
 * Monthly gratitude summary comment on a configured news/ticket id.
 * Idempotent upsert by month marker. No hardcoded titles.
 */
final class GratitudeBoard
{
    public static function refreshMonth(int $newsId, int $dayStart, int $authorId): void
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
        $y = (int)date('Y', $dayStart);
        $m = (int)date('n', $dayStart);
        $rewards = MonthRewards::forMonth($y, $m);
        $granted = 0;
        $missed = 0;
        foreach ($rewards as $byEmp) {
            foreach ($byEmp as $meta) {
                if (!empty($meta['reward'])) {
                    $granted++;
                } else {
                    $missed++;
                }
            }
        }
        $monthLabel = date('m.Y', $dayStart);
        $marker = '🏆 ' . $monthLabel;
        $body = $marker . "\n"
            . t('staff_reports.gratitude.granted', ['n' => (string)$granted]) . "\n"
            . t('staff_reports.gratitude.missed', ['n' => (string)$missed]);
        if ($authorId < 1) {
            $authorId = TicketWriter::robotId();
        }
        if ($authorId < 1) {
            return;
        }
        TicketWriter::upsertReportComment($newsId, $authorId, $marker, $body);
    }
}
