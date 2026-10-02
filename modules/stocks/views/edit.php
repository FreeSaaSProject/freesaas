<?php
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var array<int,string> $cabinets */
/** @var array<int,string> $virtualStocks */
/** @var string $message */
/** @var string $error */
$type = (int)($row['type'] ?? StocksRepository::TYPE_REAL);
?>
<section class="page-head">
  <h1><?= $isNew ? te('stocks.edit.heading_new') : e((string)($row['name'] ?? '')) ?></h1>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid form-wide user-edit-form">
  <fieldset>
    <legend><?= te('stocks.edit.sec_main') ?></legend>
    <label><?= te('stocks.col.name') ?>
      <input name="name" maxlength="20" value="<?= e((string)($row['name'] ?? '')) ?>" required>
    </label>
    <label><?= te('stocks.col.descr') ?>
      <input name="descr" maxlength="255" value="<?= e((string)($row['descr'] ?? '')) ?>" required>
    </label>
    <label><?= te('stocks.col.type') ?>
      <select name="type" id="stock-type">
        <option value="1"<?= $type === 1 ? ' selected' : '' ?>><?= te('stocks.type.real') ?></option>
        <option value="2"<?= $type === 2 ? ' selected' : '' ?>><?= te('stocks.type.virtual') ?></option>
      </select>
    </label>
    <label><?= te('stocks.col.cabinet') ?>
      <select name="id_cabinet">
        <option value="0">—</option>
        <?php foreach ($cabinets as $cid => $cname): ?>
          <option value="<?= (int)$cid ?>"<?= (int)($row['id_cabinet'] ?? 0) === (int)$cid ? ' selected' : '' ?>><?= e($cname) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="check" data-real-only>
      <input type="checkbox" name="operative" value="1"<?= !isset($row['operative']) || !empty($row['operative']) ? ' checked' : '' ?>>
      <?= te('stocks.col.operative') ?>
    </label>
    <label class="check" data-real-only>
      <input type="checkbox" name="autocontrol_req" value="1"<?= !empty($row['autocontrol_req']) ? ' checked' : '' ?>>
      <?= te('stocks.col.autocontrol') ?>
    </label>
    <label data-real-only><?= te('stocks.col.vrt_stock') ?>
      <select name="vrt_stock_id">
        <option value="0">—</option>
        <?php foreach ($virtualStocks as $vid => $vname): ?>
          <option value="<?= (int)$vid ?>"<?= (int)($row['vrt_stock_id'] ?? 0) === (int)$vid ? ' selected' : '' ?>><?= e($vname) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </fieldset>

  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/stocks"><?= te('core.common.cancel') ?></a>
  </div>
</form>
<script>
(function () {
  var sel = document.getElementById('stock-type');
  if (!sel) return;
  function sync() {
    var real = sel.value === '1';
    document.querySelectorAll('[data-real-only]').forEach(function (el) {
      el.style.display = real ? '' : 'none';
    });
  }
  sel.addEventListener('change', sync);
  sync();
})();
</script>
