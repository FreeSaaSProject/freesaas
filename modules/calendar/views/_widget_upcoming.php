<?php
/** @var list<array<string,mixed>> $events */
/** @var bool $myOnly */
/** @var int $staffForLinks */
$events = $events ?? [];
$myOnly = !empty($myOnly);
$staffForLinks = (int)($staffForLinks ?? 0);
?>
<?php if ($myOnly): ?>
  <p class="widget-meta muted"><?= te('calendar.widget.upcoming.scope_mine') ?></p>
<?php else: ?>
  <p class="widget-meta muted"><?= te('calendar.widget.upcoming.scope_all') ?></p>
<?php endif; ?>

<?php if (!$events): ?>
  <p class="muted"><?= te('calendar.widget.upcoming.empty') ?></p>
<?php else: ?>
  <ul class="widget-event-list">
    <?php foreach ($events as $ev): ?>
      <?php
        $id = (int)($ev['id'] ?? 0);
        $ts = (int)($ev['dtstart'] ?? 0);
        $name = trim((string)($ev['name'] ?? ''));
        $client = trim((string)($ev['client_name'] ?? ''));
        $label = $name !== '' ? $name : ($client !== '' ? $client : t('calendar.widget.upcoming.unnamed'));
        $op = trim((string)($ev['operator_name'] ?? ''));
        $isBlock = !empty($ev['is_block']);
        $href = Auth::can('calendar.edit') && $id > 0
            ? '/calendar/events/' . $id . '/edit'
            : EventsRepository::dayUrl($ts, $staffForLinks);
        $when = $ts > 0 ? I18n::formatDate($ts, 'datetime') : '—';
      ?>
      <li class="widget-event<?= $isBlock ? ' is-block' : '' ?>">
        <a href="<?= e($href) ?>">
          <span class="widget-event-when"><?= e($when) ?></span>
          <span class="widget-event-label"><?= e($label) ?><?php if ($isBlock): ?> <span class="muted">(<?= te('calendar.day.block') ?>)</span><?php endif; ?></span>
          <?php if (!$myOnly && $op !== ''): ?>
            <span class="widget-event-op muted"><?= e($op) ?></span>
          <?php endif; ?>
        </a>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
