<?php
declare(strict_types=1);
/**
 * @var int $procId
 * @var int $eventId
 * @var int $clientId
 * @var list<array<string,mixed>> $sheets
 * @var array<string,mixed>|null $visitDef
 * @var bool $canEdit
 */
?>
<section class="docs-procedure-block">
  <h2><?= te('docs.procedure.sec') ?></h2>
  <?php if ($sheets !== []): ?>
    <ul>
      <?php foreach ($sheets as $s): ?>
        <li>
          <a href="/documents/<?= (int)$s['id'] ?>/edit"><?= e((string)$s['def_name']) ?> #<?= (int)$s['id'] ?></a>
          <span class="docs-status docs-status--<?= e((string)$s['status']) ?>"><?= te('docs.status.' . $s['status']) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php elseif ($visitDef !== null && $canEdit && $clientId > 0): ?>
    <p>
      <a class="btn-secondary" href="/documents/new?def=<?= (int)$visitDef['id'] ?>&amp;client=<?= (int)$clientId ?>&amp;event=<?= (int)$eventId ?>&amp;procedure=<?= (int)$procId ?>"><?= te('docs.procedure.create') ?></a>
    </p>
  <?php else: ?>
    <p class="muted"><?= te('docs.procedure.empty') ?></p>
  <?php endif; ?>
</section>
