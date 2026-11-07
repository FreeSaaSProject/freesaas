<?php
declare(strict_types=1);
/** @var array<string,mixed> $row */
?>
<section class="page-head">
  <div class="page-head-row">
    <h1><?= e((string)$row['name']) ?></h1>
    <?php if ($canEdit): ?>
    <div class="page-actions">
      <a class="btn-secondary" href="/crm/companies/<?= (int)$row['id'] ?>/edit"><?= te('core.common.edit') ?></a>
    </div>
    <?php endif; ?>
  </div>
</section>
<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>
<dl class="dl-grid">
  <dt><?= te('crm.col.inn') ?></dt><dd><?= e((string)$row['inn']) ?></dd>
  <dt><?= te('crm.col.phone') ?></dt><dd><?= e((string)$row['phone']) ?></dd>
  <dt><?= te('crm.col.email') ?></dt><dd><?= e((string)$row['email']) ?></dd>
  <dt><?= te('crm.col.website') ?></dt><dd><?= e((string)$row['website']) ?></dd>
  <dt><?= te('crm.col.address') ?></dt><dd><?= e((string)$row['address']) ?></dd>
</dl>
<?php if ($cfDefs): ?>
<ul>
  <?php foreach ($cfDefs as $f): ?>
    <li><strong><?= e((string)$f['name']) ?>:</strong> <?= e((string)($cfValues[(int)$f['id']] ?? '')) ?></li>
  <?php endforeach; ?>
</ul>
<?php endif; ?>
<h2><?= te('crm.col.contacts') ?></h2>
<ul>
<?php foreach ($contacts as $c): ?>
  <li>
    <?php if (!empty($c['client_id'])): ?>
      <a href="/clients/<?= (int)$c['client_id'] ?>"><?= e((string)($c['name'] ?? ('#' . $c['client_id']))) ?></a>
      <?= e((string)($c['tel'] ?? '')) ?>
      <?= !empty($c['is_primary']) ? '★' : '' ?>
    <?php endif; ?>
  </li>
<?php endforeach; ?>
<?php if (!$contacts): ?><li class="muted"><?= te('crm.profile.none') ?></li><?php endif; ?>
</ul>
<h2><?= te('crm.deals.title') ?></h2>
<ul>
<?php foreach ($deals as $d): ?>
  <li><a href="/crm/deals/<?= (int)$d['id'] ?>"><?= e((string)$d['title']) ?></a>
    <span class="muted"><?= e((string)($d['stage_name'] ?? '')) ?></span></li>
<?php endforeach; ?>
<?php if (!$deals): ?><li class="muted"><?= te('crm.profile.none') ?></li><?php endif; ?>
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
  <li><span class="muted"><?= e(date('Y-m-d H:i', (int)$n['created_at'])) ?></span>
    <?= e_nl((string)$n['body']) ?></li>
<?php endforeach; ?>
<?php if (!$notes): ?><li class="muted"><?= te('crm.notes.empty') ?></li><?php endif; ?>
</ul>
