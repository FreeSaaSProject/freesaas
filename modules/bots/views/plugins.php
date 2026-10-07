<?php
declare(strict_types=1);
/** @var list<array{id:string,priority:int,handlers:list<string>}> $plugins */
?>
<section class="page-head">
  <p class="lede"><?= te('bots.plugins.title') ?></p>
</section>
<?php if ($plugins === []): ?>
  <p class="muted"><?= te('bots.plugins.empty') ?></p>
<?php else: ?>
<table class="data-table">
  <thead>
    <tr>
      <th><?= te('bots.plugins.col.id') ?></th>
      <th><?= te('bots.plugins.col.priority') ?></th>
      <th><?= te('bots.plugins.col.handlers') ?></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($plugins as $p): ?>
    <tr>
      <td><code><?= e($p['id']) ?></code></td>
      <td><?= (int)$p['priority'] ?></td>
      <td><?= e(implode(', ', $p['handlers'])) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
