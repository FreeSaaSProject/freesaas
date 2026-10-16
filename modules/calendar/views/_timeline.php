<?php
declare(strict_types=1);
/**
 * Oper-style timeline grid (week or single day).
 *
 * @var list<array{day:array,shifts:list,chips:list,passed:?array}> $dayColumns
 * @var list<int> $hours
 * @var int $gridHeight
 * @var int $pxPerHour
 * @var int $staffId
 * @var bool $staffLocked
 * @var bool $canCreate
 * @var string $retUrl return URL for new-event links
 * @var string $ariaLabel
 * @var bool $showShiftsRow
 * @var array<string,string> $dayStatsHtml date => html
 * Hour marks live on each $dayColumns[]['hour_marks'][hour] => {html,title}
 */
$staffLocked = !empty($staffLocked);
$canCreate = !empty($canCreate);
$showShiftsRow = $showShiftsRow ?? true;
$dayStatsHtml = is_array($dayStatsHtml ?? null) ? $dayStatsHtml : [];
$retUrl = $retUrl ?? '/calendar';
$ariaLabel = $ariaLabel ?? t('calendar.week.aria');
$dayQs = static function (string $d, int $staff) use ($staffLocked): string {
    $parts = [];
    if ($staff > 0 && !$staffLocked) {
        $parts[] = 'staff=' . $staff;
    }
    $q = $parts ? ('?' . implode('&', $parts)) : '';
    return '/calendar/day/' . rawurlencode($d) . $q;
};
$isDayView = count($dayColumns) === 1;
$tlClass = 'calendar-tl' . ($isDayView ? ' calendar-tl--day' : ' calendar-tl--week');
$canTables = !empty($canTables)
    || (
        class_exists('WorkweeksRepository', false)
        && WorkweeksRepository::canNazcab()
        && (
            WorkweeksRepository::tablesActive()
            || WorkweeksRepository::nazcabBadgesAvailable()
        )
    );
$canTablesPeriod = $canTables
    && class_exists('WorkweeksRepository', false)
    && WorkweeksRepository::canNazcabPeriod();
$tablesToday = date('Y-m-d');
// Per-day «С» only on today. Axis «all» only for EVT_ASSIGN (multi-day badges).
$renderShiftChip = static function (array $sh): string {
    $g = (int)($sh['group'] ?? 0);
    $gClass = 'calendar-tl-shift';
    if ($g === 3) {
        $gClass .= ' is-assist';
    } elseif ($g === 4) {
        $gClass .= ' is-hidden-op';
    } elseif ($g === 2) {
        $gClass .= ' is-admin';
    } elseif ($g === 1) {
        $gClass .= ' is-fl';
    }
    if (!empty($sh['load_min'])) {
        $gClass .= ' is-load-min';
    } elseif (isset($sh['load_pct'])) {
        $gClass .= ' is-load-high';
    }
    $html = '<span class="' . e($gClass) . '"'
        . ' title="' . e((string)($sh['full'] ?? '')) . '"'
        . ' style="background:' . e((string)($sh['bg'] ?? 'var(--bg-input)'))
        . ';color:' . e((string)($sh['fg'] ?? 'var(--text)')) . ';">'
        . e((string)($sh['label'] ?? ''));
    $tableHtml = trim((string)($sh['table_html'] ?? ''));
    if ($tableHtml === '' && !empty($sh['table_label'])) {
        $tableHtml = e((string)$sh['table_label']);
    }
    if ($tableHtml !== '') {
        $html .= '<span class="calendar-tl-shift-table">' . $tableHtml . '</span>';
    }
    $html .= '</span>';
    return $html;
};
?>
<div class="calendar-tl-wrap">
  <table class="<?= e($tlClass) ?>" id="event_container" aria-label="<?= e($ariaLabel) ?>">
    <colgroup>
      <col class="calendar-tl-col-axis">
      <?php foreach ($dayColumns as $_col): ?>
        <col class="calendar-tl-col-day">
      <?php endforeach; ?>
    </colgroup>
    <thead>
      <tr>
        <th class="calendar-tl-axis-h" scope="col"></th>
        <?php foreach ($dayColumns as $col): ?>
          <?php $day = $col['day']; ?>
          <th class="calendar-tl-day-h<?= !empty($day['is_today']) ? ' is-today' : '' ?><?= !empty($day['is_weekend']) ? ' is-weekend' : '' ?>" scope="col">
            <?php if (count($dayColumns) > 1): ?>
              <a href="<?= e($dayQs($day['date'], $staffId)) ?>">
                <span class="calendar-tl-dow"><?= e($day['weekday']) ?></span>
                <span class="calendar-tl-date"><?= e($day['label']) ?></span>
              </a>
            <?php else: ?>
              <span class="calendar-tl-dow"><?= e($day['weekday']) ?></span>
              <span class="calendar-tl-date"><?= e($day['label']) ?></span>
            <?php endif; ?>
          </th>
        <?php endforeach; ?>
      </tr>
      <?php if ($showShiftsRow): ?>
      <tr class="calendar-tl-shifts-row">
        <th class="calendar-tl-axis-h" scope="row">
          <?php
          $anyManage = false;
          foreach ($dayColumns as $c) {
              if (!empty($c['manage'])) {
                  $anyManage = true;
                  break;
              }
          }
          ?>
          <?php if ($anyManage || $canTables): ?>
            <div class="calendar-tl-axis-marks">
              <?php if ($anyManage): ?>
                <button type="button"
                        class="calendar-tl-nazop-all"
                        data-shift-open="all"
                        title="<?= te('workweeks.nazop.title_all') ?>"><?= te('calendar.week.shifts_mark') ?></button>
              <?php else: ?>
                <span class="calendar-tl-shifts-label"><?= te('calendar.week.shifts_mark') ?></span>
              <?php endif; ?>
              <?php if ($canTables): ?>
                <button type="button"
                        class="calendar-tl-nazop-all"
                        data-table-open="<?= e($canTablesPeriod ? 'all' : $tablesToday) ?>"
                        title="<?= $canTablesPeriod
                            ? te('workweeks.nazcab.title_all')
                            : te('workweeks.nazcab.open') ?>"><?= te('workweeks.nazcab.mark') ?></button>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <?= te('calendar.week.shifts_mark') ?>
          <?php endif; ?>
        </th>
        <?php foreach ($dayColumns as $col): ?>
          <?php
          $day = $col['day'];
          $canManage = !empty($col['manage']);
          $dayIsToday = (string)($day['date'] ?? '') === $tablesToday;
          $showTableBtn = $canTables && $dayIsToday;
          ?>
          <td>
            <?php if ($canManage): ?>
              <div class="calendar-tl-shifts-cell">
                <button type="button"
                        class="calendar-tl-shifts calendar-tl-shifts-btn"
                        data-shift-open="<?= e($day['date']) ?>"
                        title="<?= te('calendar.week.manage_shifts') ?>">
                  <?php if (!empty($col['shifts'])): ?>
                    <?php foreach ($col['shifts'] as $sh): ?>
                      <?= $renderShiftChip($sh) ?>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <span class="muted"><?= te('calendar.week.assign_hint') ?></span>
                  <?php endif; ?>
                </button>
                <?php if ($showTableBtn): ?>
                  <button type="button"
                          class="calendar-tl-table-open"
                          data-table-open="<?= e($tablesToday) ?>"
                          title="<?= te('workweeks.nazcab.open') ?>"><?= te('workweeks.nazcab.mark') ?></button>
                <?php endif; ?>
              </div>
            <?php else: ?>
              <div class="calendar-tl-shifts-cell">
                <div class="calendar-tl-shifts">
                  <?php if (!empty($col['shifts'])): ?>
                    <?php foreach ($col['shifts'] as $sh): ?>
                      <?= $renderShiftChip($sh) ?>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <span class="muted">—</span>
                  <?php endif; ?>
                </div>
                <?php if ($showTableBtn): ?>
                  <button type="button"
                          class="calendar-tl-table-open"
                          data-table-open="<?= e($tablesToday) ?>"
                          title="<?= te('workweeks.nazcab.open') ?>"><?= te('workweeks.nazcab.mark') ?></button>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </td>
        <?php endforeach; ?>
      </tr>
      <?php endif; ?>
    </thead>
    <tbody>
      <tr>
        <td class="calendar-tl-axis" style="height:<?= (int)$gridHeight ?>px;">
          <div class="calendar-tl-axis-inner" style="height:<?= (int)$gridHeight ?>px;">
            <?php foreach ($hours as $i => $h): ?>
              <div class="calendar-tl-hour" style="top:<?= (int)($i * $pxPerHour) ?>px;height:<?= (int)$pxPerHour ?>px;"><?= (int)$h ?></div>
            <?php endforeach; ?>
          </div>
        </td>
        <?php foreach ($dayColumns as $col): ?>
          <?php
          $day = $col['day'];
          $d = $day['date'];
          $cellClass = 'calendar-tl-cell';
          if (!empty($day['is_today'])) {
              $cellClass .= ' is-today';
          }
          if (!empty($day['is_weekend'])) {
              $cellClass .= ' is-weekend';
          }
          ?>
          <td class="<?= e($cellClass) ?>" style="height:<?= (int)$gridHeight ?>px;">
            <?php /* Inner wrapper: Chrome/Android % left/width on chips is unreliable on <td>. */ ?>
            <div class="calendar-tl-cell-inner" style="height:<?= (int)$gridHeight ?>px;">
            <?php if (!empty($col['passed'])): ?>
              <div class="calendar-tl-passed<?= !empty($col['passed']['is_now']) ? ' is-now' : '' ?>"
                   style="height:<?= (int)$col['passed']['height'] ?>px;"></div>
            <?php endif; ?>

            <?php
            $hourMarks = is_array($col['hour_marks'] ?? null) ? $col['hour_marks'] : [];
            foreach ($hours as $i => $h):
              $mark = $hourMarks[(int)$h] ?? $hourMarks[(string)$h] ?? null;
              $markHtml = is_array($mark) ? trim((string)($mark['html'] ?? '')) : '';
              $markTitle = is_array($mark) ? trim((string)($mark['title'] ?? '')) : '';
              ?>
              <?php if ($canCreate): ?>
                <a class="calendar-tl-slot"
                   style="top:<?= (int)($i * $pxPerHour) ?>px;height:<?= (int)$pxPerHour ?>px;"
                   href="/calendar/events/new?date=<?= e(rawurlencode($d)) ?>&amp;hour=<?= (int)$h ?>&amp;staff=<?= (int)$staffId ?>&amp;ret=<?= e(rawurlencode($retUrl)) ?>"
                   title="<?= te('calendar.day.add_event') ?>"></a>
              <?php else: ?>
                <div class="calendar-tl-slot is-static"
                     style="top:<?= (int)($i * $pxPerHour) ?>px;height:<?= (int)$pxPerHour ?>px;"></div>
              <?php endif; ?>
              <?php if ($markHtml !== ''): ?>
                <div class="calendar-tl-hour-mark"
                     style="top:<?= (int)($i * $pxPerHour) ?>px;height:<?= (int)$pxPerHour ?>px;"
                     <?php if ($markTitle !== ''): ?>title="<?= e($markTitle) ?>"<?php endif; ?>
                     aria-label="<?= e($markTitle !== '' ? $markTitle : t('calendar.timeline.load_title')) ?>"><?= $markHtml ?></div>
              <?php endif; ?>
            <?php endforeach; ?>

            <?php foreach ($col['chips'] as $chip): ?>
              <?php
              $chipExtra = preg_replace('/[^a-zA-Z0-9_\-\s]/', '', (string)($chip['class'] ?? '')) ?? '';
              $chipExtra = trim(preg_replace('/\s+/', ' ', $chipExtra) ?? '');
              ?>
              <a class="calendar-tl-chip calendar-tl-chip-<?= e((string)$chip['kind']) ?><?= $chipExtra !== '' ? ' ' . e($chipExtra) : '' ?>"
                 href="<?= e((string)$chip['href']) ?>"
                 data-info="<?= e((string)($chip['info'] ?? '')) ?>"
                 <?php if (!empty($chip['id_good'])): ?>data-id-good="<?= (int)$chip['id_good'] ?>"<?php endif; ?>
                 style="top:<?= (int)$chip['top'] ?>px;height:<?= (int)$chip['height'] ?>px;left:<?= e((string)$chip['left']) ?>%;width:<?= e((string)$chip['width']) ?>%;background:<?= e((string)$chip['bg']) ?>;color:<?= e((string)$chip['fg']) ?>;">
                <?= e((string)$chip['label']) ?>
              </a>
            <?php endforeach; ?>
            </div>
          </td>
        <?php endforeach; ?>
      </tr>
      <?php
      $hasStats = false;
      foreach ($dayColumns as $col) {
          $d = (string)($col['day']['date'] ?? '');
          if ($d !== '' && trim((string)($dayStatsHtml[$d] ?? '')) !== '') {
              $hasStats = true;
              break;
          }
      }
      ?>
      <?php if ($hasStats): ?>
      <tr class="calendar-tl-stats-row">
        <td class="calendar-tl-axis-h"></td>
        <?php foreach ($dayColumns as $col): ?>
          <?php
          $d = (string)($col['day']['date'] ?? '');
          $statsHtml = trim((string)($dayStatsHtml[$d] ?? ''));
          ?>
          <td class="calendar-tl-stats<?= !empty($col['day']['is_today']) ? ' is-today' : '' ?><?= !empty($col['day']['is_weekend']) ? ' is-weekend' : '' ?>">
            <?php if ($statsHtml !== ''): ?>
              <div class="cal-day-stats"><?= $statsHtml ?></div>
            <?php endif; ?>
          </td>
        <?php endforeach; ?>
      </tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>
