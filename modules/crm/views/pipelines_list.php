<?php
declare(strict_types=1);
?>
<section class="page-head">
  <?php if ($canEdit): ?>
  <div class="page-head-row">
    <div></div>
    <div class="page-actions">
      <a class="btn-primary" href="/crm/pipelines/new"><?= te('crm.pipelines.new') ?></a>
    </div>
  </div>
  <?php endif; ?>
</section>
<?php if (!$items): ?>
  <p class="muted"><?= te('crm.pipelines.empty') ?></p>
<?php endif; ?>
<?php foreach ($items as $p): ?>
  <section class="crm-pipeline-block">
    <h2>
      <?php if ($canEdit): ?>
        <a href="/crm/pipelines/<?= (int)$p['id'] ?>/edit"><?= e((string)$p['name']) ?></a>
      <?php else: ?>
        <?= e((string)$p['name']) ?>
      <?php endif; ?>
      <span class="muted"><?= e((string)$p['kind']) ?></span>
      <?= empty($p['enabled']) ? ' (' . te('core.common.no') . ')' : '' ?>
    </h2>
    <ol>
      <?php foreach ($p['stages'] as $s): ?>
        <li>
          <?php if ($canEdit): ?>
            <a href="/crm/stages/<?= (int)$s['id'] ?>/edit"><?= e((string)$s['name']) ?></a>
          <?php else: ?>
            <?= e((string)$s['name']) ?>
          <?php endif; ?>
          <span class="muted"><?= e((string)$s['code']) ?> / <?= e((string)$s['semantic']) ?></span>
        </li>
      <?php endforeach; ?>
    </ol>
    <?php if ($canEdit): ?>
      <p><a href="/crm/stages/new?pipeline_id=<?= (int)$p['id'] ?>"><?= te('crm.stages.new') ?></a></p>
    <?php endif; ?>
  </section>
<?php endforeach; ?>
