<?php
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var string $day */
/** @var string $from */
/** @var string $to */
/** @var bool $canEdit */
/** @var string $sort */
/** @var string $dir */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */
$path = '/terminals/fullstats';
$showAll = !empty($showAll);
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, $showAll);
?>
<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <label><?= te('terminals.fullstats.search_day') ?>
    <input type="date" name="day" value="<?= e($day) ?>">
  </label>
  <label><?= te('terminals.fullstats.from') ?>
    <input type="date" name="from" value="<?= e($from) ?>">
  </label>
  <label><?= te('terminals.fullstats.to') ?>
    <input type="date" name="to" value="<?= e($to) ?>">
  </label>
</form>

<?= $pager ?>

<div class="table-wrap">
  <table class="data">
    <thead>
      <tr>
        <?= ListQuery::sortTh('ID', 'id', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('terminals.fullstats.col.dtstart'), 'dtstart', $sort, $dir, $path, $filters) ?>
        <th><?= te('terminals.fullstats.col.title') ?></th>
        <?= ListQuery::sortTh(t('terminals.fullstats.col.ticket_type'), 'ticket_type', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('terminals.fullstats.col.id_type'), 'id_type', $sort, $dir, $path, $filters) ?>
        <th><?= te('terminals.fullstats.col.is_limited') ?></th>
        <th><?= te('terminals.fullstats.col.is_payer') ?></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $u): ?>
      <tr>
        <td class="muted">
          <?php if ($canEdit): ?>
            <a href="/terminals/fullstats/<?= (int)$u['id'] ?>/edit"><?= (int)$u['id'] ?></a>
          <?php else: ?>
            <?= (int)$u['id'] ?>
          <?php endif; ?>
        </td>
        <td><?= (int)$u['dtstart'] > 0 ? e(date('Y-m-d H:i', (int)$u['dtstart'])) : '—' ?></td>
        <td><?= e((string)$u['title_full']) ?></td>
        <td><?= e((string)$u['ticket_type']) ?></td>
        <td><?= (int)$u['id_type'] ?></td>
        <td><?= !empty($u['is_limited']) ? te('core.common.yes') : te('core.common.no') ?></td>
        <td><?= !empty($u['is_payer']) ? te('core.common.yes') : te('core.common.no') ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?>
      <tr><td colspan="7" class="muted"><?= te('terminals.fullstats.empty') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?= $pager ?>
