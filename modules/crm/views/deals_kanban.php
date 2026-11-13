<?php
declare(strict_types=1);
/** @var list<array{stage:array<string,mixed>,count:int,sums:array<string,float>,items:list<array<string,mixed>>}> $columns */
$view = 'kanban';
$sort = '';
$dir = '';
$perPage = 0;
require __DIR__ . '/_deals_toolbar.php';
?>
<?php if (!$columns): ?>
  <p class="muted"><?= te('crm.kanban.no_stages') ?></p>
<?php else: ?>
<div class="crm-kanban" data-crm-kanban data-can-edit="<?= $canEdit ? '1' : '0' ?>"
     data-err="<?= te('crm.kanban.move_failed') ?>">
  <?php foreach ($columns as $col): $s = $col['stage']; ?>
  <section class="crm-kanban-col crm-sem-<?= e(strtolower((string)$s['semantic'])) ?>" data-stage-id="<?= (int)$s['id'] ?>">
    <header class="crm-kanban-head">
      <div class="crm-kanban-title"><?= e((string)$s['name']) ?></div>
      <div class="crm-kanban-meta">
        <span data-col-count><?= (int)$col['count'] ?></span>
        · <span data-col-sum><?= e(CrmUi::sumsLabel($col['sums'])) ?></span>
      </div>
    </header>
    <div class="crm-kanban-cards" data-drop-zone>
      <?php foreach ($col['items'] as $d): ?>
      <article class="crm-kanban-card" draggable="<?= $canEdit ? 'true' : 'false' ?>"
               data-deal-id="<?= (int)$d['id'] ?>"
               data-amount="<?= e((string)(float)$d['amount']) ?>"
               data-currency="<?= e((string)($d['currency'] ?: 'RUB')) ?>">
        <a class="crm-kanban-card-title" href="/crm/deals/<?= (int)$d['id'] ?>"><?= e((string)$d['title']) ?></a>
        <?php if (!empty($d['company_name'])): ?>
          <div class="muted"><?= e((string)$d['company_name']) ?></div>
        <?php endif; ?>
        <div class="crm-kanban-card-foot">
          <span class="crm-num"><?= e(CrmUi::money((float)$d['amount'], (string)$d['currency'])) ?></span>
          <?php if (!empty($d['responsible_name'])): ?>
            <span class="muted"><?= e((string)$d['responsible_name']) ?></span>
          <?php endif; ?>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endforeach; ?>
</div>
<?php endif; ?>
