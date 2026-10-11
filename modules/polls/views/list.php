<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var string $q */
/** @var string $enabled */
/** @var bool $canEdit */
/** @var string $sort */
/** @var string $dir */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */
/** @var array|null $bulkFlash */
$path = '/polls';
$listId = 'polls';
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
      <a class="btn-primary" href="/polls/new"><?= te('core.common.add') ?></a>
    </div>
  </div>
  <?php endif; ?>
</section>

<?php if (!empty($bulkFlash)): ?>
  <?= BulkActions::flashHtml($bulkFlash) ?>
<?php endif; ?>

<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('polls.list.search_placeholder') ?>" data-filter-debounce>
  <label><?= te('polls.list.filter_enabled') ?>
    <select name="enabled">
      <option value=""<?= $enabled === '' ? ' selected' : '' ?>><?= te('polls.list.filter_all') ?></option>
      <option value="1"<?= $enabled === '1' ? ' selected' : '' ?>><?= te('polls.list.filter_on') ?></option>
      <option value="0"<?= $enabled === '0' ? ' selected' : '' ?>><?= te('polls.list.filter_off') ?></option>
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
        <?= ListQuery::sortTh(t('polls.col.id'), 'id', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('polls.col.name'), 'name', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('polls.col.num'), 'num', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('polls.col.enabled'), 'enabled', $sort, $dir, $path, $filters) ?>
        <th><?= te('polls.col.who_can_pass') ?></th>
        <th><?= te('polls.col.tray') ?></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $u): ?>
      <tr>
        <?= BulkActions::td($listId, (int)$u['id']) ?>
        <td class="muted">
          <?php if ($canEdit): ?>
            <a href="/polls/<?= (int)$u['id'] ?>/edit"><?= (int)$u['id'] ?></a>
          <?php else: ?>
            <?= (int)$u['id'] ?>
          <?php endif; ?>
        </td>
        <td><?= e((string)$u['name']) ?></td>
        <td><?= (int)$u['num'] ?></td>
        <td><?= !empty($u['enabled']) ? te('core.common.yes') : te('core.common.no') ?></td>
        <td><?= e(PollsRepository::whoCanPassLabel((int)$u['who_can_pass'])) ?></td>
        <td class="muted"><?= e((string)($u['tray_code'] ?? '')) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?>
      <tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('polls.list.empty') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?= BulkActions::endForm($listId) ?>

<?= $pager ?>
