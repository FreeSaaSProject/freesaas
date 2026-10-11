<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var string $message */
/** @var string $error */
/** @var array<int,string> $categories */
/** @var array<int,string> $unitNames */
/** @var array<int,string> $supplierNames */
/** @var array<int,string> $procedureTypeNames */
/** @var list<array<string,mixed>> $relations */
/** @var array<int,string> $productOptions */
/** @var string $lastAdminName */
/** @var string $backHref */
$pid = (int)($row['id'] ?? 0);
$isMaterial = !empty($row['is_material']);
$backHref = $backHref ?? '/catalog';
?>
<section class="page-head">
  <h1><?= $isNew ? te('catalog.product.heading_new') : e((string)($row['name'] ?? '')) ?></h1>
  <?php if (!$isNew && ((int)($row['last_admin_at'] ?? 0) > 0 || $lastAdminName !== '')): ?>
    <p class="lede muted">
      <?= te('catalog.product.last_edit') ?>:
      <?= $lastAdminName !== '' ? e($lastAdminName) : ('#' . (int)$row['last_admin_id']) ?>
      <?php if ((int)($row['last_admin_at'] ?? 0) > 0): ?>
        · <?= e(I18n::formatDate((int)$row['last_admin_at'], 'datetime')) ?>
      <?php endif; ?>
    </p>
  <?php endif; ?>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid form-wide user-edit-form">
  <input type="hidden" name="action" value="save">

  <fieldset>
    <legend><?= te('catalog.product.sec_main') ?></legend>
    <label><?= te('catalog.col.name') ?>
      <input name="name" maxlength="255" value="<?= e((string)($row['name'] ?? '')) ?>" required>
    </label>
    <label><?= te('catalog.product.name_price') ?>
      <input name="name_price" maxlength="200" value="<?= e((string)($row['name_price'] ?? '')) ?>">
    </label>
    <label><?= te('catalog.col.category') ?>
      <select name="category">
        <option value="0">—</option>
        <?php foreach ($categories as $cid => $cname): ?>
          <option value="<?= (int)$cid ?>"<?= (int)($row['category'] ?? 0) === (int)$cid ? ' selected' : '' ?>><?= e($cname) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= te('catalog.col.num') ?>
      <input type="number" name="num" value="<?= e((string)($row['num'] ?? '0')) ?>">
    </label>
    <label><?= te('catalog.product.dop_cat') ?>
      <input name="dop_cat" maxlength="255" value="<?= e((string)($row['dop_cat'] ?? '')) ?>">
    </label>
    <label><?= te('catalog.product.description') ?>
      <textarea name="description" rows="3"><?= e((string)($row['description'] ?? '')) ?></textarea>
    </label>
    <label><?= te('catalog.product.descr_proc') ?>
      <textarea name="descr_proc" rows="3"><?= e((string)($row['descr_proc'] ?? '')) ?></textarea>
    </label>
    <label class="check">
      <input type="checkbox" name="enabled" value="1"<?= !isset($row['enabled']) || !empty($row['enabled']) ? ' checked' : '' ?>>
      <?= te('catalog.col.enabled') ?>
    </label>
    <label class="check">
      <input type="checkbox" name="is_material" value="1"<?= $isMaterial ? ' checked' : '' ?> onchange="document.getElementById('stock-sec').hidden=!this.checked">
      <?= te('catalog.kind.material') ?>
    </label>
  </fieldset>

  <fieldset>
    <legend><?= te('catalog.product.sec_type') ?></legend>
    <label><?= te('catalog.product.type_id') ?>
      <?php if (!empty($procedureTypeNames)): ?>
      <select name="type_id">
        <option value="0">—</option>
        <?php foreach ($procedureTypeNames as $tid => $tlabel): ?>
          <option value="<?= (int)$tid ?>"<?= (int)($row['type_id'] ?? 0) === (int)$tid ? ' selected' : '' ?>><?= e($tlabel) ?></option>
        <?php endforeach; ?>
      </select>
      <?php else: ?>
      <input type="number" min="0" name="type_id" value="<?= e((string)($row['type_id'] ?? '0')) ?>">
      <?php endif; ?>
      <span class="field-hint muted"><?= te('catalog.product.type_id_hint') ?></span>
    </label>
  </fieldset>

  <fieldset>
    <legend><?= te('catalog.product.sec_time') ?></legend>
    <label><?= te('catalog.product.time_sec') ?>
      <input type="number" min="0" name="time_sec" value="<?= e((string)($row['time_sec'] ?? '0')) ?>">
    </label>
    <label><?= te('catalog.product.time_net') ?>
      <input type="number" min="0" name="time_net" value="<?= e((string)($row['time_net'] ?? '0')) ?>">
    </label>
  </fieldset>

  <fieldset>
    <legend><?= te('catalog.product.sec_prices') ?></legend>
    <?php for ($i = 1; $i <= 5; $i++):
      $key = $i === 1 ? 'price' : ('price' . $i);
    ?>
      <label><?= te('catalog.product.price_n', ['n' => $i]) ?>
        <input type="number" step="0.01" name="<?= e($key) ?>" value="<?= e((string)($row[$key] ?? '0')) ?>">
      </label>
    <?php endfor; ?>
  </fieldset>

  <fieldset>
    <legend><?= te('catalog.product.sec_flags') ?></legend>
    <label class="check">
      <input type="checkbox" name="hide_on_site" value="1"<?= !empty($row['hide_on_site']) || !empty($row['is_status_card']) ? ' checked' : '' ?>>
      <?= te('catalog.product.hide_on_site') ?>
      <span class="field-hint muted"><?= te('catalog.product.hide_on_site_hint') ?></span>
    </label>
    <label class="check">
      <input type="checkbox" name="hide_in_history" value="1"<?= !empty($row['hide_in_history']) ? ' checked' : '' ?>>
      <?= te('catalog.product.hide_in_history') ?>
    </label>
  </fieldset>

  <fieldset id="stock-sec"<?= $isMaterial ? '' : ' hidden' ?>>
    <legend><?= te('catalog.product.sec_stock') ?></legend>
    <p class="form-hint"><?= te('catalog.product.stock_hint') ?></p>
    <label><?= te('catalog.product.unit_id') ?>
      <select name="unit_id">
        <option value="0">—</option>
        <?php foreach ($unitNames as $uid => $uname): ?>
          <option value="<?= (int)$uid ?>"<?= (int)($row['unit_id'] ?? 0) === (int)$uid ? ' selected' : '' ?>><?= e($uname) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= te('catalog.product.unit_use_id') ?>
      <select name="unit_use_id">
        <option value="0">—</option>
        <?php foreach ($unitNames as $uid => $uname): ?>
          <option value="<?= (int)$uid ?>"<?= (int)($row['unit_use_id'] ?? 0) === (int)$uid ? ' selected' : '' ?>><?= e($uname) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= te('catalog.product.supplier_id') ?>
      <select name="supplier_id">
        <option value="0">—</option>
        <?php foreach ($supplierNames as $sid => $sname): ?>
          <option value="<?= (int)$sid ?>"<?= (int)($row['supplier_id'] ?? 0) === (int)$sid ? ' selected' : '' ?>><?= e($sname) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= te('catalog.product.stock_min') ?>
      <input type="number" min="0" name="stock_min" value="<?= e((string)($row['stock_min'] ?? '0')) ?>">
    </label>
    <label><?= te('catalog.product.stock_max') ?>
      <input type="number" min="0" name="stock_max" value="<?= e((string)($row['stock_max'] ?? '0')) ?>">
    </label>
  </fieldset>

  <?php
  $sections = EventBus::emit('catalog.product_edit.sections', [
      'product_id' => $pid,
      'is_new' => $isNew,
      'row' => $row,
      'html' => '',
  ]);
  if (!empty($sections['html'])) {
      echo $sections['html'];
  }
  ?>

  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="<?= e($backHref) ?>"><?= te('core.common.cancel') ?></a>
  </div>
</form>

<?php if (!$isNew): ?>
<section class="page-head" style="margin-top:2rem">
  <h2><?= te('catalog.rel.title') ?></h2>
  <p class="lede"><?= te('catalog.rel.lede') ?></p>
</section>

<div class="table-wrap">
  <table class="data">
    <thead>
      <tr>
        <th><?= te('catalog.rel.type') ?></th>
        <th><?= te('catalog.col.name') ?></th>
        <th><?= te('catalog.rel.benefit') ?></th>
        <th><?= te('catalog.col.enabled') ?></th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php
    $relTypeKeys = [
        'addon' => 'catalog.rel.type_addon',
        'upgrade' => 'catalog.rel.type_upgrade',
        'component' => 'catalog.rel.type_component',
    ];
    foreach ($relations as $rel):
      $rtype = (string)$rel['type'];
      $rtypeKey = $relTypeKeys[$rtype] ?? 'catalog.rel.type_addon';
    ?>
      <tr>
        <td><?= te($rtypeKey) ?></td>
        <td>
          <a href="/products/<?= (int)$rel['product_to_id'] ?>/edit">
            <?= e((string)($rel['related_name'] ?? ('#' . $rel['product_to_id']))) ?>
          </a>
        </td>
        <td class="muted"><?= e((string)($rel['benefit'] ?? '')) ?></td>
        <td>
          <form method="post" class="inline-form">
            <input type="hidden" name="action" value="toggle_relation">
            <input type="hidden" name="relation_id" value="<?= (int)$rel['id'] ?>">
            <label class="check">
              <input type="checkbox" name="rel_enabled" value="1"<?= !empty($rel['enabled']) ? ' checked' : '' ?> onchange="this.form.submit()">
              <?= !empty($rel['enabled']) ? te('core.common.on') : te('core.common.off') ?>
            </label>
          </form>
        </td>
        <td>
          <form method="post" onsubmit="return confirm(<?= tj('catalog.rel.confirm_delete') ?>)">
            <input type="hidden" name="action" value="delete_relation">
            <input type="hidden" name="relation_id" value="<?= (int)$rel['id'] ?>">
            <button type="submit" class="btn-secondary"><?= te('core.common.delete') ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$relations): ?>
      <tr><td colspan="5" class="muted"><?= te('catalog.rel.empty') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<form method="post" class="form-grid form-wide" style="margin-top:1rem">
  <input type="hidden" name="action" value="add_relation">
  <fieldset>
    <legend><?= te('catalog.rel.add') ?></legend>
    <label><?= te('catalog.rel.type') ?>
      <select name="rel_type">
        <option value="addon"><?= te('catalog.rel.type_addon') ?></option>
        <option value="upgrade"><?= te('catalog.rel.type_upgrade') ?></option>
        <option value="component"><?= te('catalog.rel.type_component') ?></option>
      </select>
    </label>
    <label><?= te('catalog.rel.product') ?>
      <select name="related_id" required>
        <option value=""><?= te('catalog.rel.pick_product') ?></option>
        <?php foreach ($productOptions as $oid => $oname): ?>
          <?php if ((int)$oid === $pid) { continue; } ?>
          <option value="<?= (int)$oid ?>"><?= e($oname) ?> (#<?= (int)$oid ?>)</option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= te('catalog.rel.benefit') ?>
      <input name="benefit" value="">
    </label>
    <div class="page-actions">
      <button class="btn-primary" type="submit"><?= te('catalog.rel.add_btn') ?></button>
    </div>
  </fieldset>
</form>

<form method="post" style="margin-top:1rem">
  <input type="hidden" name="action" value="recalc_components">
  <button class="btn-secondary" type="submit"><?= te('catalog.rel.recalc_btn') ?></button>
</form>
<?php endif; ?>

<?php
$extra = EventBus::emit('catalog.product_edit.after', [
    'product_id' => $pid,
    'is_new' => $isNew,
    'row' => $row,
    'html' => '',
]);
if (!empty($extra['html'])) {
    echo $extra['html'];
}
?>
