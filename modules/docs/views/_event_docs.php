<?php
declare(strict_types=1);
/**
 * Injected into calendar event edit (inside the event form — links only, no nested forms).
 *
 * @var int $eventId
 * @var int $clientId
 * @var list<array<string,mixed>> $instances
 * @var list<array<string,mixed>> $manualDefs
 * @var bool $blocking
 */
$canPrint = Auth::can('docs.print');
?>
<fieldset class="docs-event-block">
  <legend><?= te('docs.event.sec') ?></legend>
  <?php if ($blocking): ?>
    <p class="flash flash-warn"><?= te('docs.event.medcard_blocking') ?></p>
  <?php endif; ?>
  <?php if ($instances === []): ?>
    <p class="muted"><?= te('docs.event.empty') ?></p>
  <?php else: ?>
    <ul class="docs-event-list">
      <?php foreach ($instances as $inst): ?>
        <li>
          <a href="/documents/<?= (int)$inst['id'] ?>/edit"><?= e((string)$inst['def_name']) ?></a>
          <span class="docs-status docs-status--<?= e((string)$inst['status']) ?>"><?= te('docs.status.' . $inst['status']) ?></span>
          <?php if ($canPrint && $inst['status'] !== 'void'): ?>
            · <a href="/documents/<?= (int)$inst['id'] ?>/print"><?= te((int)$inst['dt_print'] > 0 ? 'docs.edit.open_print' : 'docs.edit.print') ?></a>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <?php if ($manualDefs !== [] && $clientId > 0): ?>
    <p class="docs-event-add">
      <span class="muted"><?= te('docs.event.add') ?>:</span>
      <?php foreach ($manualDefs as $d): ?>
        <a class="btn-secondary btn-sm" href="/documents/new?def=<?= (int)$d['id'] ?>&amp;event=<?= (int)$eventId ?>&amp;client=<?= (int)$clientId ?>"><?= e((string)$d['name']) ?></a>
      <?php endforeach; ?>
    </p>
  <?php endif; ?>
  <?php if ($clientId > 0 && Auth::can('docs.medical')): ?>
    <p class="docs-event-add">
      <a href="/documents/clients/<?= (int)$clientId ?>/profile"><?= te('docs.client.profile') ?></a>
      <?php if ($canPrint): ?> · <a href="/documents/clients/<?= (int)$clientId ?>/medcard"><?= te('docs.client.dossier') ?></a><?php endif; ?>
    </p>
  <?php endif; ?>
</fieldset>
