<?php
declare(strict_types=1);
/** @var string $mode */
/** @var bool $isSuperadmin */
/** @var int $year */
/** @var int $month */
/** @var list<int> $yearOptions */
/** @var list<array<string,mixed>> $months */
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var string $q */
/** @var int $userId */
/** @var int|null $account */
/** @var int|null $direction */
/** @var array<int,string> $staff */
/** @var bool $canSeeOthers */
/** @var bool $canEdit */
/** @var bool $ledgerEnabled */
/** @var bool $canViewLedger */
/** @var array<int,list<int>> $dpsByPayment */
/** @var string $sort */
/** @var string $dir */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */
/** @var array|null $bulkFlash */
/** @var array<string,mixed>|null $dayPayPanel */
/** @var string $actionUrl */
/** @var bool $showBalanceCol */
/** @var array<int,float> $runningBalances */
$path = '/payments';
$maskDescr = static function (string $descr): string {
    return class_exists('DayPayroll', false) ? DayPayroll::maskDescr($descr) : $descr;
};

$listId = 'payments';
$showAll = !empty($showAll);
$isMonthly = ($mode ?? 'monthly') === 'monthly';
$isSuperadmin = !empty($isSuperadmin);
$canEdit = !empty($canEdit);
$ledgerEnabled = !empty($ledgerEnabled);
$canViewLedger = !empty($canViewLedger);
$showBalanceCol = !empty($showBalanceCol) && !$isMonthly;
$runningBalances = is_array($runningBalances ?? null) ? $runningBalances : [];
$dpsByPayment = is_array($dpsByPayment ?? null) ? $dpsByPayment : [];
$months = is_array($months ?? null) ? $months : [];
$yearOptions = is_array($yearOptions ?? null) ? $yearOptions : [];
$fmtMoney = static function ($v): string {
    return number_format((float)$v, 2, '.', ' ');
};
/**
 * Account + DPS in one cell. Account 0 ("no moves") label is omitted.
 *
 * @param array<string,mixed> $r
 */
$accountDpsHtml = static function (array $r) use ($ledgerEnabled, $canViewLedger, $dpsByPayment): string {
    $pid = (int)($r['id'] ?? 0);
    $dir = (int)($r['direction'] ?? 0);
    $account = (int)($r['account'] ?? 0);
    $parts = [];
    if ($account === 1 || $account === 2) {
        $parts[] = '<span class="muted">' . e(PaymentsRepository::accountLabel($account)) . '</span>';
    }
    if ($ledgerEnabled) {
        $ids = $dpsByPayment[$pid] ?? [];
        if ($ids !== []) {
            foreach ($ids as $mid) {
                $label = t('payments.dps.link', ['id' => (int)$mid]);
                if ($canViewLedger) {
                    $parts[] = '<a href="/ledger/moves/' . (int)$mid . '/edit">' . e($label) . '</a>';
                } else {
                    $parts[] = e($label);
                }
            }
        } elseif ($dir < 0 && $account > 0) {
            $noneKey = $account === 2 ? 'payments.dps.none_cash' : 'payments.dps.none_bank';
            $parts[] = '<strong class="money-signed money-out">' . e(t($noneKey)) . '</strong>';
        }
    }
    return $parts === [] ? '' : implode(' ', $parts);
};
/** @param array<string,mixed> $r */
$dtStaffHtml = static function (array $r, bool $canEdit): string {
    $uid = (int)($r['user_id'] ?? 0);
    $dt = (int)($r['dt'] ?? 0);
    $id = (int)($r['id'] ?? 0);
    $html = UsersRepository::badgeHtml($uid, true, true);
    $dtLabel = $dt > 0 ? date('d.m.Y H:i', $dt) : ('#' . $id);
    if ($canEdit && $id > 0) {
        $html .= ' <a href="/payments/' . $id . '/edit">' . e($dtLabel) . '</a>';
    } else {
        $html .= ' <span class="muted">' . e($dt > 0 ? $dtLabel : '—') . '</span>';
    }
    return $html;
};
$monthNames = [
    1 => t('payments.month.1'),
    2 => t('payments.month.2'),
    3 => t('payments.month.3'),
    4 => t('payments.month.4'),
    5 => t('payments.month.5'),
    6 => t('payments.month.6'),
    7 => t('payments.month.7'),
    8 => t('payments.month.8'),
    9 => t('payments.month.9'),
    10 => t('payments.month.10'),
    11 => t('payments.month.11'),
    12 => t('payments.month.12'),
];
$pager = $isMonthly
    ? ''
    : ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, $showAll);
$colSpan = 6 + ($showBalanceCol ? 1 : 0) + BulkActions::colSpanExtra($listId);
$actionUrl = ListQuery::url($path, $filters, []);
?>
<section class="page-head">
  <?php if ($canEdit): ?>
  <div class="page-head-row">
    <div></div>
    <div class="page-actions">
      <a class="btn-primary" href="/payments/new<?= $userId > 0 ? '?user_id=' . (int)$userId : '' ?>"><?= te('core.common.add') ?></a>
    </div>
  </div>
  <?php endif; ?>
</section>

<?php if (!$ledgerEnabled): ?>
  <div class="flash flash-warn"><?= te('payments.flash.dps_stub') ?></div>
<?php endif; ?>
<?php if (!empty($bulkFlash)): ?>
  <?= BulkActions::flashHtml($bulkFlash) ?>
<?php endif; ?>

<?php require __DIR__ . '/_day_pay_panel.php'; ?>

<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <?php if ($isSuperadmin): ?>
  <label><?= te('payments.list.filter_mode') ?>
    <select name="mode">
      <option value="monthly"<?= $isMonthly ? ' selected' : '' ?>><?= te('payments.mode.monthly') ?></option>
      <option value="linear"<?= !$isMonthly ? ' selected' : '' ?>><?= te('payments.mode.linear') ?></option>
    </select>
  </label>
  <?php else: ?>
  <input type="hidden" name="mode" value="monthly">
  <?php endif; ?>
  <label><?= te('payments.list.filter_year') ?>
    <select name="year">
      <?php if (!$isMonthly): ?>
        <option value="0"><?= te('payments.list.filter_all') ?></option>
      <?php endif; ?>
      <?php foreach ($yearOptions as $y): ?>
        <option value="<?= (int)$y ?>"<?= (int)$year === (int)$y ? ' selected' : '' ?>><?= (int)$y ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('payments.list.filter_month') ?>
    <select name="month">
      <option value="0"><?= te('payments.list.filter_all') ?></option>
      <?php foreach ($monthNames as $mi => $mlabel): ?>
        <option value="<?= (int)$mi ?>"<?= (int)$month === (int)$mi ? ' selected' : '' ?>><?= e($mlabel) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('payments.list.search_placeholder') ?>" data-filter-debounce>
  <?php if ($canSeeOthers): ?>
  <label><?= te('payments.list.filter_user') ?>
    <select name="user_id">
      <option value="0"><?= te('payments.list.filter_all') ?></option>
      <?php foreach ($staff as $sid => $sname): ?>
        <option value="<?= (int)$sid ?>"<?= (int)$userId === (int)$sid ? ' selected' : '' ?>><?= e($sname) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <?php endif; ?>
  <label><?= te('payments.list.filter_account') ?>
    <select name="account">
      <option value=""><?= te('payments.list.filter_all') ?></option>
      <?php foreach ([0, 1, 2] as $a): ?>
        <option value="<?= $a ?>"<?= $account !== null && (int)$account === $a ? ' selected' : '' ?>><?= e(PaymentsRepository::accountLabel($a)) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('payments.list.filter_direction') ?>
    <select name="direction">
      <option value=""><?= te('payments.list.filter_all') ?></option>
      <?php foreach ([1, -1] as $d): ?>
        <option value="<?= $d ?>"<?= $direction !== null && (int)$direction === $d ? ' selected' : '' ?>><?= e(PaymentsRepository::directionLabel($d)) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
</form>

<?= $pager ?>

<?= BulkActions::beginForm($listId, $actionUrl) ?>
<?= BulkActions::bar($listId) ?>

<?php if ($isMonthly): ?>
<?php
  $bulkExtra = BulkActions::colSpanExtra($listId);
  $nestedCols = 3 + $bulkExtra;
  $nestedTotalSpan = 2 + $bulkExtra;
  $bulkTh = $bulkExtra > 0 ? '<th class="bulk-check-col"></th>' : '';
?>
<?php if ($bulkExtra > 0): ?>
  <div class="payments-month-select-all">
    <label class="bulk-check-hit">
      <input type="checkbox" data-bulk-select-all title="<?= te('core.bulk.select_all') ?>" aria-label="<?= te('core.bulk.select_all') ?>">
      <?= te('core.bulk.select_all') ?>
    </label>
  </div>
<?php endif; ?>
<div class="payments-monthly">
  <?php if (!$months): ?>
    <p class="muted"><?= te('payments.list.empty') ?></p>
  <?php endif; ?>
  <?php foreach ($months as $block): ?>
    <?php
      $debtStart = (float)$block['debt_start'];
      $debtEnd = (float)$block['debt_end'];
      $totalIn = (float)$block['total_in'];
      $totalOut = (float)$block['total_out'];
      $internalOut = (float)$block['internal_out'];
      $balanced = !empty($block['balanced']);
    ?>
    <div class="payments-month<?= $balanced ? ' is-balanced' : '' ?>">
      <div class="payments-month-head">
        <strong><?= te('payments.month.label', ['ym' => (string)$block['key']]) ?>
          <?php if ($balanced): ?>
            <span class="payments-month-ok" title="<?= te('payments.month.balanced') ?>">✓</span>
          <?php endif; ?>
        </strong>
        <div class="payments-month-debt muted">
          <?php if (abs($debtStart) >= 0.01): ?>
            <?php if ($debtStart < 0): ?>
              <?= te('payments.month.debt_start_staff', ['sum' => $fmtMoney($debtStart)]) ?>
            <?php else: ?>
              <?= te('payments.month.debt_start_org', ['sum' => $fmtMoney($debtStart)]) ?>
            <?php endif; ?>
            <br>
          <?php endif; ?>
          <?= te('payments.month.during', [
              'ym' => (string)$block['key'],
              'in' => $fmtMoney($totalIn),
              'out' => $fmtMoney($totalOut),
          ]) ?>
          <?php if (abs($debtEnd) >= 0.01): ?>
            <br>
            <?php if ($debtEnd < 0): ?>
              <?= te('payments.month.debt_end_staff', ['sum' => $fmtMoney($debtEnd)]) ?>
            <?php else: ?>
              <?= te('payments.month.debt_end_org', ['sum' => $fmtMoney($debtEnd)]) ?>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
      <div class="payments-month-cols">
        <div class="payments-month-col">
          <table class="data payments-nested<?= $bulkExtra > 0 ? ' has-bulk' : '' ?>">
            <thead>
              <tr>
                <?= $bulkTh ?>
                <th><?= te('payments.col.dt') ?></th>
                <th><?= te('payments.col.descr') ?></th>
                <th><?= te('payments.col.sum') ?></th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($block['accrued'] as $r): ?>
              <tr>
                <?= BulkActions::td($listId, (int)$r['id']) ?>
                <td><?= $dtStaffHtml($r, $canEdit) ?></td>
                <td><?= e($maskDescr((string)$r['descr'])) ?></td>
                <td><?= money_signed_html((float)$r['sum'], 1) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$block['accrued']): ?>
              <tr><td colspan="<?= (int)$nestedCols ?>" class="muted"><?= te('payments.month.no_accrued') ?></td></tr>
            <?php endif; ?>
            <tr class="payments-month-total">
              <td colspan="<?= (int)$nestedTotalSpan ?>" class="right"><strong><?= te('payments.month.total_accrued') ?></strong></td>
              <td><?= money_signed_html($totalIn, 1) ?></td>
            </tr>
            </tbody>
          </table>
        </div>
        <div class="payments-month-col">
          <table class="data payments-nested<?= $bulkExtra > 0 ? ' has-bulk' : '' ?>">
            <thead>
              <tr>
                <?= $bulkTh ?>
                <th><?= te('payments.col.dt') ?></th>
                <th><?= te('payments.col.descr') ?></th>
                <th><?= te('payments.col.sum') ?></th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($block['issued'] as $r): ?>
              <tr>
                <?= BulkActions::td($listId, (int)$r['id']) ?>
                <td><?= $dtStaffHtml($r, $canEdit) ?></td>
                <td><?= e($maskDescr((string)$r['descr'])) ?>
                  <?php $ad = $accountDpsHtml($r); if ($ad !== ''): ?>
                    <br><?= $ad ?>
                  <?php endif; ?>
                </td>
                <td><?= money_signed_html((float)$r['sum'], -1) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$block['issued']): ?>
              <tr><td colspan="<?= (int)$nestedCols ?>" class="muted"><?= te('payments.month.no_issued') ?></td></tr>
            <?php endif; ?>
            <tr class="payments-month-total">
              <td colspan="<?= (int)$nestedTotalSpan ?>" class="right">
                <?= te('payments.month.internal', ['sum' => $fmtMoney($internalOut)]) ?>
                <strong><?= te('payments.month.total_issued') ?></strong>
              </td>
              <td><?= money_signed_html($totalOut, -1) ?></td>
            </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php else: ?>
<div class="table-wrap">
  <table class="data">
    <thead>
      <tr>
        <?= BulkActions::th($listId) ?>
        <?= ListQuery::sortTh('ID', 'id', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('payments.col.dt'), 'dt', $sort, $dir, $path, $filters) ?>
        <th><?= te('payments.col.descr') ?></th>
        <?= ListQuery::sortTh(t('payments.col.sum'), 'sum', $sort, $dir, $path, $filters) ?>
        <th><?= te('payments.col.account') ?></th>
        <?php if ($showBalanceCol): ?>
          <th><?= te('payments.col.balance_after') ?></th>
        <?php endif; ?>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $r): ?>
      <?php
        $pid = (int)$r['id'];
        $rowBalance = $showBalanceCol ? ($runningBalances[$pid] ?? null) : null;
      ?>
      <tr>
        <?= BulkActions::td($listId, $pid) ?>
        <td class="muted">
          <?php if ($canEdit): ?>
            <a href="/payments/<?= $pid ?>/edit"><?= $pid ?></a>
          <?php else: ?>
            <?= $pid ?>
          <?php endif; ?>
        </td>
        <td><?= $dtStaffHtml($r, $canEdit) ?></td>
        <td><?= e($maskDescr((string)$r['descr'])) ?></td>
        <td><?= money_signed_html((float)$r['sum'], (int)$r['direction']) ?></td>
        <td><?php $ad = $accountDpsHtml($r); echo $ad !== '' ? $ad : '<span class="muted">—</span>'; ?></td>
        <?php if ($showBalanceCol): ?>
          <td class="payments-balance-after">
            <?php if ($rowBalance !== null): ?>
              <?= money_signed_html(abs((float)$rowBalance), (float)$rowBalance >= 0 ? 1 : -1) ?>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?>
      <tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('payments.list.empty') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?= BulkActions::endForm($listId) ?>

<?= $pager ?>
