<?php
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var string $q */
/** @var int $accountId */
/** @var int $typeId */
/** @var int $year */
/** @var int $month */
/** @var int $paymentId */
/** @var int $supplierId */
/** @var array<int,string> $accounts */
/** @var array<int,string> $types */
/** @var array<int,string> $suppliers */
/** @var list<array<string,mixed>> $recons */
/** @var float|null $balance */
/** @var bool $canEdit */
/** @var bool $canRecon */
/** @var list<int> $years */
/** @var string $sort */
/** @var string $dir */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */
/** @var array|null $bulkFlash */
/** @var string $reconFlash */
/** @var string $reconError */
$path = '/ledger/moves';
$listId = 'ledger_moves';
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, !empty($showAll));
$colSpan = 8 + BulkActions::colSpanExtra($listId);
?>
<section class="page-head">
  <?php if ($canEdit): ?>
  <div class="page-head-row">
    <div></div>
    <div class="page-actions">
      <a class="btn-primary" href="/ledger/moves/new"><?= te('core.common.add') ?></a>
      <a class="btn-secondary" href="/ledger/transfers/new"><?= te('ledger.moves.transfer_new') ?></a>
    </div>
  </div>
  <?php endif; ?>
</section>
<?php if (!empty($bulkFlash)): ?><?= BulkActions::flashHtml($bulkFlash) ?><?php endif; ?>
<?php if ($reconFlash !== ''): ?><div class="flash flash-ok"><?= e($reconFlash) ?></div><?php endif; ?>
<?php if ($reconError !== ''): ?><div class="flash flash-warn"><?= e($reconError) ?></div><?php endif; ?>

<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('ledger.moves.search') ?>" data-filter-debounce>
  <label><?= te('ledger.moves.filter_account') ?>
    <select name="account_id">
      <option value="0"><?= te('ledger.moves.filter_all') ?></option>
      <?php foreach ($accounts as $aid => $aname): ?>
        <option value="<?= (int)$aid ?>"<?= (int)$accountId === (int)$aid ? ' selected' : '' ?>><?= e($aname) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('ledger.moves.filter_type') ?>
    <select name="type_id">
      <option value="0"><?= te('ledger.moves.filter_all') ?></option>
      <?php foreach ($types as $tid => $tname): ?>
        <option value="<?= (int)$tid ?>"<?= (int)$typeId === (int)$tid ? ' selected' : '' ?>><?= e($tname) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('ledger.moves.filter_year') ?>
    <select name="year">
      <option value="0"><?= te('ledger.moves.filter_all') ?></option>
      <?php foreach ($years as $y): ?>
        <option value="<?= (int)$y ?>"<?= (int)$year === (int)$y ? ' selected' : '' ?>><?= (int)$y ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('ledger.moves.filter_month') ?>
    <select name="month">
      <option value="0">—</option>
      <?php for ($m = 1; $m <= 12; $m++): ?>
        <option value="<?= $m ?>"<?= (int)$month === $m ? ' selected' : '' ?>><?= sprintf('%02d', $m) ?></option>
      <?php endfor; ?>
    </select>
  </label>
  <label><?= te('ledger.moves.filter_payment') ?>
    <input type="number" name="payment_id" min="0" value="<?= $paymentId > 0 ? (int)$paymentId : '' ?>">
  </label>
  <?php if ($suppliers): ?>
  <label><?= te('ledger.moves.filter_supplier') ?>
    <select name="supplier_id">
      <option value="0"><?= te('ledger.moves.filter_all') ?></option>
      <?php foreach ($suppliers as $sid => $sname): ?>
        <option value="<?= (int)$sid ?>"<?= (int)$supplierId === (int)$sid ? ' selected' : '' ?>><?= e($sname) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <?php endif; ?>
</form>

<?php if ($accountId > 0 && $balance !== null): ?>
  <p><strong><?= e((string)($accounts[$accountId] ?? '')) ?></strong>:
    <?= e(number_format((float)$balance, 2, '.', ' ')) ?>
  </p>
<?php endif; ?>

<?php if ($canRecon && $accountId > 0): ?>
<section class="panel" style="margin-bottom:1rem">
  <h3><?= te('ledger.recon.title') ?></h3>
  <form method="post" class="form-grid" style="margin-bottom:1rem">
    <input type="hidden" name="recon_action" value="save">
    <input type="hidden" name="recon_account_id" value="<?= (int)$accountId ?>">
    <label><?= te('ledger.recon.bank') ?><input name="bank_balance" value="<?= e(number_format((float)$balance, 2, '.', '')) ?>"></label>
    <label><?= te('ledger.recon.dt') ?><input type="date" name="recon_dt" value="<?= e(date('Y-m-d')) ?>"></label>
    <div class="page-actions"><button class="btn-primary" type="submit"><?= te('ledger.recon.save') ?></button></div>
  </form>
  <?php if ($recons): ?>
  <div class="table-wrap"><table class="data">
    <thead><tr><th>ID</th><th><?= te('ledger.recon.dt') ?></th><th><?= te('ledger.recon.bank') ?></th><th><?= te('ledger.recon.system') ?></th><th></th></tr></thead>
    <tbody>
    <?php foreach ($recons as $rc): ?>
      <tr>
        <td><?= (int)$rc['id'] ?></td>
        <td><?= (int)$rc['dt'] > 0 ? e(date('d.m.Y', (int)$rc['dt'])) : '—' ?></td>
        <td><?= e(number_format((float)$rc['bank_balance'], 2, '.', ' ')) ?></td>
        <td><?= e(number_format((float)$rc['system_balance'], 2, '.', ' ')) ?></td>
        <td>
          <?php if (empty($rc['disabled'])): ?>
          <form method="post" class="inline-form">
            <input type="hidden" name="recon_action" value="disable">
            <input type="hidden" name="recon_id" value="<?= (int)$rc['id'] ?>">
            <input name="reason" placeholder="<?= te('ledger.recon.reason') ?>" required>
            <button type="submit" class="btn-secondary"><?= te('ledger.recon.disable') ?></button>
          </form>
          <?php else: ?>
            <span class="muted"><?= e((string)$rc['reason']) ?></span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php else: ?>
    <p class="muted"><?= te('ledger.recon.empty') ?></p>
  <?php endif; ?>
</section>
<?php endif; ?>

<?= $pager ?>
<?= BulkActions::beginForm($listId, ListQuery::url($path, $filters, [])) ?>
<?= BulkActions::bar($listId) ?>
<div class="table-wrap"><table class="data">
<thead><tr>
  <?= BulkActions::th($listId) ?>
  <?= ListQuery::sortTh('ID', 'id', $sort, $dir, $path, $filters) ?>
  <?= ListQuery::sortTh(t('ledger.moves.col.dt'), 'dt', $sort, $dir, $path, $filters) ?>
  <?= ListQuery::sortTh(t('ledger.moves.col.account'), 'account', $sort, $dir, $path, $filters) ?>
  <th><?= te('ledger.moves.col.type') ?></th>
  <?= ListQuery::sortTh(t('ledger.moves.col.sum'), 'sum', $sort, $dir, $path, $filters) ?>
  <th><?= te('ledger.moves.col.descr') ?></th>
  <th><?= te('ledger.moves.col.confirmed') ?></th>
</tr></thead>
<tbody>
<?php foreach ($items as $r): ?>
<tr>
  <?= BulkActions::td($listId, (int)$r['id']) ?>
  <td class="muted"><?php if ($canEdit): ?><a href="/ledger/moves/<?= (int)$r['id'] ?>/edit"><?= (int)$r['id'] ?></a><?php else: ?><?= (int)$r['id'] ?><?php endif; ?></td>
  <td><?= (int)$r['dt'] > 0 ? e(date('d.m.Y H:i', (int)$r['dt'])) : '—' ?></td>
  <td><?= e((string)($accounts[(int)$r['account_id']] ?? ('#' . (int)$r['account_id']))) ?></td>
  <td><?= e((string)($types[(int)$r['type_id']] ?? ('#' . (int)$r['type_id']))) ?></td>
  <td><?= e(LedgerMovesRepository::directionLabel((int)$r['direction'])) ?> <?= e(number_format((float)$r['summ'], 2, '.', ' ')) ?>
    <?php if ((int)$r['payment_id'] > 0 && ModuleManager::isEnabled('payments')): ?>
      <a class="muted" href="/payments/<?= (int)$r['payment_id'] ?>/edit">#P<?= (int)$r['payment_id'] ?></a>
    <?php endif; ?>
  </td>
  <td><?= e(mb_strimwidth((string)$r['descr'], 0, 60, '…')) ?></td>
  <td><?= empty($r['unconfirmed']) ? te('core.common.yes') : te('core.common.no') ?></td>
</tr>
<?php endforeach; ?>
<?php if (!$items): ?><tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('ledger.moves.empty') ?></td></tr><?php endif; ?>
</tbody></table></div>
<?= BulkActions::endForm($listId) ?>
<?= $pager ?>
