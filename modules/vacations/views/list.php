<?php
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var string $q */
/** @var int $userId */
/** @var int $sign */
/** @var array<int,string> $staff */
/** @var bool $canSeeOthers */
/** @var bool $canEdit */
/** @var string $sort */
/** @var string $dir */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */
/** @var array|null $bulkFlash */
$path = '/vacations';
$listId = 'vacations';
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
      <a class="btn-primary" href="/vacations/new"><?= te('core.common.add') ?></a>
    </div>
  </div>
  <?php endif; ?>
</section>

<?php if (!empty($bulkFlash)): ?>
  <?= BulkActions::flashHtml($bulkFlash) ?>
<?php endif; ?>

<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('vacations.list.search_placeholder') ?>" data-filter-debounce>
  <?php if ($canSeeOthers): ?>
  <label><?= te('vacations.list.filter_user') ?>
    <select name="user_id">
      <option value="0"><?= te('vacations.list.filter_all') ?></option>
      <?php foreach ($staff as $sid => $sname): ?>
        <option value="<?= (int)$sid ?>"<?= (int)$userId === (int)$sid ? ' selected' : '' ?>><?= e($sname) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <?php endif; ?>
  <label><?= te('vacations.list.filter_sign') ?>
    <select name="sign">
      <option value="0"><?= te('vacations.list.sign_any') ?></option>
      <option value="1"<?= (int)$sign === 1 ? ' selected' : '' ?>><?= te('vacations.list.sign_pending') ?></option>
      <option value="2"<?= (int)$sign === 2 ? ' selected' : '' ?>><?= te('vacations.list.sign_done') ?></option>
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
        <?= ListQuery::sortTh(t('vacations.col.user'), 'user', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('vacations.col.type'), 'type', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('vacations.col.start'), 'start', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('vacations.col.end'), 'end', $sort, $dir, $path, $filters) ?>
        <th><?= te('vacations.col.days') ?></th>
        <th><?= te('vacations.col.sign') ?></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $r): ?>
      <tr>
        <?= BulkActions::td($listId, (int)$r['id']) ?>
        <td class="muted">
          <?php if ($canEdit): ?>
            <a href="/vacations/<?= (int)$r['id'] ?>/edit"><?= (int)$r['id'] ?></a>
          <?php else: ?>
            <?= (int)$r['id'] ?>
          <?php endif; ?>
        </td>
        <td><?= e((string)($staff[(int)$r['user_id']] ?? ('#' . (int)$r['user_id']))) ?></td>
        <td><?= e(VacationsRepository::typeLabel((int)$r['id_type'])) ?></td>
        <td><?= (int)$r['dt_start'] > 0 ? e(date('d.m.Y', (int)$r['dt_start'])) : '—' ?></td>
        <td><?= (int)$r['dt_end'] > 0 ? e(date('d.m.Y', (int)$r['dt_end'])) : '—' ?></td>
        <td><?= (int)$r['days_long'] ?></td>
        <td><?= e(VacationsRepository::signLabel($r)) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?>
      <tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('vacations.list.empty') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?= BulkActions::endForm($listId) ?>

<?= $pager ?>
