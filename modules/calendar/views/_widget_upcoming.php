<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var bool $myOnly */
$items = $items ?? [];
$myOnly = !empty($myOnly);
?>
<?php if ($myOnly): ?>
  <p class="widget-meta muted"><?= te('calendar.widget.upcoming.scope_mine') ?></p>
<?php else: ?>
  <p class="widget-meta muted"><?= te('calendar.widget.upcoming.scope_all') ?></p>
<?php endif; ?>

<?php if (!$items): ?>
  <p class="muted"><?= te('calendar.widget.upcoming.empty') ?></p>
<?php else: ?>
  <ul class="widget-event-list">
    <?php foreach ($items as $row): ?>
      <?php
        $ts = (int)($row['dtstart'] ?? 0);
        $end = (int)($row['dtend'] ?? 0);
        $when = $ts > 0 ? date('H:i', $ts) : '—';
        if ($end > $ts) {
            $when .= '–' . date('H:i', $end);
        }
        $kind = (string)($row['kind'] ?? 'event');
        $ongoing = !empty($row['ongoing']);
        $op = trim((string)($row['operator_name'] ?? ''));
        $href = (string)($row['href'] ?? '#');
      ?>
      <li class="widget-event<?= $ongoing ? ' is-ongoing' : '' ?> kind-<?= e($kind) ?>">
        <a href="<?= e($href) ?>">
          <span class="widget-event-when">
            <?= e($when) ?>
            <?php if ($ongoing): ?>
              <span class="widget-event-live"><?= te('calendar.widget.upcoming.live') ?></span>
            <?php endif; ?>
          </span>
          <span class="widget-event-label"><?= e((string)($row['label'] ?? '')) ?></span>
          <?php if (!$myOnly && $op !== ''): ?>
            <span class="widget-event-op muted"><?= e($op) ?></span>
          <?php endif; ?>
        </a>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
