<?php
declare(strict_types=1);
/** @var ?array $row */
/** @var bool $isNew */
/** @var string $error */
/** @var string $path */
?>
<?php if ($error !== ''): ?><p class="flash error"><?= e($error) ?></p><?php endif; ?>
<form method="post" class="form">
  <fieldset class="form-fieldset">
    <legend><?= te('audio_dicts.edit.sec_main') ?></legend>
    <label><?= te('audio_dicts.col.name') ?><input name="name" required maxlength="20" value="<?= e((string)($row['name'] ?? '')) ?>"></label>
    <label><?= te('audio_dicts.col.descr') ?><input name="descr" value="<?= e((string)($row['descr'] ?? '')) ?>"></label>
    <label><?= te('audio_dicts.col.color') ?><input name="color" value="<?= e((string)($row['color'] ?? '')) ?>" placeholder="#fff"></label>
    <label><?= te('audio_dicts.col.color_bg') ?><input name="color_background" value="<?= e((string)($row['color_background'] ?? '')) ?>" placeholder="#333"></label>
    <label class="logo-remove"><input type="checkbox" name="enabled" value="1"<?= ($isNew || !empty($row['enabled'])) ? ' checked' : '' ?>> <?= te('audio_dicts.col.enabled') ?></label>
    <label class="logo-remove"><input type="checkbox" name="kpi_enabled" value="1"<?= !empty($row['kpi_enabled']) ? ' checked' : '' ?>> <?= te('audio_dicts.col.kpi') ?></label>
    <label class="logo-remove"><input type="checkbox" name="is_hidden" value="1"<?= !empty($row['is_hidden']) ? ' checked' : '' ?>> <?= te('audio_dicts.col.hidden') ?></label>
  </fieldset>
  <fieldset class="form-fieldset">
    <legend><?= te('audio_dicts.edit.sec_score') ?></legend>
    <label><?= te('audio_dicts.col.score_value') ?><input type="number" name="score_value" value="<?= (int)($row['score_value'] ?? 0) ?>"></label>
    <label><?= te('audio_dicts.col.diversity') ?><input type="number" min="0" name="score_diversity" value="<?= (int)($row['score_diversity'] ?? 0) ?>"></label>
    <label><?= te('audio_dicts.col.inst_min') ?><input type="number" name="score_instances_min" value="<?= (int)($row['score_instances_min'] ?? 0) ?>"></label>
    <label><?= te('audio_dicts.col.inst_max') ?><input type="number" name="score_instances_max" value="<?= (int)($row['score_instances_max'] ?? 0) ?>"></label>
    <label><?= te('audio_dicts.col.volume') ?><input type="number" min="0" name="volume" value="<?= (int)($row['volume'] ?? 0) ?>"></label>
    <label><?= te('audio_dicts.col.strings') ?><input type="number" min="0" name="strings_amount" value="<?= (int)($row['strings_amount'] ?? 0) ?>"></label>
    <label class="logo-remove"><input type="checkbox" name="intersect_flag" value="1"<?= !empty($row['intersect_flag']) ? ' checked' : '' ?>> <?= te('audio_dicts.col.intersect') ?></label>
  </fieldset>
  <div class="form-actions">
    <button type="submit" class="btn-primary"><?= te('core.common.save') ?></button>
    <a href="<?= e($path) ?>"><?= te('core.common.cancel') ?></a>
  </div>
</form>
