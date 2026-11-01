<?php
declare(strict_types=1);

final class ReconsDayBoard
{
    /** Idle zero accounts: no moves in this many months (calendar). */
    public const IDLE_MONTHS = 3;

    /**
     * @return array{
     *   day:string,
     *   day_start:int,
     *   day_end:int,
     *   is_today:bool,
     *   is_latest_recon_day:bool,
     *   is_editable:bool,
     *   stale_vs_today:bool,
     *   show_zeros:bool,
     *   hidden_zero_count:int,
     *   latest_recon_day:?string,
     *   prev_day:?string,
     *   next_day:?string,
     *   today:string,
     *   rows:list<array<string,mixed>>,
     *   summary:array{matched:int,in_progress:int,missing:int,stale:int}
     * }
     */
    public static function build(string $dayYmd, bool $showZeros = false): array
    {
        $today = date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dayYmd)) {
            $dayYmd = $today;
        }
        $parts = explode('-', $dayYmd);
        $dayStart = mktime(0, 0, 0, (int)$parts[1], (int)$parts[2], (int)$parts[0]);
        if ($dayStart === false) {
            $dayStart = mktime(0, 0, 0) ?: time();
            $dayYmd = date('Y-m-d', $dayStart);
        }
        $dayEnd = $dayStart + 86400 - 1;
        $isToday = ($dayYmd === $today);

        $latestStart = LedgerReconciliationsRepository::latestDayStartWithAny();
        $latestReconDay = $latestStart !== null ? date('Y-m-d', $latestStart) : null;
        $isLatestReconDay = ($latestReconDay !== null && $dayYmd === $latestReconDay);
        // Editable: calendar today (start new) OR the latest day that already has recons (open session).
        $isEditable = $isToday || $isLatestReconDay;
        // Latest recon session is not for calendar today → mark as stale vs today.
        $staleVsToday = $isLatestReconDay && !$isToday;

        $prevStart = LedgerReconciliationsRepository::nearestDayStartBefore($dayStart);
        $nextStart = LedgerReconciliationsRepository::nearestDayStartAfter($dayEnd);
        $prevDay = $prevStart !== null ? date('Y-m-d', $prevStart) : null;
        $nextDay = $nextStart !== null ? date('Y-m-d', $nextStart) : null;

        $recons = LedgerReconciliationsRepository::listActiveForDay($dayStart, $dayEnd);
        /** @var array<int,array<string,mixed>> $byAccount */
        $byAccount = [];
        foreach ($recons as $rec) {
            $aid = (int)($rec['account_id'] ?? 0);
            if ($aid < 1 || isset($byAccount[$aid])) {
                continue;
            }
            $byAccount[$aid] = $rec;
        }

        $accounts = LedgerAccountsRepository::list([
            'order' => 'NAME ASC',
            'limit' => 0,
        ]);
        /** @var array<int,array<string,mixed>> $accountMap */
        $accountMap = [];
        foreach ($accounts['items'] as $acc) {
            $aid = (int)($acc['id'] ?? 0);
            if ($aid < 1) {
                continue;
            }
            $accountMap[$aid] = $acc;
        }

        $ids = [];
        foreach ($accountMap as $aid => $acc) {
            if ((int)($acc['enabled'] ?? 0) > 0) {
                $ids[$aid] = true;
            }
        }
        foreach (array_keys($byAccount) as $aid) {
            $ids[$aid] = true;
        }

        // Missing rows: live only for calendar today; otherwise balance as of that day.
        $balances = LedgerMovesRepository::balancesByAccount($isToday ? null : $dayEnd);
        // Live ledger balances (idle-zero filter + stale markers).
        $liveToday = LedgerMovesRepository::balancesByAccount(null);

        $sinceDt = strtotime('-' . self::IDLE_MONTHS . ' months') ?: (time() - self::IDLE_MONTHS * 30 * 86400);
        $activeSince = LedgerMovesRepository::accountIdsWithMovesSince($sinceDt);

        $rows = [];
        $summary = ['matched' => 0, 'in_progress' => 0, 'missing' => 0, 'stale' => 0];
        $hiddenZeroCount = 0;
        $sortedIds = array_keys($ids);
        usort($sortedIds, static function (int $a, int $b) use ($accountMap): int {
            $na = (string)($accountMap[$a]['name'] ?? ('#' . $a));
            $nb = (string)($accountMap[$b]['name'] ?? ('#' . $b));
            $cmp = strcasecmp($na, $nb);
            return $cmp !== 0 ? $cmp : ($a <=> $b);
        });

        foreach ($sortedIds as $aid) {
            $acc = $accountMap[$aid] ?? null;
            $name = $acc !== null ? (string)$acc['name'] : ('#' . $aid);
            $enabled = $acc !== null ? ((int)($acc['enabled'] ?? 0) > 0) : false;
            $rec = $byAccount[$aid] ?? null;
            $asOf = (float)($balances[$aid] ?? 0.0);
            $live = (float)($liveToday[$aid] ?? 0.0);
            $hasRecon = $rec !== null;
            $hasRecentMoves = isset($activeSince[$aid]);
            // Idle zero: current ledger balance ~0 and no ДПС in last N months (still show if recon that day).
            $isIdleZero = !$hasRecon && abs($live) < 0.005 && !$hasRecentMoves;
            if ($isIdleZero && !$showZeros) {
                $hiddenZeroCount++;
                continue;
            }

            if ($rec === null) {
                $stale = $staleVsToday && abs($asOf - $live) >= 0.005;
                if ($stale) {
                    $summary['stale']++;
                }
                $rows[] = [
                    'account_id' => $aid,
                    'account_name' => $name,
                    'enabled' => $enabled,
                    'recon_id' => 0,
                    'bank_balance' => null,
                    'system_balance' => $asOf,
                    'saved_system' => null,
                    'live_today' => $live,
                    'stale' => $stale,
                    'idle_zero' => $isIdleZero,
                    'delta' => null,
                    'status' => 'missing',
                    'dt' => 0,
                ];
                $summary['missing']++;
                continue;
            }

            $bank = round((float)$rec['bank_balance'], 2);
            // Always show SYSTEM snapshot from the reconciliation date (not live ledger).
            $savedSystem = round((float)$rec['system_balance'], 2);
            $delta = round($bank - $savedSystem, 2);
            $status = abs($delta) < 0.005 ? 'matched' : 'in_progress';
            $summary[$status]++;
            // Snapshot differs from live ledger today → not актуально на сегодня.
            $stale = ($isLatestReconDay || $isToday) && abs($savedSystem - $live) >= 0.005;
            if ($stale) {
                $summary['stale']++;
            }

            $rows[] = [
                'account_id' => $aid,
                'account_name' => $name,
                'enabled' => $enabled,
                'recon_id' => (int)$rec['id'],
                'bank_balance' => $bank,
                'system_balance' => $savedSystem,
                'saved_system' => $savedSystem,
                'live_today' => $live,
                'stale' => $stale,
                'idle_zero' => false,
                'delta' => $delta,
                'status' => $status,
                'dt' => (int)$rec['dt'],
            ];
        }

        return [
            'day' => $dayYmd,
            'day_start' => $dayStart,
            'day_end' => $dayEnd,
            'is_today' => $isToday,
            'is_latest_recon_day' => $isLatestReconDay,
            'is_editable' => $isEditable,
            'stale_vs_today' => $staleVsToday,
            'show_zeros' => $showZeros,
            'hidden_zero_count' => $hiddenZeroCount,
            'latest_recon_day' => $latestReconDay,
            'prev_day' => $prevDay,
            'next_day' => $nextDay,
            'today' => $today,
            'rows' => $rows,
            'summary' => $summary,
        ];
    }

    /**
     * Resolve landing day when ?day= is absent.
     *
     * @return array{day:string,show_create_today_cta:bool}
     */
    public static function resolveLandingDay(): array
    {
        $today = date('Y-m-d');
        $todayStart = mktime(0, 0, 0) ?: time();
        $todayEnd = $todayStart + 86400 - 1;
        if (LedgerReconciliationsRepository::countActiveForDay($todayStart, $todayEnd) > 0) {
            return ['day' => $today, 'show_create_today_cta' => false];
        }
        $latestStart = LedgerReconciliationsRepository::latestDayStartWithAny();
        if ($latestStart !== null) {
            return [
                'day' => date('Y-m-d', $latestStart),
                'show_create_today_cta' => true,
            ];
        }
        return ['day' => $today, 'show_create_today_cta' => false];
    }
}
