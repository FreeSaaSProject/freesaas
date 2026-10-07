<?php
declare(strict_types=1);
/** @var string $message */
/** @var string $error */
/** @var array<string,string> $cfg */
$c = static function (string $key, string $fallback = '') use ($cfg): string {
    return (string)($cfg[$key] ?? $fallback);
};
$on = static function (string $key) use ($c): bool {
    return $c($key, '0') === '1';
};
?>
<section class="page-head">
  <p class="lede"><?= te('likespa.settings.lede') ?></p>
  <p class="muted"><?= te('likespa.settings.ini_hint') ?></p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid likespa-settings">
  <fieldset>
    <legend><?= te('likespa.settings.sec.features') ?></legend>
    <label class="check"><input type="checkbox" name="term_scans_enabled" value="1"<?= $on('term_scans_enabled') ? ' checked' : '' ?>> <?= te('likespa.settings.term_scans_enabled') ?></label>
    <label class="check"><input type="checkbox" name="promo_enabled" value="1"<?= $on('promo_enabled') ? ' checked' : '' ?>> <?= te('likespa.settings.promo_enabled') ?></label>
    <label class="check"><input type="checkbox" name="dops_enabled" value="1"<?= $on('dops_enabled') ? ' checked' : '' ?>> <?= te('likespa.settings.dops_enabled') ?></label>
    <label class="check"><input type="checkbox" name="restores_enabled" value="1"<?= $on('restores_enabled') ? ' checked' : '' ?>> <?= te('likespa.settings.restores_enabled') ?></label>
  </fieldset>

  <fieldset>
    <legend><?= te('likespa.settings.sec.promo') ?></legend>
    <label><?= te('likespa.settings.promo_price_col') ?>
      <input type="number" name="promo_price_col" min="1" value="<?= e($c('promo_price_col', '4')) ?>">
      <span class="muted"><?= te('likespa.settings.promo_price_col_hint') ?></span>
    </label>
    <label><?= te('likespa.settings.promo_min_work_hours') ?>
      <input type="number" name="promo_min_work_hours" min="0" step="0.1" value="<?= e($c('promo_min_work_hours', '6.8')) ?>">
    </label>
    <label><?= te('likespa.settings.promo_script_ids') ?>
      <input type="text" name="promo_script_ids" value="<?= e($c('promo_script_ids', '4,20')) ?>" placeholder="4,20">
      <span class="muted"><?= te('likespa.settings.ids_hint') ?></span>
    </label>
    <label><?= te('likespa.settings.promo_min_script_count') ?>
      <input type="number" name="promo_min_script_count" min="0" value="<?= e($c('promo_min_script_count', '95')) ?>">
    </label>
    <label><?= te('likespa.settings.promo_min_conversion') ?>
      <input type="number" name="promo_min_conversion" min="0" step="0.1" value="<?= e($c('promo_min_conversion', '20')) ?>">
      <span class="muted"><?= te('likespa.settings.promo_min_conversion_hint') ?></span>
    </label>
    <label><?= te('likespa.settings.promo_bonus_playtime') ?>
      <input type="number" name="promo_bonus_playtime" min="1" value="<?= e($c('promo_bonus_playtime', '9000')) ?>">
    </label>
    <label><?= te('likespa.settings.promo_bonus_score_add') ?>
      <input type="number" name="promo_bonus_score_add" step="0.1" value="<?= e($c('promo_bonus_score_add', '1.5')) ?>">
    </label>
    <label class="check"><input type="checkbox" name="promo_term_scan_conversion" value="1"<?= $on('promo_term_scan_conversion') ? ' checked' : '' ?>> <?= te('likespa.settings.promo_term_scan_conversion') ?></label>
    <label><?= te('likespa.settings.promo_term_scan_proc_window_hours') ?>
      <input type="number" name="promo_term_scan_proc_window_hours" min="1" max="24" value="<?= e($c('promo_term_scan_proc_window_hours', '4')) ?>">
    </label>
  </fieldset>

  <fieldset>
    <legend><?= te('likespa.settings.sec.dops') ?></legend>
    <label><?= te('likespa.settings.dops_procedure_type') ?>
      <input type="number" name="dops_procedure_type" min="0" value="<?= e($c('dops_procedure_type', '44')) ?>">
      <span class="muted"><?= te('likespa.settings.dops_procedure_type_hint') ?></span>
    </label>
    <label><?= te('likespa.settings.dops_position_ids') ?>
      <input type="text" name="dops_position_ids" value="<?= e($c('dops_position_ids', '1')) ?>" placeholder="1">
      <span class="muted"><?= te('likespa.settings.dops_position_ids_hint') ?></span>
    </label>
  </fieldset>

  <fieldset>
    <legend><?= te('likespa.settings.sec.restores') ?></legend>
    <label><?= te('likespa.settings.restores_lookback_days') ?>
      <input type="number" name="restores_lookback_days" min="0" max="30" value="<?= e($c('restores_lookback_days', '4')) ?>">
      <span class="muted"><?= te('likespa.settings.restores_lookback_days_hint') ?></span>
    </label>
    <label><?= te('likespa.settings.restores_payment_type') ?>
      <input type="number" name="restores_payment_type" min="1" value="<?= e($c('restores_payment_type', '1')) ?>">
      <span class="muted"><?= te('likespa.settings.restores_payment_type_hint') ?></span>
    </label>
  </fieldset>

  <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
</form>
