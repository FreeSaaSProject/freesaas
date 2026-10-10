<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var string $message */
/** @var string $error */
/** @var bool $canEdit */
/** @var list<string> $themes */
/** @var array<int,string> $promoOptions */
/** @var array<int,string> $catOptions */
?>
<section class="page-head">
  <h1><?= $isNew ? te('sites.hosts.edit.heading_new') : e((string)($row['host'] ?? '')) ?></h1>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid form-wide" <?= $canEdit ? '' : 'onsubmit="return false"' ?>>
  <fieldset>
    <legend><?= te('sites.hosts.sec.main') ?></legend>
    <label><?= te('sites.hosts.field.host') ?>
      <input name="host" maxlength="255" value="<?= e((string)($row['host'] ?? '')) ?>" placeholder="www.example.com" <?= $canEdit ? 'required' : 'disabled' ?>>
      <span class="field-hint muted"><?= te('sites.hosts.field.host_hint') ?></span>
    </label>
    <label><?= te('sites.hosts.field.name') ?>
      <input name="name" maxlength="255" value="<?= e((string)($row['name'] ?? '')) ?>" <?= $canEdit ? '' : 'disabled' ?>>
    </label>
    <label><?= te('sites.hosts.field.tel') ?>
      <input name="tel" maxlength="64" value="<?= e((string)($row['tel'] ?? '')) ?>" <?= $canEdit ? '' : 'disabled' ?>>
      <span class="field-hint muted"><?= te('sites.hosts.field.tel_hint') ?></span>
    </label>
    <label><?= te('sites.hosts.field.num') ?>
      <input type="number" name="num" value="<?= e((string)($row['num'] ?? '0')) ?>" <?= $canEdit ? '' : 'disabled' ?>>
    </label>
    <label class="check">
      <input type="checkbox" name="enabled" value="1"<?= !isset($row['enabled']) || !empty($row['enabled']) ? ' checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>>
      <?= te('sites.hosts.field.enabled') ?>
    </label>
    <label class="check">
      <input type="checkbox" name="is_default" value="1"<?= !empty($row['is_default']) ? ' checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>>
      <?= te('sites.hosts.field.is_default') ?>
      <span class="field-hint muted"><?= te('sites.hosts.field.is_default_hint') ?></span>
    </label>
  </fieldset>

  <fieldset>
    <legend><?= te('sites.hosts.sec.storefront') ?></legend>
    <label><?= te('sites.hosts.field.theme') ?>
      <select name="theme" <?= $canEdit ? '' : 'disabled' ?>>
        <?php foreach ($themes as $tid): ?>
          <option value="<?= e($tid) ?>"<?= ((string)($row['theme'] ?? 'default') === $tid) ? ' selected' : '' ?>><?= e($tid) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= te('sites.hosts.field.promo_id') ?>
      <select name="promo_id" <?= $canEdit ? '' : 'disabled' ?>>
        <?php foreach ($promoOptions as $pid => $label): ?>
          <option value="<?= (int)$pid ?>"<?= (int)($row['promo_id'] ?? 0) === (int)$pid ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <span class="field-hint muted"><?= te('sites.hosts.field.promo_id_hint') ?></span>
    </label>
    <label><?= te('sites.hosts.field.catalog_root_id') ?>
      <select name="catalog_root_id" <?= $canEdit ? '' : 'disabled' ?>>
        <?php foreach ($catOptions as $cid => $label): ?>
          <option value="<?= (int)$cid ?>"<?= (int)($row['catalog_root_id'] ?? 0) === (int)$cid ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <span class="field-hint muted"><?= te('sites.hosts.field.catalog_root_id_hint') ?></span>
    </label>
  </fieldset>

  <?php if ($canEdit): ?>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/sites/hosts"><?= te('core.common.cancel') ?></a>
  </div>
  <?php else: ?>
  <p><a class="btn-secondary" href="/sites/hosts"><?= te('core.common.cancel') ?></a></p>
  <?php endif; ?>
</form>
