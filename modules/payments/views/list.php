<?php
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var string $q */
/** @var int $userId */
/** @var int $typeId */
/** @var int|null $account */
/** @var int|null $direction */
/** @var array<int,string> $staff */
/** @var array<int,string> $typeNames */
/** @var bool $canSeeOthers */
/** @var bool $canEdit */
/** @var bool $ledgerEnabled */
/** @var string $sort */
/** @var string $dir */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */
/** @var array|null $bulkFlash */
$path = '/payments';
$listId = 'payments';
$showAll = !empty($showAll);
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, $showAll);
$colSpan = 8 + BulkActions::colSpanExtra($listId);
$actionUrl = ListQuery::url($path, $filters, []);
?>
<section class="page-head">
  <?php if ($canEdit): ?>
  <div class="page-head-row">
    <div></div>
    <div class="page-actions">
      <a class="btn-primary" href="/payments/new"><?= te('core.common.add') ?></a>
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

<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
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
  <label><?= te('payments.list.filter_type') ?>
    <select name="type_id">
      <option value="0"><?= te('payments.list.filter_all') ?></option>
      <?php foreach ($typeNames as $tid => $tname): ?>
        <option value="<?= (int)$tid ?>"<?= (int)$typeId === (int)$tid ? ' selected' : '' ?>><?= e($tname) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
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
<div class="table-wrap">
  <table class="data">
    <thead>
      <tr>
        <?= BulkActions::th($listId) ?>
        <?= ListQuery::sortTh('ID', 'id', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('payments.col.user'), 'user', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('payments.col.dt'), 'dt', $sort, $dir, $path, $filters) ?>
        <th><?= te('payments.col.descr') ?></th>
        <?= ListQuery::sortTh(t('payments.col.sum'), 'sum', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('payments.col.account'), 'account', $sort, $dir, $path, $filters) ?>
        <th><?= te('payments.col.signed') ?></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $r): ?>
      <tr>
        <?= BulkActions::td($listId, (int)$r['id']) ?>
        <td class="muted">
          <?php if ($canEdit): ?>
            <a href="/payments/<?= (int)$r['id'] ?>/edit"><?= (int)$r['id'] ?></a>
          <?php else: ?>
            <?= (int)$r['id'] ?>
          <?php endif; ?>
        </td>
        <td><?= e((string)($staff[(int)$r['user_id']] ?? ('#' . (int)$r['user_id']))) ?></td>
        <td><?= (int)$r['dt'] > 0 ? e(date('d.m.Y H:i', (int)$r['dt'])) : '—' ?></td>
        <td><?= e(mb_strimwidth((string)$r['descr'], 0, 80, '…')) ?></td>
        <td><?= e(number_format((float)$r['sum'], 2, '.', ' ')) ?>
          <span class="muted"><?= e(PaymentsRepository::directionLabel((int)$r['direction'])) ?></span>
        </td>
        <td><?= e(PaymentsRepository::accountLabel((int)$r['account'])) ?></td>
        <td><?= e(PaymentsRepository::statusLabel($r)) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?>
      <tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('payments.list.empty') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?= BulkActions::endForm($listId) ?>

<?= $pager ?>
