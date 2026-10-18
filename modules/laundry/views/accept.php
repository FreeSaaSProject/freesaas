<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $open */
/** @var array<int,string> $types */
/** @var string $message */
/** @var string $error */
?>
<section class="page-head"><h1><?= te('laundry.accept.title') ?></h1></section>
<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>
<?php if ($open === []): ?>
  <p class="muted"><?= te('laundry.accept.no_open') ?></p>
  <p><a class="btn-secondary" href="/laundry"><?= te('laundry.menu.home') ?></a></p>
<?php else: ?>
<form method="post" class="form-grid form-wide">
  <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
  <div class="table-wrap"><table class="data">
  <thead><tr>
    <th><?= te('laundry.col.dt') ?></th>
    <th>ID</th>
    <th><?= te('laundry.col.type') ?></th>
    <th><?= te('laundry.col.open') ?></th>
    <th><?= te('laundry.accept.return_now') ?></th>
  </tr></thead>
  <tbody>
  <?php foreach ($open as $s): ?>
    <?php foreach ($s['lines'] ?? [] as $l): ?>
      <tr>
        <td class="muted"><?= (int)$s['dt'] > 0 ? e(date('d.m.Y H:i', (int)$s['dt'])) : '—' ?></td>
        <td>#<?= (int)$s['id'] ?></td>
        <td><?= e($types[(int)$l['id_item_type']] ?? ('#' . (int)$l['id_item_type'])) ?></td>
        <td><?= (int)$l['qty_open'] ?></td>
        <td>
          <input type="number" min="0" max="<?= (int)$l['qty_open'] ?>"
                 name="ret[<?= (int)$l['id'] ?>]" value="0" style="width:5rem">
        </td>
      </tr>
    <?php endforeach; ?>
  <?php endforeach; ?>
  </tbody></table></div>
  <fieldset>
    <label><?= te('laundry.col.descr') ?><input name="descr" maxlength="255" value=""></label>
    <label><?= te('laundry.col.weight') ?><input name="weight" value="0"></label>
  </fieldset>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('laundry.accept.submit') ?></button>
    <a class="btn-secondary" href="/laundry"><?= te('core.common.cancel') ?></a>
  </div>
</form>
<?php endif; ?>
