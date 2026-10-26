<?php
declare(strict_types=1);

/** Compact sync form for ledger moves list toolbar. */
final class BankLedgerSyncUi
{
    /**
     * @param array<string,mixed> $listFilters filters passed to ListQuery::url
     */
    public static function toolbarHtml(int $accountId, array $listFilters = []): string
    {
        if ($accountId < 1 || !Auth::can('banks.sync')) {
            return '';
        }
        $links = BankAccountLinksRepository::forLedgerAccount($accountId);
        if ($links === []) {
            return '';
        }

        [$dateFrom, $dateTo] = self::defaultDates($listFilters);
        $ret = ListQuery::url('/ledger/moves', $listFilters, []);
        $providers = [];
        foreach ($links as $link) {
            $p = (string)($link['provider'] ?? '');
            if ($p !== '') {
                $providers[$p] = BanksConfig::providerLabel($p);
            }
        }
        $labelExtra = $providers !== [] ? ' · ' . implode(', ', array_values($providers)) : '';

        ob_start();
        ?>
<form class="banks-sync-form banks-sync-form--ledger" method="post" action="/banks/sync-account">
  <input type="hidden" name="account_id" value="<?= (int)$accountId ?>">
  <input type="hidden" name="ret" value="<?= e($ret) ?>">
  <span class="muted banks-sync-form-label"><?= te('banks.conn.sync') ?><?= e($labelExtra) ?></span>
  <label><?= te('banks.conn.sync_from') ?>
    <input type="date" name="date_from" value="<?= e($dateFrom) ?>">
  </label>
  <label><?= te('banks.conn.sync_to') ?>
    <input type="date" name="date_to" value="<?= e($dateTo) ?>">
  </label>
  <button type="submit" class="btn-secondary"><?= te('banks.conn.sync') ?></button>
</form>
        <?php
        return (string)ob_get_clean();
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{0:string,1:string} Y-m-d from, to
     */
    public static function defaultDates(array $filters): array
    {
        $year = (int)($filters['year'] ?? 0);
        $month = (int)($filters['month'] ?? 0);
        if ($year >= 2000 && $month >= 1 && $month <= 12) {
            $from = sprintf('%04d-%02d-01', $year, $month);
            $toTs = strtotime($from . ' +1 month -1 day');
            $to = $toTs !== false ? date('Y-m-d', $toTs) : date('Y-m-d');
            $today = date('Y-m-d');
            if ($to > $today) {
                $to = $today;
            }
            return [$from, $to];
        }
        return [
            date('Y-m-d', strtotime('-7 days')),
            date('Y-m-d'),
        ];
    }
}
