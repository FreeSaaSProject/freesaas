<?php
declare(strict_types=1);
/**
 * Mini day board for home (events + procedures + orders, no staff/blocks).
 *
 * @var list<int> $hours
 * @var int $gridHeight
 * @var int $pxPerHour
 * @var list<array<string,mixed>> $chips
 * @var bool $myOnly
 */
$hours = $hours ?? [];
$gridHeight = (int)($gridHeight ?? 0);
$pxPerHour = (int)($pxPerHour ?? 40);
$chips = $chips ?? [];
$myOnly = !empty($myOnly);
?>
<?php if ($myOnly): ?>
  <p class="widget-meta muted"><?= te('calendar.widget.upcoming.scope_mine') ?></p>
<?php else: ?>
  <p class="widget-meta muted"><?= te('calendar.widget.upcoming.scope_all') ?></p>
<?php endif; ?>

<?php if (!$chips): ?>
  <p class="muted"><?= te('calendar.widget.board.empty') ?></p>
<?php else: ?>
<div class="calendar-board-wrap">
  <div class="calendar-board" style="height:<?= (int)$gridHeight ?>px;">
    <div class="calendar-board-axis">
      <?php foreach ($hours as $i => $h): ?>
        <div class="calendar-board-hour" style="top:<?= (int)($i * $pxPerHour) ?>px;height:<?= (int)$pxPerHour ?>px;"><?= (int)$h ?></div>
      <?php endforeach; ?>
    </div>
    <div class="calendar-board-cell">
      <?php foreach ($hours as $i => $_h): ?>
        <div class="calendar-board-slot" style="top:<?= (int)($i * $pxPerHour) ?>px;height:<?= (int)$pxPerHour ?>px;"></div>
      <?php endforeach; ?>
      <?php foreach ($chips as $chip): ?>
        <button type="button"
                class="calendar-tl-chip calendar-tl-chip-<?= e((string)$chip['kind']) ?> calendar-board-chip"
                data-info="<?= e((string)($chip['info'] ?? '')) ?>"
                style="top:<?= (int)$chip['top'] ?>px;height:<?= (int)$chip['height'] ?>px;left:<?= e((string)$chip['left']) ?>%;width:<?= e((string)$chip['width']) ?>%;background:<?= e((string)$chip['bg']) ?>;color:<?= e((string)$chip['fg']) ?>;">
          <?= e((string)$chip['label']) ?>
        </button>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<div id="calendar-board-info" class="calendar-info" hidden></div>
<?php endif; ?>
