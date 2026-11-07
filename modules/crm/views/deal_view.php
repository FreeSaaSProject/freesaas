<?php
declare(strict_types=1);
?>
<section class="page-head">
  <div class="page-head-row">
    <h1><?= e((string)$row['title']) ?></h1>
    <?php if ($canEdit): ?>
    <div class="page-actions">
      <a class="btn-secondary" href="/crm/deals/<?= (int)$row['id'] ?>/edit"><?= te('core.common.edit') ?></a>
    </div>
    <?php endif; ?>
  </div>
</section>
<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>
<dl class="dl-grid">
  <dt><?= te('crm.col.pipeline') ?></dt>
  <dd><?= e((string)($row['pipeline_name'] ?? '')) ?> (<?= e((string)($row['pipeline_kind'] ?? '')) ?>)</dd>
  <dt><?= te('crm.col.stage') ?></dt>
  <dd><?= e((string)($row['stage_name'] ?? '')) ?></dd>
  <dt><?= te('crm.col.amount') ?></dt>
  <dd><?= e((string)$row['amount']) ?> <?= e((string)$row['currency']) ?></dd>
  <dt><?= te('crm.col.source') ?></dt>
  <dd><?= e((string)$row['source']) ?></dd>
</dl>
<?php if ($canEdit && $stages): ?>
<form method="post" class="form-grid">
  <input type="hidden" name="action" value="move_stage">
  <label><?= te('crm.col.stage') ?>
    <select name="stage_id">
      <?php foreach ($stages as $s): ?>
        <option value="<?= (int)$s['id'] ?>"<?= (int)$row['stage_id'] === (int)$s['id'] ? ' selected' : '' ?>><?= e((string)$s['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
</form>
<?php endif; ?>
<h2><?= te('crm.col.contacts') ?></h2>
<ul>
<?php foreach ($contacts as $c): ?>
  <li><a href="/clients/<?= (int)$c['client_id'] ?>"><?= e((string)($c['name'] ?? ('#' . $c['client_id']))) ?></a>
    <?= !empty($c['is_primary']) ? '★' : '' ?></li>
<?php endforeach; ?>
<?php if (!$contacts): ?><li class="muted"><?= te('crm.profile.none') ?></li><?php endif; ?>
</ul>
<h2><?= te('crm.history.title') ?></h2>
<ul>
<?php foreach ($history as $h): ?>
  <li class="muted"><?= e(date('Y-m-d H:i', (int)$h['changed_at'])) ?>:
    <?= e((string)($h['from_name'] ?? '—')) ?> → <?= e((string)($h['to_name'] ?? '—')) ?></li>
<?php endforeach; ?>
<?php if (!$history): ?><li class="muted"><?= te('crm.history.empty') ?></li><?php endif; ?>
</ul>
<h2><?= te('crm.notes.title') ?></h2>
<?php if ($canEdit): ?>
<form method="post" class="form-grid">
  <input type="hidden" name="action" value="add_note">
  <label><textarea name="body" rows="3" required></textarea></label>
  <button class="btn-primary" type="submit"><?= te('crm.notes.add') ?></button>
</form>
<?php endif; ?>
<ul>
<?php foreach ($notes as $n): ?>
  <li><span class="muted"><?= e(date('Y-m-d H:i', (int)$n['created_at'])) ?></span> <?= e_nl((string)$n['body']) ?></li>
<?php endforeach; ?>
<?php if (!$notes): ?><li class="muted"><?= te('crm.notes.empty') ?></li><?php endif; ?>
</ul>
