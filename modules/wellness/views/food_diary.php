<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var list<array{key:string,label:string,items:list<array<string,mixed>>,totals:array<string,float|int>}> $meals */
/** @var list<array<string,mixed>> $workouts */
/** @var list<array<string,mixed>> $health */
/** @var array<string,mixed>|null $target */
/** @var array{burned:int,target:array<string,float|int>,left:array<string,int>,advice:string}|null $balance */
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
/** @var array{ok:bool,message:string}|null $quickFlash */
/** @var string $path */
/** @var string $listId */
$meals = is_array($meals ?? null) ? $meals : [];
$balance = is_array($balance ?? null) ? $balance : null;
$health = is_array($health ?? null) ? $health : [];
$workouts = is_array($workouts ?? null) ? $workouts : [];
$showAll = !empty($showAll);
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, $showAll);
$colSpan = 8 + BulkActions::colSpanExtra($listId);
$labelSpan = 3 + BulkActions::colSpanExtra($listId);
$actionUrl = ListQuery::url($path, $filters, []);
$prevDay = date('Y-m-d', strtotime($date . ' -1 day') ?: time());
$nextDay = date('Y-m-d', strtotime($date . ' +1 day') ?: time());
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

<?php if (!empty($quickFlash) && ($quickFlash['message'] ?? '') !== ''): ?>
  <p class="<?= !empty($quickFlash['ok']) ? 'ok' : 'error' ?>"><?= e((string)$quickFlash['message']) ?></p>
<?php endif; ?>

<?php if ($canEdit): ?>
  <?= View::capture('_quick_add_food', ['date' => $date, 'action' => $path], 'wellness') ?>
<?php endif; ?>

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
        <th><?= te('wellness.col.fats') ?></th>
        <th><?= te('wellness.col.carbs') ?></th>
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
            <td><?= e((string)$u['fats']) ?></td>
            <td><?= e((string)$u['carbs']) ?></td>
          </tr>
        <?php endforeach; ?>
        <tr class="wellness-meal-total">
          <td colspan="<?= (int)$labelSpan ?>" class="wellness-meal-total-label">
            <?= te('wellness.meal.subtotal', ['name' => (string)$meal['label']]) ?>
          </td>
          <td><?= e((string)$meal['totals']['weight']) ?>г</td>
          <td><?= (int)$meal['totals']['ccals'] ?></td>
          <td><?= e((string)$meal['totals']['proteins']) ?></td>
          <td><?= e((string)$meal['totals']['fats']) ?></td>
          <td><?= e((string)$meal['totals']['carbs']) ?></td>
        </tr>
      <?php endforeach; ?>
      <tr class="wellness-day-total">
        <td colspan="<?= (int)$labelSpan ?>" class="wellness-meal-total-label">
          <?= te('wellness.food_diary.totals') ?>
        </td>
        <td><?= e((string)$totals['weight']) ?>г</td>
        <td><?= (int)$totals['ccals'] ?></td>
        <td><?= e((string)$totals['proteins']) ?></td>
        <td><?= e((string)$totals['fats']) ?></td>
        <td><?= e((string)$totals['carbs']) ?></td>
      </tr>
    <?php else: ?>
      <tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('wellness.food_diary.empty') ?></td></tr>
    <?php endif; ?>
    <?php if ($balance): ?>
      <?php
        $bt = $balance['target'];
        $bl = $balance['left'];
      ?>
      <tr class="wellness-day-target">
        <td colspan="<?= (int)$labelSpan ?>" class="wellness-meal-total-label">
          <?= te('wellness.food_diary.target_row') ?>
        </td>
        <td></td>
        <td><?= (int)$bt['ccals'] ?></td>
        <td><?= e((string)$bt['proteins']) ?></td>
        <td><?= e((string)$bt['fats']) ?></td>
        <td><?= e((string)$bt['carbs']) ?></td>
      </tr>
      <?php if ((int)$balance['burned'] > 0): ?>
      <tr class="wellness-day-burned">
        <td colspan="<?= (int)$labelSpan ?>" class="wellness-meal-total-label">
          <?= te('wellness.food_diary.burned') ?>
        </td>
        <td></td>
        <td>+<?= (int)$balance['burned'] ?></td>
        <td></td>
        <td></td>
        <td></td>
      </tr>
      <?php endif; ?>
      <tr class="wellness-day-remain">
        <td colspan="<?= (int)$labelSpan ?>" class="wellness-meal-total-label">
          <?= te('wellness.food_diary.remainders') ?>
        </td>
        <td></td>
        <td><span class="<?= e(FitDayBalance::cssClass((int)$bl['ccals'])) ?>"><?= (int)$bl['ccals'] ?></span></td>
        <td><span class="<?= e(FitDayBalance::cssClass((int)$bl['proteins'])) ?>"><?= (int)$bl['proteins'] ?></span></td>
        <td><span class="<?= e(FitDayBalance::cssClass((int)$bl['fats'])) ?>"><?= (int)$bl['fats'] ?></span></td>
        <td><span class="<?= e(FitDayBalance::cssClass((int)$bl['carbs'])) ?>"><?= (int)$bl['carbs'] ?></span></td>
      </tr>
      <?php if ($balance['advice'] !== ''): ?>
      <tr class="wellness-day-advice">
        <td colspan="<?= (int)$colSpan ?>"><?= e($balance['advice']) ?></td>
      </tr>
      <?php endif; ?>
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
</section>

<section class="wellness-day-health">
  <h2><?= te('wellness.food_diary.health') ?></h2>
  <?php if ($canEdit): ?>
    <?= View::capture('_quick_add_health', ['date' => $date, 'action' => $path, 'autofocus' => false], 'wellness') ?>
  <?php endif; ?>
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr>
          <th><?= te('wellness.col.dt') ?></th>
          <th><?= te('wellness.col.symptom') ?></th>
          <th><?= te('wellness.col.medicine') ?></th>
          <th><?= te('wellness.col.level') ?></th>
          <th><?= te('wellness.col.descr') ?></th>
        </tr>
      </thead>
      <tbody>
      <?php
        $symptoms = [];
        $medicines = [];
        foreach ($health as $h) {
            if ((int)($h['symptom_id'] ?? 0) > 0) {
                $symptoms[] = $h;
            } elseif ((int)($h['medicine_id'] ?? 0) > 0) {
                $medicines[] = $h;
            } else {
                $symptoms[] = $h;
            }
        }
      ?>
      <?php if ($symptoms): ?>
        <tr class="wellness-meal-head"><td colspan="5"><strong><?= te('wellness.health_diary.symptoms_group') ?></strong></td></tr>
        <?php foreach ($symptoms as $h): ?>
          <tr>
            <td><?php if ($canEdit): ?><a href="/wellness/health-diary/<?= (int)$h['id'] ?>/edit"><?= e(WellnessUi::formatDt((int)$h['dt'])) ?></a><?php else: ?><?= e(WellnessUi::formatDt((int)$h['dt'])) ?><?php endif; ?></td>
            <td><?= e((string)$h['symptom_name']) ?></td>
            <td><?= e((string)$h['medicine_name']) ?></td>
            <td><?= (int)$h['level'] ?></td>
            <td><?= e((string)$h['descr']) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      <?php if ($medicines): ?>
        <tr class="wellness-meal-head"><td colspan="5"><strong><?= te('wellness.health_diary.medicines_group') ?></strong></td></tr>
        <?php foreach ($medicines as $h): ?>
          <tr>
            <td><?php if ($canEdit): ?><a href="/wellness/health-diary/<?= (int)$h['id'] ?>/edit"><?= e(WellnessUi::formatDt((int)$h['dt'])) ?></a><?php else: ?><?= e(WellnessUi::formatDt((int)$h['dt'])) ?><?php endif; ?></td>
            <td><?= e((string)$h['symptom_name']) ?></td>
            <td><?= e((string)$h['medicine_name']) ?></td>
            <td><?= (int)$h['level'] ?></td>
            <td><?= e((string)$h['descr']) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      <?php if (!$health): ?>
        <tr><td colspan="5" class="muted"><?= te('wellness.health_diary.empty') ?></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
