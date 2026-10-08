<?php
/** @var int $year */
/** @var int $month */
/** @var int $prevYear */
/** @var int $prevMonth */
/** @var int $nextYear */
/** @var int $nextMonth */
/** @var int $firstDow */
/** @var int $daysInMonth */
/** @var array $summary */
/** @var string $monthLabel */
$days = $summary['days'] ?? [];
$chartPayload = [
    'labels' => $summary['labels'],
    'eaten' => $summary['eaten'],
    'burned' => $summary['burned'],
    'target' => $summary['target'],
    'weight' => $summary['weight'],
    'i18n' => [
        'eaten' => t('wellness.calendar.eaten'),
        'burned' => t('wellness.calendar.burned'),
        'target' => t('wellness.calendar.target'),
        'weight' => t('wellness.calendar.weight_chart'),
    ],
];
$today = date('Y-m-d');
?>
<section class="page-head wellness-cal-head">
  <nav class="wellness-cal-nav" aria-label="<?= te('wellness.calendar.aria') ?>">
    <a class="btn-secondary" href="/wellness?year=<?= (int)$prevYear ?>&month=<?= (int)$prevMonth ?>" title="<?= te('wellness.calendar.prev') ?>">«</a>
    <form method="get" action="/wellness" class="wellness-cal-jump" data-auto-filter>
      <select name="month">
        <?php for ($m = 1; $m <= 12; $m++): ?>
          <option value="<?= $m ?>"<?= $m === (int)$month ? ' selected' : '' ?>><?= e(date('F', mktime(0, 0, 0, $m, 1))) ?></option>
        <?php endfor; ?>
      </select>
      <select name="year">
        <?php for ($y = (int)date('Y') - 5; $y <= (int)date('Y') + 1; $y++): ?>
          <option value="<?= $y ?>"<?= $y === (int)$year ? ' selected' : '' ?>><?= $y ?></option>
        <?php endfor; ?>
      </select>
    </form>
    <a class="btn-secondary" href="/wellness?year=<?= (int)$nextYear ?>&month=<?= (int)$nextMonth ?>" title="<?= te('wellness.calendar.next') ?>">»</a>
    <strong class="wellness-cal-title"><?= e($monthLabel) ?></strong>
  </nav>
</section>

<section class="wellness-charts" aria-label="<?= te('wellness.calendar.chart_title') ?>">
  <h2 class="wellness-charts-title"><?= te('wellness.calendar.chart_title') ?></h2>
  <?php if ((int)$summary['target_ccals'] > 0): ?>
    <p class="muted"><?= te('wellness.calendar.target') ?>: <?= (int)$summary['target_ccals'] ?> <?= te('wellness.col.ccals') ?></p>
  <?php endif; ?>
  <div class="wellness-chart-wrap"><canvas id="wellnessCalorieChart" height="120"></canvas></div>
  <div class="wellness-chart-wrap wellness-chart-weight"><canvas id="wellnessWeightChart" height="80"></canvas></div>
</section>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
window.WELLNESS_CALENDAR = <?= json_encode($chartPayload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?>;
</script>

<section class="wellness-month-grid" aria-label="<?= te('wellness.calendar.aria') ?>">
  <div class="wellness-month-dow">
    <?php
    $dowLabels = [];
    for ($i = 1; $i <= 7; $i++) {
        $dowLabels[] = date('D', strtotime('2024-01-0' . $i)); // Mon-start week in Jan 2024
    }
    foreach ($dowLabels as $dl): ?>
      <div class="wellness-dow"><?= e($dl) ?></div>
    <?php endforeach; ?>
  </div>
  <div class="wellness-month-cells">
    <?php for ($pad = 1; $pad < $firstDow; $pad++): ?>
      <div class="wellness-day-cell is-empty"></div>
    <?php endfor; ?>
    <?php for ($d = 1; $d <= $daysInMonth; $d++):
        $ymd = sprintf('%04d-%02d-%02d', $year, $month, $d);
        $cell = $days[$ymd] ?? null;
        $has = $cell && (
            (int)($cell['ccals'] ?? 0) > 0
            || (int)($cell['burned'] ?? 0) > 0
            || $cell['weight'] !== null
            || (int)($cell['health'] ?? 0) > 0
            || (int)($cell['doctor_rx'] ?? 0) > 0
        );
        $isToday = $ymd === $today;
        ?>
      <a class="wellness-day-cell<?= $has ? ' has-data' : '' ?><?= $isToday ? ' is-today' : '' ?>"
         href="/wellness/food-diary?date=<?= e(urlencode($ymd)) ?>">
        <span class="wellness-day-num"><?= $d ?></span>
        <?php if ($cell && (int)$cell['ccals'] > 0): ?>
          <span class="wellness-day-kcal"><?= (int)$cell['ccals'] ?></span>
        <?php endif; ?>
        <span class="wellness-day-marks">
          <?php if ($cell && $cell['weight'] !== null): ?><span class="mark mark-w" title="<?= te('wellness.menu.weight') ?>">W</span><?php endif; ?>
          <?php if ($cell && (int)$cell['workouts'] > 0): ?><span class="mark mark-t" title="<?= te('wellness.menu.workouts') ?>">T</span><?php endif; ?>
          <?php if ($cell && (int)$cell['health'] > 0): ?><span class="mark mark-h" title="<?= te('wellness.menu.health_diary') ?>">H</span><?php endif; ?>
          <?php if ($cell && (int)$cell['doctor_rx'] > 0): ?><span class="mark mark-r" title="<?= te('wellness.menu.doctor_rx') ?>">Rx</span><?php endif; ?>
        </span>
      </a>
    <?php endfor; ?>
  </div>
</section>
