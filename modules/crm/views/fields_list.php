<?php
declare(strict_types=1);
?>
<section class="page-head">
  <?php if ($canEdit): ?>
  <div class="page-head-row">
    <div></div>
    <div class="page-actions">
      <a class="btn-primary" href="/crm/fields/new"><?= te('crm.fields.new') ?></a>
    </div>
  </div>
  <?php endif; ?>
</section>
<div class="table-wrap">
  <table class="data">
    <thead>
      <tr>
        <th>ID</th>
        <th><?= te('crm.col.entity') ?></th>
        <th><?= te('crm.col.code') ?></th>
        <th><?= te('crm.col.name') ?></th>
        <th><?= te('crm.col.field_type') ?></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $f): ?>
      <tr>
        <td class="muted">
          <?php if ($canEdit): ?>
            <a href="/crm/fields/<?= (int)$f['id'] ?>/edit"><?= (int)$f['id'] ?></a>
          <?php else: ?>
            <?= (int)$f['id'] ?>
          <?php endif; ?>
        </td>
        <td><?= e((string)$f['entity']) ?></td>
        <td><?= e((string)$f['code']) ?></td>
        <td><?= e((string)$f['name']) ?></td>
        <td><?= e((string)$f['field_type']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?>
      <tr><td colspan="5" class="muted"><?= te('crm.fields.empty') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
