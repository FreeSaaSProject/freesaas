<?php
declare(strict_types=1);

/**
 * Pre-accepted PRICE_OFFER child comment under the day report (oper massage reward contract).
 * Marker: «Вознаграждение за отчёт DD.MM.YYYY» — stable for find/upsert.
 */
final class DayRewardContract
{
    /**
     * @return array{comment_id:int,action:string}
     *         action: created|updated|disabled|none|skipped
     */
    public static function sync(
        int $ticketId,
        int $employeeId,
        int $reportCommentId,
        string $dayText,
        bool $rewardGranted,
        int $rewardSum,
        int $workingDays
    ): array {
        $empty = ['comment_id' => 0, 'action' => 'skipped'];
        if ($ticketId < 1 || $employeeId < 1 || $dayText === '' || !class_exists('CommentsRepository', false)) {
            return $empty;
        }
        $marker = 'Вознаграждение за отчёт ' . $dayText;
        $existingId = TicketWriter::findCommentId($ticketId, $marker);

        if (!$rewardGranted || $rewardSum < 1) {
            if ($existingId > 0) {
                CommentsRepository::setDisabled($existingId, true);
                return ['comment_id' => $existingId, 'action' => 'disabled'];
            }

            return ['comment_id' => 0, 'action' => 'none'];
        }

        $deadline = self::addWorkingDays($workingDays);
        $text = $marker . '. Основание: аудиоаналитика';
        $acceptorId = self::ticketCurator($ticketId);
        $extra = [
            'PRICE_OFFER' => $rewardSum,
            'DT_DEADLINE' => $deadline,
            'PID' => $reportCommentId > 0 ? $reportCommentId : 0,
            'DT_OFFER_ACCEPT' => time(),
            'DISABLED' => 0,
            'COLOR' => '1',
        ];
        if ($acceptorId > 0) {
            $extra['ID_ACCEPTOR'] = $acceptorId;
        }

        if ($existingId > 0) {
            // Do not touch DT_WORK_ACCEPT — leave acceptance state intact on reparse.
            CommentsRepository::updateFields($existingId, $text, $extra);
            return ['comment_id' => $existingId, 'action' => 'updated'];
        }

        $id = CommentsRepository::add($ticketId, $employeeId, $text, $extra);

        return ['comment_id' => $id, 'action' => $id > 0 ? 'created' : 'skipped'];
    }

    private static function ticketCurator(int $ticketId): int
    {
        if ($ticketId < 1 || !Database::tableExists('base_news')) {
            return 0;
        }
        $st = Database::pdo()->prepare('SELECT ID_CURATOR FROM base_news WHERE ID = ? LIMIT 1');
        $st->execute([$ticketId]);

        return (int)$st->fetchColumn();
    }

    private static function addWorkingDays(int $days): int
    {
        $ts = time();
        $left = max(0, $days);
        while ($left > 0) {
            $ts = strtotime('+1 day', $ts) ?: ($ts + 86400);
            $w = (int)date('N', $ts);
            if ($w < 6) {
                $left--;
            }
        }

        return mktime(23, 59, 59, (int)date('n', $ts), (int)date('j', $ts), (int)date('Y', $ts)) ?: $ts;
    }
}
