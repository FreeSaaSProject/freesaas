<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var string $q */
/** @var int $idUser */
/** @var int|null $direction */
/** @var bool $canEdit */
/** @var string $sort */
/** @var string $dir */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */
/** @var array|null $bulkFlash */
$path = '/bonuses';
$listId = 'bonuses';
$showAll = !empty($showAll);
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, $showAll);
$colSpan = 7 + BulkActions::colSpanExtra($listId);
$actionUrl = ListQuery::url($path, $filters, []);
?>
<section class="page-head">
  <?php if ($canEdit): ?>
  <div class="page-head-row">
    <div></div>
    <div class="page-actions">
      <a class="btn-primary" href="/bonuses/new"><?= te('core.common.add') ?></a>
    </div>
  </div>
  <?php endif; ?>
</section>

<?php if (!empty($bulkFlash)): ?>
  <?= BulkActions::flashHtml($bulkFlash) ?>
<?php endif; ?>

<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('bonuses.list.search_placeholder') ?>" data-filter-debounce>
  <label><?= te('bonuses.list.filter_client') ?>
    <input type="number" min="0" name="id_user" value="<?= $idUser > 0 ? (int)$idUser : '' ?>">
  </label>
  <label><?= te('bonuses.list.filter_direction') ?>
    <select name="direction">
      <option value=""><?= te('bonuses.list.dir_all') ?></option>
      <option value="1"<?= $direction === 1 ? ' selected' : '' ?>><?= te('bonuses.list.dir_in') ?></option>
      <option value="-1"<?= $direction === -1 ? ' selected' : '' ?>><?= te('bonuses.list.dir_out') ?></option>
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
        <?= ListQuery::sortTh(t('bonuses.col.client'), 'id_user', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('bonuses.col.date_in'), 'date_in', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('bonuses.col.direction'), 'direction', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('bonuses.col.sum'), 'sum', $sort, $dir, $path, $filters) ?>
        <th><?= te('bonuses.col.descr') ?></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $b): ?>
      <tr>
        <?= BulkActions::td($listId, (int)$b['id']) ?>
        <td class="muted">
          <?php if ($canEdit): ?>
            <a href="/bonuses/<?= (int)$b['id'] ?>/edit"><?= (int)$b['id'] ?></a>
          <?php else: ?>
            <?= (int)$b['id'] ?>
          <?php endif; ?>
        </td>
        <td>
          <?php if ((int)$b['id_user'] > 0): ?>
            <a href="/bonuses/clients/<?= (int)$b['id_user'] ?>"><?= (int)$b['id_user'] ?></a>
          <?php else: ?>
            —
          <?php endif; ?>
        </td>
        <td><?= (int)$b['date_in'] > 0 ? e(date('d.m.Y H:i', (int)$b['date_in'])) : '—' ?></td>
        <td><?= e(BonusPayRepository::directionLabel((int)$b['direction'])) ?></td>
        <td><?= e(number_format((float)$b['sum'], 0, '.', ' ')) ?></td>
        <td><?= e((string)($b['description'] ?? '')) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?>
      <tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('bonuses.list.empty') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?= BulkActions::endForm($listId) ?>

<?= $pager ?>
