<?php
/** @var list<array<string,mixed>> $items */
/** @var list<array{key:string,label:string,items:list<array<string,mixed>>,totals:array<string,float|int>}> $meals */
/** @var list<array<string,mixed>> $workouts */
/** @var list<array<string,mixed>> $health */
/** @var array<string,mixed>|null $target */
/** @var array<string,float|int> $totals */
/** @var int $total */
/** @var string $q */
/** @var string $date */
/** @var bool $canEdit */
/** @var string $sort */
/** @var string $dir */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */
/** @var array|null $bulkFlash */
/** @var string $path */
/** @var string $listId */
$meals = is_array($meals ?? null) ? $meals : [];
$showAll = !empty($showAll);
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, $showAll);
$colSpan = 7 + BulkActions::colSpanExtra($listId);
$actionUrl = ListQuery::url($path, $filters, []);
$prevDay = date('Y-m-d', strtotime($date . ' -1 day') ?: time());
$nextDay = date('Y-m-d', strtotime($date . ' +1 day') ?: time());
$burned = 0;
foreach ($workouts as $w) {
    $burned += (int)($w['ccals'] ?? 0);
}
$leftCcals = null;
if ($target) {
    $leftCcals = (int)$target['ccals'] - (int)$totals['ccals'] + $burned;
}
?>
<section class="page-head">
  <div class="page-head-row">
    <div class="wellness-day-nav">
      <a class="btn-secondary" href="<?= e(ListQuery::url($path, $filters, ['date' => $prevDay])) ?>">«</a>
      <form method="get" action="<?= e($path) ?>" data-auto-filter class="wellness-day-form">
        <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
        <label><?= te('wellness.food_diary.day') ?>
          <input type="date" name="date" value="<?= e($date) ?>">
        </label>
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('wellness.food_diary.search_placeholder') ?>" data-filter-debounce>
      </form>
      <a class="btn-secondary" href="<?= e(ListQuery::url($path, $filters, ['date' => $nextDay])) ?>">»</a>
    </div>
    <?php if ($canEdit): ?>
    <div class="page-actions">
      <a class="btn-primary" href="/wellness/food-diary/new?date=<?= e(urlencode($date)) ?>"><?= te('core.common.add') ?></a>
      <a class="btn-secondary" href="/wellness/calendar"><?= te('wellness.menu.calendar') ?></a>
    </div>
    <?php else: ?>
    <div class="page-actions">
      <a class="btn-secondary" href="/wellness/calendar"><?= te('wellness.menu.calendar') ?></a>
    </div>
    <?php endif; ?>
  </div>
</section>

<div class="wellness-day-summary">
  <strong><?= te('wellness.food_diary.totals') ?>:</strong>
  <?= e((string)$totals['weight']) ?>г
  · <?= (int)$totals['ccals'] ?> <?= te('wellness.col.ccals') ?>
  · <?= te('wellness.col.proteins') ?> <?= e((string)$totals['proteins']) ?>
  · <?= te('wellness.col.fats') ?> <?= e((string)$totals['fats']) ?>
  · <?= te('wellness.col.carbs') ?> <?= e((string)$totals['carbs']) ?>
  <?php if ($target): ?>
    · <?= te('wellness.calendar.target') ?>: <?= (int)$target['ccals'] ?> <?= te('wellness.col.ccals') ?>
    <?php if ($leftCcals !== null): ?>
      · <?= te('wellness.food_diary.left') ?>:
      <span class="<?= $leftCcals < 0 ? 'wellness-left-neg' : 'wellness-left-ok' ?>"><?= (int)$leftCcals ?></span>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php if (!empty($bulkFlash)): ?>
  <?= BulkActions::flashHtml($bulkFlash) ?>
<?php endif; ?>

<?= $pager ?>

<?= BulkActions::beginForm($listId, $actionUrl) ?>
<?= BulkActions::bar($listId) ?>
<div class="table-wrap">
  <table class="data wellness-food-diary">
    <thead>
      <tr>
        <?= BulkActions::th($listId) ?>
        <th>ID</th>
        <th><?= te('wellness.col.dt') ?></th>
        <th><?= te('wellness.col.food') ?></th>
        <th><?= te('wellness.col.weight_g') ?></th>
        <th><?= te('wellness.col.ccals') ?></th>
        <th><?= te('wellness.col.proteins') ?></th>
        <th><?= te('wellness.col.fats') ?> / <?= te('wellness.col.carbs') ?></th>
      </tr>
    </thead>
    <tbody>
    <?php if ($meals): ?>
      <?php foreach ($meals as $meal): ?>
        <tr class="wellness-meal-head">
          <td colspan="<?= (int)$colSpan ?>"><strong><?= e((string)$meal['label']) ?></strong></td>
        </tr>
        <?php foreach ($meal['items'] as $u): ?>
          <tr>
            <?= BulkActions::td($listId, (int)$u['id']) ?>
            <td class="muted">
              <?php if ($canEdit): ?>
                <a href="/wellness/food-diary/<?= (int)$u['id'] ?>/edit"><?= (int)$u['id'] ?></a>
              <?php else: ?>
                <?= (int)$u['id'] ?>
              <?php endif; ?>
            </td>
            <td><?= e(WellnessUi::formatDt((int)$u['dt'])) ?></td>
            <td><?= e((string)$u['food_name']) ?></td>
            <td><?= e((string)$u['weight']) ?>г</td>
            <td><?= (int)$u['ccals'] ?></td>
            <td><?= e((string)$u['proteins']) ?></td>
            <td><?= e((string)$u['fats']) ?> / <?= e((string)$u['carbs']) ?></td>
          </tr>
        <?php endforeach; ?>
        <tr class="wellness-meal-total">
          <td colspan="<?= 3 + BulkActions::colSpanExtra($listId) ?>" class="wellness-meal-total-label">
            <?= te('wellness.meal.subtotal', ['name' => (string)$meal['label']]) ?>
          </td>
          <td><?= e((string)$meal['totals']['weight']) ?>г</td>
          <td><?= (int)$meal['totals']['ccals'] ?></td>
          <td><?= e((string)$meal['totals']['proteins']) ?></td>
          <td><?= e((string)$meal['totals']['fats']) ?> / <?= e((string)$meal['totals']['carbs']) ?></td>
        </tr>
      <?php endforeach; ?>
      <tr class="wellness-day-total">
        <td colspan="<?= 3 + BulkActions::colSpanExtra($listId) ?>" class="wellness-meal-total-label">
          <?= te('wellness.food_diary.totals') ?>
        </td>
        <td><?= e((string)$totals['weight']) ?>г</td>
        <td><?= (int)$totals['ccals'] ?></td>
        <td><?= e((string)$totals['proteins']) ?></td>
        <td><?= e((string)$totals['fats']) ?> / <?= e((string)$totals['carbs']) ?></td>
      </tr>
    <?php else: ?>
      <tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('wellness.food_diary.empty') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?= BulkActions::endForm($listId) ?>

<?= $pager ?>

<section class="wellness-day-side">
  <h2><?= te('wellness.food_diary.workouts') ?></h2>
  <?php if ($workouts): ?>
    <ul>
      <?php foreach ($workouts as $w): ?>
        <li>
          <a href="/wellness/workouts/<?= (int)$w['id'] ?>/edit"><?= e((string)$w['name']) ?></a>
          — +<?= (int)$w['ccals'] ?> <?= te('wellness.col.ccals') ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <p class="muted"><?= te('wellness.workouts.empty') ?></p>
  <?php endif; ?>
  <?php if ($canEdit): ?>
    <a class="btn-secondary" href="/wellness/workouts/new?date=<?= e(urlencode($date)) ?>"><?= te('wellness.workouts.edit_new') ?></a>
  <?php endif; ?>

  <h2><?= te('wellness.food_diary.health') ?></h2>
  <?php if ($health): ?>
    <ul>
      <?php foreach ($health as $h): ?>
        <li>
          <a href="/wellness/health-diary/<?= (int)$h['id'] ?>/edit">
            <?= e((string)($h['symptom_name'] ?: $h['medicine_name'] ?: ('#' . $h['id']))) ?>
          </a>
          <?php if ((int)$h['level'] > 0): ?> · <?= te('wellness.col.level') ?> <?= (int)$h['level'] ?><?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <p class="muted"><?= te('wellness.health_diary.empty') ?></p>
  <?php endif; ?>
  <?php if ($canEdit): ?>
    <a class="btn-secondary" href="/wellness/health-diary/new?date=<?= e(urlencode($date)) ?>"><?= te('wellness.health_diary.edit_new') ?></a>
  <?php endif; ?>
</section>
