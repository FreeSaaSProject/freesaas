<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var bool $isClone */
/** @var array<string,mixed> $row */
/** @var string $message */
/** @var string $error */
/** @var array<int,string> $parentOptions */
/** @var array<int,string> $typeNames */
/** @var array<int,string> $staffNames */
/** @var string $publicUrl */
$types = is_array($row['types'] ?? null) ? $row['types'] : [];
$cosm = is_array($row['cosm_limit'] ?? null) ? $row['cosm_limit'] : [];
$chk = static function (string $key) use ($row): string {
    return !empty($row[$key]) ? ' checked' : '';
};
?>
<section class="page-head">
  <h1><?= $isNew ? te('sites.promopages.edit.heading_new') : e((string)($row['descr'] ?? '')) ?></h1>
  <?php if ($publicUrl !== ''): ?>
    <p class="muted"><a href="<?= e($publicUrl) ?>" target="_blank" rel="noopener"><?= e($publicUrl) ?></a></p>
  <?php endif; ?>
  <?php if ($isClone): ?>
    <p class="flash flash-warn"><?= te('sites.promopages.clone_hint') ?></p>
  <?php elseif (!empty($row['has_clones'])): ?>
    <p class="muted"><?= te('sites.promopages.has_clones_hint') ?></p>
  <?php endif; ?>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if (!empty($modulesFlash)): ?><div class="flash flash-ok"><?= e((string)$modulesFlash) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid form-wide">
  <fieldset>
    <legend><?= te('sites.promopages.sec.main') ?></legend>
    <label><?= te('sites.promopages.field.descr') ?>
      <input name="descr" maxlength="255" value="<?= e((string)($row['descr'] ?? '')) ?>" required>
    </label>
    <label><?= te('sites.promopages.field.code') ?>
      <input name="code" maxlength="10" value="<?= e((string)($row['code'] ?? '')) ?>" required>
      <span class="field-hint muted"><?= te('sites.promopages.field.code_hint') ?></span>
    </label>
    <label><?= te('sites.promopages.field.skin') ?>
      <input name="skin" maxlength="20" value="<?= e((string)($row['skin'] ?? '')) ?>">
    </label>
    <?php if (!$isClone): ?>
    <label><?= te('sites.promopages.field.tpl') ?>
      <input name="tpl" maxlength="100" value="<?= e((string)($row['tpl'] ?? '')) ?>">
    </label>
    <?php endif; ?>
    <label><?= te('sites.promopages.field.phone') ?>
      <input name="phone" maxlength="16" value="<?= e((string)($row['phone'] ?? '')) ?>">
    </label>
    <label><?= te('sites.promopages.field.id_parent') ?>
      <select name="id_parent"<?= !empty($row['has_clones']) ? ' disabled' : '' ?>>
        <option value="0"><?= te('sites.promopages.parent_none') ?></option>
        <?php foreach ($parentOptions as $pid => $label): ?>
          <option value="<?= (int)$pid ?>"<?= (int)($row['id_parent'] ?? 0) === (int)$pid ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (!empty($row['has_clones'])): ?>
        <input type="hidden" name="id_parent" value="0">
      <?php endif; ?>
    </label>
    <label><?= te('sites.promopages.field.thanks_url') ?>
      <input name="thanks_url" maxlength="255" value="<?= e((string)($row['thanks_url'] ?? '')) ?>">
    </label>
    <label class="check"><input type="checkbox" name="enabled" value="1"<?= $chk('enabled') ?>> <?= te('sites.promopages.field.enabled') ?></label>
    <?php if (!$isClone): ?>
    <label class="check"><input type="checkbox" name="special_menu" value="1"<?= $chk('special_menu') ?>> <?= te('sites.promopages.field.special_menu') ?></label>
    <?php endif; ?>
  </fieldset>

  <fieldset>
    <legend><?= te('sites.promopages.sec.limits') ?></legend>
    <label><?= te('sites.promopages.field.dt_expire') ?>
      <input type="datetime-local" name="dt_expire_local" value="<?= e((string)($row['dt_expire_local'] ?? '')) ?>">
      <span class="field-hint muted"><?= te('sites.promopages.field.dt_hint') ?></span>
    </label>
    <?php if (!$isClone): ?>
    <label><?= te('sites.promopages.field.dt_use_only') ?>
      <input type="datetime-local" name="dt_use_only_local" value="<?= e((string)($row['dt_use_only_local'] ?? '')) ?>">
    </label>
    <label><?= te('sites.promopages.field.minimal_summ') ?>
      <input type="number" min="0" name="minimal_summ_limit" value="<?= e((string)($row['minimal_summ_limit'] ?? '0')) ?>">
    </label>
    <label class="check"><input type="checkbox" name="can_use_oldclients" value="1"<?= $chk('can_use_oldclients') ?>> <?= te('sites.promopages.field.can_use_oldclients') ?></label>
    <label class="check"><input type="checkbox" name="can_use_newclient" value="1"<?= $chk('can_use_newclient') ?>> <?= te('sites.promopages.field.can_use_newclient') ?></label>
    <label class="check"><input type="checkbox" name="can_use_clubers" value="1"<?= $chk('can_use_clubers') ?>> <?= te('sites.promopages.field.can_use_clubers') ?></label>
    <label class="check"><input type="checkbox" name="can_pay_online_only" value="1"<?= $chk('can_pay_online_only') ?>> <?= te('sites.promopages.field.can_pay_online_only') ?></label>
    <label class="check"><input type="checkbox" name="can_pay_offline_only" value="1"<?= $chk('can_pay_offline_only') ?>> <?= te('sites.promopages.field.can_pay_offline_only') ?></label>
    <label class="check"><input type="checkbox" name="can_use_dco" value="1"<?= $chk('can_use_dco') ?>> <?= te('sites.promopages.field.can_use_dco') ?></label>
    <label class="check"><input type="checkbox" name="can_use_bonus" value="1"<?= $chk('can_use_bonus') ?>> <?= te('sites.promopages.field.can_use_bonus') ?></label>
    <label class="check"><input type="checkbox" name="can_schedule" value="1"<?= $chk('can_schedule') ?>> <?= te('sites.promopages.field.can_schedule') ?></label>
    <label class="check"><input type="checkbox" name="can_use_today_only" value="1"<?= $chk('can_use_today_only') ?>> <?= te('sites.promopages.field.can_use_today_only') ?></label>
    <?php endif; ?>
  </fieldset>

  <?php if (!$isClone): ?>
  <fieldset>
    <legend><?= te('sites.promopages.sec.prices') ?></legend>
    <label><?= te('sites.promopages.field.pricecol') ?>
      <input type="number" min="0" max="255" name="pricecol" value="<?= e((string)($row['pricecol'] ?? '0')) ?>">
    </label>
    <label><?= te('sites.promopages.field.discount') ?>
      <input type="number" min="0" max="99" name="discount" value="<?= e((string)($row['discount'] ?? '0')) ?>">
    </label>
    <?php if ($typeNames): ?>
    <div class="check-grid">
      <span class="field-label"><?= te('sites.promopages.field.types') ?></span>
      <?php foreach ($typeNames as $tid => $tlabel): ?>
        <label class="check"><input type="checkbox" name="types[]" value="<?= (int)$tid ?>"<?= in_array((int)$tid, $types, true) ? ' checked' : '' ?>> <?= e((string)$tlabel) ?></label>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <p class="muted"><?= te('sites.promopages.legacy_blob_hint') ?></p>
  </fieldset>

  <fieldset>
    <legend><?= te('sites.promopages.sec.seo') ?></legend>
    <label><?= te('sites.promopages.field.meta_title') ?>
      <textarea name="meta_title" rows="2"><?= e((string)($row['meta_title'] ?? '')) ?></textarea>
    </label>
    <label><?= te('sites.promopages.field.meta_descr') ?>
      <textarea name="meta_descr" rows="3"><?= e((string)($row['meta_descr'] ?? '')) ?></textarea>
    </label>
    <label><?= te('sites.promopages.field.synonims') ?>
      <textarea name="synonims" rows="2"><?= e((string)($row['synonims'] ?? '')) ?></textarea>
      <span class="field-hint muted"><?= te('sites.promopages.field.synonims_hint') ?></span>
    </label>
  </fieldset>

  <?php
    $modules = is_array($modules ?? null) ? $modules : [];
    $copyFromOptions = is_array($copyFromOptions ?? null) ? $copyFromOptions : [];
    require __DIR__ . '/_promopage_modules.php';
  ?>
  <?php endif; ?>

  <?php if ($staffNames): ?>
  <fieldset>
    <legend><?= te('sites.promopages.field.cosm_limit') ?></legend>
    <div class="check-grid">
      <?php foreach ($staffNames as $sid => $slabel): ?>
        <label class="check"><input type="checkbox" name="cosm_limit[]" value="<?= (int)$sid ?>"<?= in_array((int)$sid, $cosm, true) ? ' checked' : '' ?>> <?= e((string)$slabel) ?></label>
      <?php endforeach; ?>
    </div>
  </fieldset>
  <?php endif; ?>

  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/sites/promopages"><?= te('core.common.cancel') ?></a>
  </div>
</form>
