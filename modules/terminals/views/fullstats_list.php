<?php
declare(strict_types=1);
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

$day = (string)$day;
$dayNavActive = (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $day);
$today = date('Y-m-d');
$isToday = ($dayNavActive && $day === $today);
$urlToday = ListQuery::url($path, $filters, ['day' => $today, 'from' => '', 'to' => '', 'page' => 1]);
$urlPrev = '';
$urlNext = '';
$dayLabel = '';
if ($dayNavActive) {
    $focus = $day;
    $prevDay = date('Y-m-d', strtotime($focus . ' -1 day') ?: time());
    $nextDay = date('Y-m-d', strtotime($focus . ' +1 day') ?: time());
    $urlPrev = ListQuery::url($path, $filters, ['day' => $prevDay, 'from' => '', 'to' => '', 'page' => 1]);
    $urlNext = ListQuery::url($path, $filters, ['day' => $nextDay, 'from' => '', 'to' => '', 'page' => 1]);
    $dayLabel = I18n::formatDate(strtotime($focus . ' 12:00:00') ?: time(), 'date');
}
?>
<section class="page-head">
  <div class="page-actions" style="gap:0.5rem;align-items:center;flex-wrap:wrap">
    <?php if ($dayNavActive): ?>
      <nav class="pager" aria-label="<?= te('terminals.day_nav.aria') ?>"
           data-pager-prev="<?= e($urlPrev) ?>"
           data-pager-next="<?= e($urlNext) ?>">
        <a class="btn-secondary" href="<?= e($urlPrev) ?>" title="<?= te('terminals.day_nav.prev') ?>" aria-label="<?= te('terminals.day_nav.prev') ?>">‹</a>
        <strong><?= e($dayLabel) ?></strong>
        <a class="btn-secondary" href="<?= e($urlNext) ?>" title="<?= te('terminals.day_nav.next') ?>" aria-label="<?= te('terminals.day_nav.next') ?>">›</a>
        <?php if (!$isToday): ?>
          <a class="btn-secondary" href="<?= e($urlToday) ?>"><?= te('terminals.day_nav.today') ?></a>
        <?php else: ?>
          <span class="pill"><?= te('terminals.day_nav.today') ?></span>
        <?php endif; ?>
        <span class="muted"><?= te('terminals.day_nav.keyboard') ?></span>
      </nav>
    <?php else: ?>
      <a class="btn-secondary" href="<?= e($urlToday) ?>"><?= te('terminals.day_nav.today') ?></a>
    <?php endif; ?>
  </div>
</section>

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
