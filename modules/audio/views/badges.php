<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var bool $canEdit */
/** @var string $flash */
?>
<?php if ($flash !== ''): ?>
<p class="flash"><?= e($flash) ?></p>
<?php endif; ?>

<?php if ($canEdit): ?>
<form method="post" class="inline-form" style="margin-bottom:1rem">
  <input type="hidden" name="action" value="sync">
  <button type="submit"><?= te('audio.badges.sync') ?></button>
</form>
<?php endif; ?>

<?php if (!$items): ?>
<p class="muted"><?= te('audio.badges.empty') ?></p>
<?php else: ?>
<table class="data-table">
  <thead>
    <tr>
      <th><?= te('audio.badges.col.code') ?></th>
      <th><?= te('audio.badges.col.name') ?></th>
      <th><?= te('audio.badges.col.enabled') ?></th>
      <?php if ($canEdit): ?><th></th><?php endif; ?>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($items as $b): ?>
    <?php if ($canEdit): ?>
    <tr>
      <td colspan="4" style="padding:0;border:0">
        <form method="post" class="badge-row-form" style="display:grid;grid-template-columns:1fr 2fr auto auto;gap:0.5rem;align-items:center;padding:0.4rem 0.5rem">
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="id" value="<?= (int)$b['ID'] ?>">
          <code><?= e((string)$b['CODE']) ?></code>
          <input name="name" value="<?= e((string)$b['NAME']) ?>">
          <label><input type="checkbox" name="enabled" value="1"<?= !empty($b['ENABLED']) ? ' checked' : '' ?>> <?= te('audio.badges.col.enabled') ?></label>
          <button type="submit"><?= te('audio.badges.save') ?></button>
        </form>
      </td>
    </tr>
    <?php else: ?>
    <tr>
      <td><code><?= e((string)$b['CODE']) ?></code></td>
      <td><?= e((string)$b['NAME']) ?></td>
      <td><?= !empty($b['ENABLED']) ? te('core.common.on') : te('core.common.off') ?></td>
    </tr>
    <?php endif; ?>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
