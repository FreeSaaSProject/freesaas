<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var string $message */
/** @var string $error */
$content = is_array($row['content'] ?? null) ? $row['content'] : [];
if ($content === []) {
    $content = [['product_id' => 0, 'qty' => 1, 'price' => 0, 'name' => '']];
}
$contentUsed = is_array($row['content_used'] ?? null) ? $row['content_used'] : [];
$typeIds = is_array($row['type_ids'] ?? null) ? $row['type_ids'] : [];
$productIds = is_array($row['product_ids'] ?? null) ? $row['product_ids'] : [];
?>
<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid certificates-edit">
  <fieldset>
    <legend><?= te('certificates.edit.sec_main') ?></legend>
    <label><?= te('certificates.field.code') ?>
      <input name="code" required value="<?= e((string)($row['code'] ?? '')) ?>">
    </label>
    <label><?= te('certificates.field.kind') ?>
      <select name="kind">
        <option value="1"<?= (int)($row['kind'] ?? 1) === 1 ? ' selected' : '' ?>><?= te('certificates.kind.money') ?></option>
        <option value="2"<?= (int)($row['kind'] ?? 1) === 2 ? ' selected' : '' ?>><?= te('certificates.kind.abon') ?></option>
      </select>
    </label>
    <label><?= te('certificates.field.id_event_create') ?>
      <input type="number" name="id_event_create" min="0" value="<?= (int)($row['id_event_create'] ?? 0) ?>">
    </label>
    <label><?= te('certificates.field.descr') ?>
      <textarea name="descr" rows="3"><?= e((string)($row['descr'] ?? '')) ?></textarea>
    </label>
  </fieldset>

  <fieldset>
    <legend><?= te('certificates.edit.sec_people') ?></legend>
    <label><?= te('certificates.field.id_operator') ?>
      <input type="number" name="id_operator" min="0" value="<?= (int)($row['id_operator'] ?? 0) ?>">
    </label>
    <label><?= te('certificates.field.id_buyer') ?>
      <input type="number" name="id_buyer" min="0" value="<?= (int)($row['id_buyer'] ?? 0) ?>">
    </label>
    <label><?= te('certificates.field.id_holder') ?>
      <input type="number" name="id_holder" min="0" value="<?= (int)($row['id_holder'] ?? 0) ?>">
    </label>
  </fieldset>

  <fieldset>
    <legend><?= te('certificates.edit.sec_money') ?></legend>
    <label><?= te('certificates.field.sale_amount') ?>
      <input type="number" step="0.01" name="sale_amount" value="<?= e((string)($row['sale_amount'] ?? 0)) ?>">
    </label>
    <label><?= te('certificates.field.face_value') ?>
      <input type="number" step="0.01" name="face_value" value="<?= e((string)($row['face_value'] ?? 0)) ?>">
    </label>
    <?php if (!$isNew): ?>
      <p class="muted"><?= te('certificates.field.used_amount') ?>: <?= e(number_format((float)($row['used_amount'] ?? 0), 2, '.', ' ')) ?>
        · <?= te('certificates.field.balance') ?>: <?= e(number_format((float)($row['balance'] ?? 0), 2, '.', ' ')) ?></p>
      <p><a href="/certificates/ledger?id_cert=<?= (int)$row['id'] ?>"><?= te('certificates.edit.ledger_link') ?></a></p>
    <?php endif; ?>
  </fieldset>

  <fieldset>
    <legend><?= te('certificates.edit.sec_dates') ?></legend>
    <label><?= te('certificates.field.dt_create') ?>
      <input type="datetime-local" name="dt_create" value="<?= e(CertificatesRepository::tsToLocal((int)($row['dt_create'] ?? 0))) ?>">
    </label>
    <label><?= te('certificates.field.dt_activation_expire') ?>
      <input type="datetime-local" name="dt_activation_expire" value="<?= e(CertificatesRepository::tsToLocal((int)($row['dt_activation_expire'] ?? 0))) ?>">
    </label>
    <label><?= te('certificates.field.dt_use_expire') ?>
      <input type="datetime-local" name="dt_use_expire" value="<?= e(CertificatesRepository::tsToLocal((int)($row['dt_use_expire'] ?? 0))) ?>">
    </label>
  </fieldset>

  <fieldset>
    <legend><?= te('certificates.edit.sec_flags') ?></legend>
    <label class="check"><input type="checkbox" name="expired" value="1"<?= !empty($row['expired']) ? ' checked' : '' ?>> <?= te('certificates.field.expired') ?></label>
    <label class="check"><input type="checkbox" name="partial_forbidden" value="1"<?= !empty($row['partial_forbidden']) ? ' checked' : '' ?>> <?= te('certificates.field.partial_forbidden') ?></label>
    <label class="check"><input type="checkbox" name="bonus_forbidden" value="1"<?= !empty($row['bonus_forbidden']) ? ' checked' : '' ?>> <?= te('certificates.field.bonus_forbidden') ?></label>
    <label><?= te('certificates.field.use_limit_pct') ?>
      <input type="number" name="use_limit_pct" min="0" max="100" value="<?= (int)($row['use_limit_pct'] ?? 0) ?>">
    </label>
  </fieldset>

  <fieldset>
    <legend><?= te('certificates.edit.sec_restrict') ?></legend>
    <label><?= te('certificates.field.type_ids') ?>
      <input name="type_ids" value="<?= e(implode(',', $typeIds)) ?>" placeholder="1,2,3">
    </label>
    <label><?= te('certificates.field.product_ids') ?>
      <input name="product_ids" value="<?= e(implode(',', $productIds)) ?>" placeholder="10,20">
    </label>
  </fieldset>

  <fieldset>
    <legend><?= te('certificates.edit.sec_content') ?></legend>
    <div class="certificates-content-lines">
      <?php foreach ($content as $i => $line): ?>
        <div class="certificates-content-line">
          <label><?= te('certificates.field.product_id') ?>
            <input type="number" name="content_product_id[]" min="0" value="<?= (int)($line['product_id'] ?? 0) ?>">
          </label>
          <label><?= te('certificates.field.qty') ?>
            <input type="number" name="content_qty[]" min="0" value="<?= (int)($line['qty'] ?? 0) ?>">
          </label>
          <label><?= te('certificates.field.price') ?>
            <input type="number" step="0.01" name="content_price[]" value="<?= e((string)($line['price'] ?? 0)) ?>">
          </label>
          <label><?= te('certificates.field.name') ?>
            <input name="content_name[]" value="<?= e((string)($line['name'] ?? '')) ?>">
          </label>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="muted"><?= te('certificates.edit.add_line') ?> — <?= te('certificates.kind.abon') ?></p>
  </fieldset>

  <?php if ($contentUsed !== []): ?>
    <fieldset>
      <legend><?= te('certificates.edit.sec_used') ?></legend>
      <ul>
        <?php foreach ($contentUsed as $entry): ?>
          <li>
            <?= (int)($entry['ts'] ?? 0) > 0 ? e(I18n::formatDate((int)$entry['ts'], 'date')) : '—' ?>
            · admin #<?= (int)($entry['admin_id'] ?? 0) ?>
            ·
            <?php
            $bits = [];
            foreach ((array)($entry['goods'] ?? []) as $gid => $qty) {
                $bits[] = '#' . (int)$gid . '×' . (int)$qty;
            }
            echo e(implode(', ', $bits));
            ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </fieldset>
  <?php endif; ?>

  <div class="form-actions">
    <button type="submit" class="btn btn-primary"><?= te('core.common.save') ?></button>
    <a class="btn" href="/certificates"><?= te('core.common.cancel') ?></a>
  </div>
</form>
