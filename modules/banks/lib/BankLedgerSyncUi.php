<?php
declare(strict_types=1);

/** Compact sync / file-import forms for ledger moves list toolbar. */
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

        $ret = ListQuery::url('/ledger/moves', $listFilters, []);
        $parts = [];

        $apiHtml = self::apiSyncFormHtml($accountId, $listFilters, $ret);
        if ($apiHtml !== '') {
            $parts[] = $apiHtml;
        }

        // File CSV only when this ledger account was explicitly bound on /banks.
        $fileLink = BankAccountLinksRepository::fileLinkForLedgerAccount($accountId, 'psb_card');
        if ($fileLink) {
            $num = trim((string)($fileLink['account_number'] ?? ''));
            if ($num === '') {
                $num = (string)($fileLink['external_account_id'] ?? '');
            }
            $parts[] = '<p class="muted banks-import-bound">'
                . e(t('banks.import.bound', [
                    'provider' => BanksConfig::providerLabel('psb_card'),
                    'num' => $num !== '' ? $num : '—',
                ]))
                . '</p>';
            $parts[] = self::importFormHtml($accountId, $ret);
        }

        return implode("\n", $parts);
    }

    /**
     * @param array<string,mixed> $listFilters
     */
    private static function apiSyncFormHtml(int $accountId, array $listFilters, string $ret): string
    {
        $links = BankAccountLinksRepository::forLedgerAccount($accountId);
        if ($links === []) {
            return '';
        }

        [$dateFrom, $dateTo] = self::defaultDates($listFilters);
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
<form class="banks-sync-form banks-sync-form--ledger" method="post" action="/banks/sync-account"
      data-banks-ledger-sync data-account-id="<?= (int)$accountId ?>">
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
<?= View::capture('_longjob_panel', [
  'longjobId' => 'banks-ledger-sync',
  'longjobTitle' => t('banks.conn.sync'),
  'longjobDualBars' => false,
  'longjobShowBytes' => false,
], 'core') ?>
<script>
(function () {
  function boot() {
    var form = document.querySelector('form[data-banks-ledger-sync][data-account-id="<?= (int)$accountId ?>"]');
    var overlay = document.getElementById('banks-ledger-sync-overlay');
    if (!form || !overlay) return;
    if (!window.LongJobPanel) { setTimeout(boot, 40); return; }
    if (form.getAttribute('data-bound') === '1') return;
    form.setAttribute('data-bound', '1');
    var panel = LongJobPanel.mount({
      root: overlay,
      startUrl: '/banks/longjob',
      tickUrl: '/banks/longjob',
      cancelUrl: '/banks/longjob',
      dualBars: false,
      showBytes: false,
      reloadOnOk: true,
      csrf: <?= json_encode(class_exists('Csrf', false) ? Csrf::token() : '', JSON_UNESCAPED_UNICODE) ?>,
      i18n: {
        title: <?= json_encode(t('banks.conn.sync'), JSON_UNESCAPED_UNICODE) ?>,
        starting: <?= json_encode(t('core.longjob.starting'), JSON_UNESCAPED_UNICODE) ?>,
        badJson: <?= json_encode(t('core.longjob.bad_json'), JSON_UNESCAPED_UNICODE) ?>,
        doneTitle: <?= json_encode(t('core.longjob.done_title'), JSON_UNESCAPED_UNICODE) ?>,
        errorTitle: <?= json_encode(t('core.longjob.error_title'), JSON_UNESCAPED_UNICODE) ?>,
        cancelledTitle: <?= json_encode(t('core.longjob.cancelled_title'), JSON_UNESCAPED_UNICODE) ?>,
        ok: <?= json_encode(t('core.longjob.ok'), JSON_UNESCAPED_UNICODE) ?>
      }
    });
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var fd = new FormData(form);
      fd.set('ledger_account_id', form.getAttribute('data-account-id') || '0');
      panel.runFormData(fd, {});
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
</script>
        <?php
        return (string)ob_get_clean();
    }

    private static function importFormHtml(int $accountId, string $ret): string
    {
        ob_start();
        ?>
<form class="banks-sync-form banks-sync-form--ledger banks-import-form" method="post" action="/banks/import-file" enctype="multipart/form-data">
  <input type="hidden" name="account_id" value="<?= (int)$accountId ?>">
  <input type="hidden" name="ret" value="<?= e($ret) ?>">
  <span class="muted banks-sync-form-label"><?= te('banks.import.label') ?></span>
  <label class="banks-import-file">
    <input type="file" name="statement" accept=".csv,text/csv,text/plain" required>
  </label>
  <button type="submit" class="btn-secondary"><?= te('banks.import.submit') ?></button>
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
