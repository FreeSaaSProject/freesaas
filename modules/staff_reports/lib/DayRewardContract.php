<?php
declare(strict_types=1);

/**
 * Pre-accepted PRICE_OFFER child comment under the day report.
 * Marker from staff_reports.report.contract_marker (multi-locale find).
 *
 * Re-® rules:
 * - same eligible sum → leave contract alone (report-only update);
 * - sum changed, no settlement → update PRICE_OFFER in place (keep work accept);
 * - sum changed + settlement with different sum → delete settlement, disable old
 *   offer, create a fresh contract (work accept required again).
 */
final class DayRewardContract
{
    /**
     * Current eligible offer sum for the day (0 = none / disabled).
     *
     * @return array{comment_id:int,sum:int,disabled:bool}
     */
    public static function offerState(int $ticketId, string $dayText): array
    {
        $empty = ['comment_id' => 0, 'sum' => 0, 'disabled' => false];
        if ($ticketId < 1 || $dayText === '' || !class_exists('StaffReportTicketText', false)) {
            return $empty;
        }
        $id = StaffReportTicketText::findContractCommentId($ticketId, $dayText, false);
        if ($id < 1) {
            return $empty;
        }
        $row = CommentsRepository::find($id);
        if (!$row) {
            return $empty;
        }
        $disabled = (int)($row['DISABLED'] ?? 0) > 0;
        $sum = max(0, (int)($row['PRICE_OFFER'] ?? 0));

        return [
            'comment_id' => $id,
            'sum' => $disabled ? 0 : $sum,
            'disabled' => $disabled,
        ];
    }

    /**
     * @return array{comment_id:int,action:string,sum_changed:bool,payment_deleted:list<int>}
     *         action: created|updated|disabled|recreated|unchanged|none|skipped
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
        $empty = [
            'comment_id' => 0,
            'action' => 'skipped',
            'sum_changed' => false,
            'payment_deleted' => [],
        ];
        if ($ticketId < 1 || $employeeId < 1 || $dayText === '' || !class_exists('CommentsRepository', false)) {
            return $empty;
        }

        $newSum = ($rewardGranted && $rewardSum > 0) ? $rewardSum : 0;
        $existingId = class_exists('StaffReportTicketText', false)
            ? StaffReportTicketText::findContractCommentId($ticketId, $dayText, false)
            : 0;
        if ($existingId < 1 && class_exists('StaffReportTicketText', false)) {
            // May need to revive / tear down a disabled prior offer.
            $existingId = StaffReportTicketText::findContractCommentId($ticketId, $dayText, true);
        }

        $oldSum = 0;
        $oldRow = null;
        if ($existingId > 0) {
            $oldRow = CommentsRepository::find($existingId);
            if ($oldRow && (int)($oldRow['DISABLED'] ?? 0) < 1) {
                $oldSum = max(0, (int)($oldRow['PRICE_OFFER'] ?? 0));
            }
        }
        $sumChanged = $oldSum !== $newSum;

        if ($newSum < 1) {
            if ($existingId < 1) {
                return [
                    'comment_id' => 0,
                    'action' => 'none',
                    'sum_changed' => $sumChanged,
                    'payment_deleted' => [],
                ];
            }
            $deleted = self::deleteLinkedPayments($existingId);
            CommentsRepository::setDisabled($existingId, true);
            if (Database::columnExists('base_comments', 'ID_PAYMENT')) {
                Database::pdo()->prepare(
                    'UPDATE base_comments SET ID_PAYMENT = 0 WHERE ID = ?'
                )->execute([$existingId]);
            }

            return [
                'comment_id' => $existingId,
                'action' => 'disabled',
                'sum_changed' => $sumChanged,
                'payment_deleted' => $deleted,
            ];
        }

        // Sum unchanged and active offer → leave contract fields alone.
        if (!$sumChanged && $existingId > 0 && $oldRow && (int)($oldRow['DISABLED'] ?? 0) < 1) {
            return [
                'comment_id' => $existingId,
                'action' => 'unchanged',
                'sum_changed' => false,
                'payment_deleted' => [],
            ];
        }

        $payment = self::linkedPayment($existingId);
        $paymentSum = $payment !== null ? (int)round((float)$payment['sum']) : null;
        $hasPayment = $payment !== null && (int)$payment['payment_id'] > 0;

        // Sum changed + settlement with a different amount → tear down and recreate.
        if ($sumChanged && $hasPayment && $paymentSum !== null && $paymentSum !== $newSum) {
            $deleted = self::deleteLinkedPayments($existingId);
            if ($existingId > 0) {
                CommentsRepository::setDisabled($existingId, true);
                if (Database::columnExists('base_comments', 'ID_PAYMENT')) {
                    Database::pdo()->prepare(
                        'UPDATE base_comments SET ID_PAYMENT = 0, DT_WORK_ACCEPT = 0 WHERE ID = ?'
                    )->execute([$existingId]);
                } else {
                    Database::pdo()->prepare(
                        'UPDATE base_comments SET DT_WORK_ACCEPT = 0 WHERE ID = ?'
                    )->execute([$existingId]);
                }
            }
            $created = self::createOffer(
                $ticketId,
                $employeeId,
                $reportCommentId,
                $dayText,
                $newSum,
                $workingDays
            );

            return [
                'comment_id' => $created,
                'action' => $created > 0 ? 'recreated' : 'skipped',
                'sum_changed' => true,
                'payment_deleted' => $deleted,
            ];
        }

        // No settlement (or payment already matches new sum) → create or update in place.
        if ($existingId > 0 && $oldRow) {
            $deadline = self::addWorkingDays($workingDays);
            $text = StaffReportTicketText::contractBody($dayText);
            $acceptorId = self::ticketCurator($ticketId);
            $extra = [
                'PRICE_OFFER' => $newSum,
                'DT_DEADLINE' => $deadline,
                'PID' => $reportCommentId > 0 ? $reportCommentId : 0,
                'DISABLED' => 0,
                'COLOR' => '1',
            ];
            if ($acceptorId > 0) {
                $extra['ID_ACCEPTOR'] = $acceptorId;
            }
            // Keep DT_OFFER_ACCEPT / DT_WORK_ACCEPT when updating in place.
            CommentsRepository::updateFields($existingId, $text, $extra);

            return [
                'comment_id' => $existingId,
                'action' => 'updated',
                'sum_changed' => $sumChanged,
                'payment_deleted' => [],
            ];
        }

        $created = self::createOffer(
            $ticketId,
            $employeeId,
            $reportCommentId,
            $dayText,
            $newSum,
            $workingDays
        );

        return [
            'comment_id' => $created,
            'action' => $created > 0 ? 'created' : 'skipped',
            'sum_changed' => $sumChanged,
            'payment_deleted' => [],
        ];
    }

    /**
     * @return array{payment_id:int,sum:float}|null
     */
    private static function linkedPayment(int $commentId): ?array
    {
        if ($commentId < 1 || !class_exists('PaymentsRepository', false)
            || !ModuleManager::isEnabled('payments')) {
            return null;
        }
        $payIdByComment = [];
        if (Database::columnExists('base_comments', 'ID_PAYMENT')) {
            $st = Database::pdo()->prepare('SELECT ID_PAYMENT FROM base_comments WHERE ID = ? LIMIT 1');
            $st->execute([$commentId]);
            $pid = (int)$st->fetchColumn();
            if ($pid > 0) {
                $payIdByComment[$commentId] = $pid;
            }
        }
        $map = PaymentsRepository::sumsByCommentIds([$commentId], $payIdByComment);

        return $map[$commentId] ?? null;
    }

    /**
     * @return list<int>
     */
    private static function deleteLinkedPayments(int $commentId): array
    {
        if ($commentId < 1 || !class_exists('PaymentsRepository', false)
            || !ModuleManager::isEnabled('payments')) {
            return [];
        }

        return PaymentsRepository::forceDeleteLinkedToComment($commentId);
    }

    private static function createOffer(
        int $ticketId,
        int $employeeId,
        int $reportCommentId,
        string $dayText,
        int $rewardSum,
        int $workingDays
    ): int {
        $deadline = self::addWorkingDays($workingDays);
        $text = StaffReportTicketText::contractBody($dayText);
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

        return CommentsRepository::add($ticketId, $employeeId, $text, $extra);
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
