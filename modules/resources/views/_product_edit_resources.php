<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $resources */
/** @var array<int,int> $selected */
?>
<fieldset>
  <legend><?= te('resources.product.sec') ?></legend>
  <p class="lede"><?= te('resources.product.lede') ?></p>
  <input type="hidden" name="resources_section" value="1">
  <?php if ($resources === []): ?>
    <p class="muted"><?= te('resources.product.none') ?></p>
  <?php else: ?>
    <div class="form-stack">
      <?php foreach ($resources as $r):
          $rid = (int)($r['id'] ?? 0);
          if ($rid < 1) {
              continue;
          }
          $checked = isset($selected[$rid]);
          $need = $checked ? max(1, (int)$selected[$rid]) : 1;
          ?>
        <label class="check">
          <input type="checkbox" name="resource_ids[]" value="<?= $rid ?>"<?= $checked ? ' checked' : '' ?>>
          <?= e((string)($r['name'] ?? ('#' . $rid))) ?>
          <span class="muted">(<?= te('resources.col.qty') ?>: <?= (int)($r['qty'] ?? 1) ?>)</span>
        </label>
        <label class="inline"><?= te('resources.product.qty_needed') ?>
          <input type="number" min="1" max="999" name="resource_qty[<?= $rid ?>]" value="<?= $need ?>" style="width:5rem">
        </label>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</fieldset>
