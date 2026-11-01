<?php
declare(strict_types=1);

/** @var string $path */
/** @var string $day */
/** @var ?string $prevDay */
/** @var ?string $nextDay */
/** @var string $today */
/** @var bool $isToday */
/** @var bool $staleVsToday */
/** @var list<array<string,mixed>> $rows */
/** @var array{matched:int,in_progress:int,missing:int,stale?:int} $summary */
/** @var bool $showCreateTodayCta */
/** @var bool $canRecon */
/** @var bool $canEdit */
/** @var string $flashError */

$urlPrev = $prevDay !== null && $prevDay !== '' ? ListQuery::url($path, ['day' => $prevDay], []) : '';
$urlNext = $nextDay !== null && $nextDay !== '' ? ListQuery::url($path, ['day' => $nextDay], []) : '';
$urlToday = ListQuery::url($path, ['day' => $today], []);
$dayLabel = date('d.m.Y', strtotime($day) ?: time());
$formAction = ListQuery::url($path, ['day' => $day], []);


$pagerAttrs = '';
if ($urlPrev !== '') {
    $pagerAttrs .= ' data-pager-prev="' . e($urlPrev) . '"';
}
if ($urlNext !== '') {
    $pagerAttrs .= ' data-pager-next="' . e($urlNext) . '"';
}
?>
<nav class="pager date-day-nav recons-day-nav" aria-label="<?= te('recons.day.nav_aria') ?>"<?= $pagerAttrs ?>>
  <?php if ($urlPrev !== ''): ?>
    <a class="btn-secondary" href="<?= e($urlPrev) ?>" title="<?= te('recons.day.prev') ?>">‹</a>
  <?php else: ?>
    <span class="btn-secondary recons-nav-disabled" aria-disabled="true">‹</span>
  <?php endif; ?>
  <strong><?= e($dayLabel) ?><?php if (!empty($staleVsToday)): ?><span class="recons-stale-mark" title="<?= te('recons.day.stale_title') ?>">*</span><?php endif; ?></strong>
  <?php if ($urlNext !== ''): ?>
    <a class="btn-secondary" href="<?= e($urlNext) ?>" title="<?= te('recons.day.next') ?>">›</a>
  <?php else: ?>
    <span class="btn-secondary recons-nav-disabled" aria-disabled="true">›</span>
  <?php endif; ?>
  <?php if (!$isToday): ?>
    <a class="btn-secondary" href="<?= e($urlToday) ?>"><?= te('recons.day.today') ?></a>
  <?php endif; ?>
  <span class="muted recons-day-nav-hint"><?= te('recons.day.keyboard') ?></span>
</nav>
<?php if (!empty($staleVsToday)): ?>
  <p class="recons-stale-note"><?= te('recons.day.stale_note') ?></p>
<?php endif; ?>

<?php if ($flashError !== ''): ?>
  <p class="flash flash-error"><?= e($flashError) ?></p>
<?php endif; ?>

<?php if ($showCreateTodayCta): ?>
  <div class="recons-cta">
    <?= te('recons.day.cta_banner', ['day' => $dayLabel]) ?>
    <a class="btn" href="<?= e($urlToday) ?>"><?= te('recons.day.cta_create') ?></a>
  </div>
<?php endif; ?>

<div class="recons-summary">
  <span class="recons-summary-matched"><?= te('recons.day.summary_matched', ['n' => (string)(int)$summary['matched']]) ?></span>
  <span class="recons-summary-progress"><?= te('recons.day.summary_progress', ['n' => (string)(int)$summary['in_progress']]) ?></span>
  <span class="recons-summary-missing"><?= te('recons.day.summary_missing', ['n' => (string)(int)$summary['missing']]) ?></span>
  <?php if ((int)($summary['stale'] ?? 0) > 0): ?>
    <span class="recons-summary-stale"><?= te('recons.day.summary_stale', ['n' => (string)(int)$summary['stale']]) ?></span>
  <?php endif; ?>
</div>

<?php if (!$rows): ?>
  <p class="muted"><?= te('recons.day.empty_accounts') ?></p>
<?php else: ?>
<div class="table-wrap">
<table class="recons-table">
  <thead>
    <tr>
      <th><?= te('recons.day.col.account') ?></th>
      <th><?= te('recons.day.col.bank') ?></th>
      <th><?= te('recons.day.col.system') ?></th>
      <th><?= te('recons.day.col.delta') ?></th>
      <th><?= te('recons.day.col.status') ?></th>
      <th><?= te('recons.day.col.actions') ?></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($rows as $row): ?>
    <?php
      $aid = (int)$row['account_id'];
      $status = (string)$row['status'];
      $rid = (int)$row['recon_id'];
      $delta = $row['delta'];
      $deltaClass = '';
      if ($delta !== null) {
          $deltaClass = abs((float)$delta) < 0.005 ? 'recons-delta-ok' : 'recons-delta-bad';
      }
      $rowClass = !empty($row['enabled']) ? '' : 'recons-disabled-account';
      $fidSave = 'recons-save-' . $aid;
      $fidUpd = 'recons-upd-' . $aid;
      $fidDel = 'recons-del-' . $aid;
      $system = $row['system_balance'];
      $stale = !empty($row['stale']);
      $staleTitle = $stale
          ? te('recons.day.stale_row_title', [
              'live' => number_format((float)($row['live_today'] ?? 0), 2, '.', ' '),
          ])
          : '';
      if ($stale) {
          $rowClass = trim($rowClass . ' recons-row-stale');
      }
    ?>
    <tr class="<?= e($rowClass) ?>">
      <td>
        <a href="<?= e('/ledger/moves?account_id=' . $aid) ?>"><?= e((string)$row['account_name']) ?></a>
        <?php if ($stale): ?>
          <span class="recons-stale-mark" title="<?= e($staleTitle) ?>">*</span>
        <?php endif; ?>
      </td>
      <td class="recons-num">
        <?php if ($canEdit): ?>
          <?php if ($status === 'missing'): ?>
            <form id="<?= e($fidSave) ?>" method="post" action="<?= e($formAction) ?>" class="recons-row-form">
              <input type="hidden" name="recon_action" value="save">
              <input type="hidden" name="recon_account_id" value="<?= $aid ?>">
              <input name="bank_balance" inputmode="decimal" size="12"
                     placeholder="<?= te('recons.day.bank_placeholder') ?>"
                     data-ledger-bank-normalize autocomplete="off">
              <button type="submit" class="btn-secondary"><?= te('recons.day.save') ?></button>
            </form>
          <?php else: ?>
            <form id="<?= e($fidUpd) ?>" method="post" action="<?= e($formAction) ?>" class="recons-row-form">
              <input type="hidden" name="recon_action" value="update">
              <input type="hidden" name="recon_account_id" value="<?= $aid ?>">
              <input type="hidden" name="recon_id" value="<?= $rid ?>">
              <input name="bank_balance" inputmode="decimal" size="12"
                     value="<?= e(number_format((float)$row['bank_balance'], 2, '.', '')) ?>"
                     data-ledger-bank-normalize autocomplete="off">
              <button type="submit" class="btn-secondary"><?= te('recons.day.save') ?></button>
            </form>
          <?php endif; ?>
        <?php elseif ($row['bank_balance'] !== null): ?>
          <?= e(number_format((float)$row['bank_balance'], 2, '.', ' ')) ?>
        <?php else: ?>
          <span class="muted">—</span>
        <?php endif; ?>
      </td>
      <td class="recons-num">
        <?php if ($system !== null): ?>
          <?= e(number_format((float)$system, 2, '.', ' ')) ?>
          <?php if ($stale): ?>
            <span class="recons-stale-mark" title="<?= e($staleTitle) ?>">*</span>
          <?php endif; ?>
        <?php else: ?>
          <span class="muted">—</span>
        <?php endif; ?>
      </td>
      <td class="recons-num">
        <?php if ($delta !== null): ?>
          <span class="<?= e($deltaClass) ?>"><?= e(number_format((float)$delta, 2, '.', ' ')) ?></span>
        <?php else: ?>
          <span class="muted">—</span>
        <?php endif; ?>
      </td>
      <td>
        <span class="recons-status-<?= e($status) ?>"><?= te('recons.day.status.' . $status) ?></span>
      </td>
      <td>
        <div class="recons-actions">
          <a class="btn-secondary" href="<?= e('/ledger/moves?account_id=' . $aid) ?>"><?= te('recons.day.link_moves') ?></a>
          <?php if ($canEdit && $status !== 'missing'): ?>
            <form id="<?= e($fidDel) ?>" method="post" action="<?= e($formAction) ?>" class="recons-row-form">
              <input type="hidden" name="recon_action" value="delete">
              <input type="hidden" name="recon_account_id" value="<?= $aid ?>">
              <input type="hidden" name="recon_id" value="<?= $rid ?>">
              <button type="submit" class="btn-secondary" title="<?= te('recons.day.delete') ?>">✕</button>
            </form>
          <?php endif; ?>
        </div>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
