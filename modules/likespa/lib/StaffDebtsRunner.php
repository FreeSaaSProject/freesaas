<?php
declare(strict_types=1);

/**
 * Oper gens/debts.php: scan staff NFC bracelets via 1C debt_detail, post hits to a news ticket.
 */
final class StaffDebtsRunner
{
    /**
     * @return array{
     *   ok:bool,
     *   skipped:int,
     *   scanned:int,
     *   with_debt:int,
     *   posted:int,
     *   errors:int,
     *   message:string,
     *   lines:list<string>
     * }
     */
    public static function run(): array
    {
        $empty = static function (string $message, bool $ok = true, int $skipped = 1): array {
            return [
                'ok' => $ok,
                'skipped' => $skipped,
                'scanned' => 0,
                'with_debt' => 0,
                'posted' => 0,
                'errors' => 0,
                'message' => $message,
                'lines' => [],
            ];
        };

        if (!LikespaConfig::staffDebtsEnabled()) {
            return $empty(t('likespa.staff_debts.err.disabled'));
        }
        if (!ModuleManager::isEnabled('terminals')
            || !class_exists(StaffBraceletsRepository::class, false)
            || !class_exists(OnecClient::class, false)
            || !class_exists(TerminalsConfig::class, false)
        ) {
            return $empty(t('likespa.staff_debts.err.need_terminals'), false);
        }
        if (!ModuleManager::isEnabled('comments') || !class_exists(CommentsRepository::class, false)) {
            return $empty(t('likespa.staff_debts.err.need_comments'), false);
        }
        if (!TerminalsConfig::onecLive()) {
            return $empty(
                TerminalsConfig::onecEnabled()
                    ? t('likespa.staff_debts.err.onec_config')
                    : t('likespa.staff_debts.err.onec_disabled'),
                false
            );
        }

        $newsId = LikespaConfig::staffDebtsNewsId();
        $authorId = LikespaConfig::staffDebtsCommentUserId();
        if ($newsId < 1) {
            return $empty(t('likespa.staff_debts.err.need_news'), false);
        }
        if ($authorId < 1) {
            return $empty(t('likespa.staff_debts.err.need_author'), false);
        }
        if (!StaffBraceletsRepository::tableReady()) {
            return $empty(t('likespa.staff_debts.err.no_bracelets_table'), false);
        }

        $rows = StaffBraceletsRepository::listAll();
        $scanned = 0;
        $withDebt = 0;
        $posted = 0;
        $errors = 0;
        $lines = [];

        foreach ($rows as $row) {
            $userId = (int)($row['user_id'] ?? 0);
            $uid = (string)($row['uid'] ?? '');
            $name = trim((string)($row['name'] ?? ''));
            if ($name === '') {
                $name = '#' . $userId;
            }
            if ($uid === '') {
                continue;
            }
            $scanned++;

            $card = OnecClient::braceletUidToCard($uid);
            $cardRes = OnecClient::raw('GET', 'client_by_card/?card=' . rawurlencode($card));
            if (empty($cardRes['ok']) || !is_array($cardRes['decoded'] ?? null)) {
                $errors++;
                $lines[] = t('likespa.staff_debts.line.card_fail', [
                    'name' => $name,
                    'err' => (string)($cardRes['error'] ?? 'client_by_card'),
                ]);
                continue;
            }
            $decoded = $cardRes['decoded'];
            $cardRow = is_array($decoded['data'] ?? null) ? $decoded['data'] : $decoded;
            if ($cardRow === $decoded && isset($decoded[0]) && is_array($decoded[0])) {
                $cardRow = $decoded[0];
            }
            $clientId = (string)($cardRow['client_id'] ?? $cardRow['ClientId'] ?? $cardRow['id'] ?? '');
            if ($clientId === '' || $clientId === '00000000-0000-0000-0000-000000000000') {
                $errors++;
                $lines[] = t('likespa.staff_debts.line.no_client', ['name' => $name]);
                continue;
            }

            $debtRes = OnecClient::raw('GET', 'debt_detail/?client_id=' . rawurlencode($clientId));
            if (empty($debtRes['ok']) || !is_array($debtRes['decoded'] ?? null)) {
                $errors++;
                $lines[] = t('likespa.staff_debts.line.debt_fail', [
                    'name' => $name,
                    'err' => (string)($debtRes['error'] ?? 'debt_detail'),
                ]);
                continue;
            }
            $debt = $debtRes['decoded'];
            $totalCost = (float)($debt['TotalCost'] ?? $debt['totalCost'] ?? 0);
            if ($totalCost <= 0) {
                $lines[] = t('likespa.staff_debts.line.clear', ['name' => $name]);
                continue;
            }

            $withDebt++;
            $itemsHtml = '';
            $items = $debt['data'] ?? null;
            if (is_array($items)) {
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $itemsHtml .= '<li>'
                        . e((string)($item['date'] ?? ''))
                        . ' / '
                        . e((string)($item['operation'] ?? ''))
                        . ': '
                        . e((string)($item['cost'] ?? ''))
                        . '</li>';
                }
            }
            $sumDisp = rtrim(rtrim(number_format($totalCost, 2, '.', ''), '0'), '.');
            $log = ' ' . e($name) . ' <b>' . te('likespa.staff_debts.marker') . '</b>: ' . e($sumDisp);
            if ($itemsHtml !== '') {
                $log .= '<br><ul>' . $itemsHtml . '</ul>';
            }

            try {
                CommentsRepository::add($newsId, $authorId, $log);
                $posted++;
                $lines[] = t('likespa.staff_debts.line.posted', [
                    'name' => $name,
                    'sum' => $sumDisp,
                ]);
            } catch (Throwable $e) {
                $errors++;
                $lines[] = t('likespa.staff_debts.line.post_fail', [
                    'name' => $name,
                    'err' => $e->getMessage(),
                ]);
            }
        }

        $message = t('likespa.staff_debts.summary', [
            'scanned' => (string)$scanned,
            'with_debt' => (string)$withDebt,
            'posted' => (string)$posted,
            'errors' => (string)$errors,
        ]);

        return [
            'ok' => $errors === 0,
            'skipped' => 0,
            'scanned' => $scanned,
            'with_debt' => $withDebt,
            'posted' => $posted,
            'errors' => $errors,
            'message' => $message,
            'lines' => $lines,
        ];
    }
}
