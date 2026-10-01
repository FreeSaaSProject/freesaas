<?php
/** @var string $date */
/** @var string $dateLabel */
/** @var string $prevDate */
/** @var string $nextDate */
/** @var string $today */
/** @var int $staffId */
/** @var array<int,string> $staffOptions */
/** @var bool $staffLocked */
/** @var list<array<string,mixed>> $orders */
/** @var float $procSum */
/** @var int $eventsCount */
/** @var int $proceduresCount */
/** @var list<array<string,mixed>> $shifts */
/** @var array<int,string> $assignableStaff */
/** @var array<int,bool> $assignedIds */
/** @var bool $canAssign */
/** @var string $shiftFlash */
/** @var string $shiftError */
/** @var bool $canEdit */
/** @var bool $canCreate */
/** @var list $dayColumns */
/** @var list<int> $hours */
/** @var int $gridHeight */
/** @var int $pxPerHour */
/** @var string $retUrl */

$shifts = $shifts ?? [];
$assignableStaff = $assignableStaff ?? [];
$assignedIds = $assignedIds ?? [];
$canAssign = !empty($canAssign);
$shiftFlash = $shiftFlash ?? '';
$shiftError = $shiftError ?? '';
$eventsCount = (int)($eventsCount ?? 0);
$proceduresCount = (int)($proceduresCount ?? 0);

$dayUrl = static function (string $d): string {
    return '/calendar/day/' . rawurlencode($d);
};
$newEventUrl = '/calendar/events/new?date=' . rawurlencode($date);
if ($staffId > 0) {
    $newEventUrl .= '&staff=' . $staffId;
}
$newEventUrl .= '&ret=' . rawurlencode($dayUrl($date));
?>
<section class="page-head">
  <div class="page-head-row">
    <div>
      <h1><?= te('calendar.day.title', ['date' => $dateLabel]) ?></h1>
    </div>
    <div class="page-actions calendar-period-nav" data-calendar-nav="day"
         data-prev="<?= e($dayUrl($prevDate)) ?>"
         data-next="<?= e($dayUrl($nextDate)) ?>">
      <?php if (!empty($canCreate)): ?>
        <a class="btn-primary" href="<?= e($newEventUrl) ?>"><?= te('calendar.day.add_event') ?></a>
      <?php endif; ?>
      <a class="btn-secondary" href="<?= e($dayUrl($prevDate)) ?>" title="<?= te('calendar.day.prev') ?>" aria-label="<?= te('calendar.day.prev') ?>">«</a>
      <a class="btn-secondary" href="<?= e($dayUrl($nextDate)) ?>" title="<?= te('calendar.day.next') ?>" aria-label="<?= te('calendar.day.next') ?>">»</a>
      <a class="btn-secondary" href="/calendar/week/<?= e(rawurlencode($date)) ?>"><?= te('calendar.day.to_week') ?></a>
    </div>
  </div>
</section>

<?php if ($shiftFlash !== ''): ?><div class="flash flash-ok"><?= e($shiftFlash) ?></div><?php endif; ?>
<?php if ($shiftError !== ''): ?><div class="flash flash-warn"><?= e($shiftError) ?></div><?php endif; ?>

<div class="calendar-stats">
  <div class="calendar-stat">
    <span class="calendar-stat-label"><?= te('calendar.day.stat_events') ?></span>
    <strong><?= (int)$eventsCount ?></strong>
  </div>
  <div class="calendar-stat">
    <span class="calendar-stat-label"><?= te('calendar.day.stat_orders') ?></span>
    <strong><?= count($orders) ?></strong>
  </div>
  <div class="calendar-stat">
    <span class="calendar-stat-label"><?= te('calendar.day.stat_procedures') ?></span>
    <strong><?= (int)$proceduresCount ?></strong>
  </div>
  <div class="calendar-stat">
    <span class="calendar-stat-label"><?= te('calendar.day.stat_sum') ?></span>
    <strong><?= e(number_format($procSum, 0, '.', ' ')) ?></strong>
  </div>
  <div class="calendar-stat">
    <span class="calendar-stat-label"><?= te('workweeks.day.sec') ?></span>
    <strong><?= count($shifts) ?></strong>
  </div>
</div>

<?php
$ariaLabel = t('calendar.day.aria');
$showShiftsRow = true;
require __DIR__ . '/_timeline.php';
?>

<div id="calendar-info" class="calendar-info" hidden></div>
<script>
(function () {
  var nav = document.querySelector('[data-calendar-nav="day"]');
  document.addEventListener('keydown', function (e) {
    if (!nav) return;
    if (e.altKey || e.ctrlKey || e.metaKey || e.shiftKey) return;
    var tag = (e.target && e.target.tagName) ? e.target.tagName.toLowerCase() : '';
    if (tag === 'input' || tag === 'textarea' || tag === 'select' || (e.target && e.target.isContentEditable)) return;
    if (e.key === 'ArrowLeft') {
      e.preventDefault();
      location.href = nav.getAttribute('data-prev');
    } else if (e.key === 'ArrowRight') {
      e.preventDefault();
      location.href = nav.getAttribute('data-next');
    }
  });

  var info = document.getElementById('calendar-info');
  var touch = ('ontouchstart' in window) || (navigator.maxTouchPoints > 0);
  var openChip = null;
  function hideInfo() {
    if (!info) return;
    info.hidden = true;
    info.innerHTML = '';
    openChip = null;
  }
  function showInfo(chip, fromTouch) {
    if (!info || !chip) return;
    var html = chip.getAttribute('data-info') || '';
    if (!html) return;
    info.innerHTML = html + (fromTouch
      ? '<div class="calendar-info-actions"><button type="button" class="btn-secondary btn-sm" data-info-close><?= te('core.common.cancel') ?></button></div>'
      : '');
    info.hidden = false;
    var rect = chip.getBoundingClientRect();
    info.style.top = (rect.bottom + window.scrollY + 4) + 'px';
    info.style.left = Math.max(8, rect.left + window.scrollX) + 'px';
    openChip = chip;
    var closer = info.querySelector('[data-info-close]');
    if (closer) closer.addEventListener('click', hideInfo);
  }
  document.querySelectorAll('.calendar-tl-chip').forEach(function (chip) {
    if (!touch) {
      chip.addEventListener('mouseenter', function () { showInfo(chip, false); });
      chip.addEventListener('mouseleave', function () { hideInfo(); });
    } else {
      chip.addEventListener('click', function (e) {
        if (openChip === chip) return;
        e.preventDefault();
        showInfo(chip, true);
      });
    }
  });
})();
</script>

<section class="calendar-section">
<?php
$formAction = '/calendar/day/' . $date;
$showHeading = true;
require base_path('modules/workweeks/views/_day_panel.php');
?>
</section>

<section class="calendar-section">
  <h2><?= te('calendar.day.sec_orders') ?></h2>
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr>
          <th><?= te('calendar.day.col_time') ?></th>
          <th><?= te('calendar.day.col_client') ?></th>
          <th><?= te('calendar.day.col_status') ?></th>
          <th><?= te('calendar.day.col_sum') ?></th>
          <th>ID</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($orders as $ord): ?>
        <tr>
          <td><?= e(EventsRepository::formatTime((int)($ord['dt_event'] ?? 0))) ?></td>
          <td>
            <?php if ((int)($ord['id_user'] ?? 0) > 0): ?>
              <a href="/clients/<?= (int)$ord['id_user'] ?>">#<?= (int)$ord['id_user'] ?></a>
            <?php else: ?>
              —
            <?php endif; ?>
            <?php if (!empty($ord['phone'])): ?>
              <div class="muted"><?= e((string)$ord['phone']) ?></div>
            <?php endif; ?>
          </td>
          <td><?= e((string)($ord['status'] ?? '—')) ?></td>
          <td><?= e(number_format((float)($ord['summ_real'] ?? 0), 0, '.', ' ')) ?></td>
          <td><a href="/orders/<?= (int)$ord['id'] ?>">#<?= (int)$ord['id'] ?></a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$orders): ?>
        <tr><td colspan="5" class="muted"><?= te('calendar.day.empty_orders') ?></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
