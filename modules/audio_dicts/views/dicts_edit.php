<?php
declare(strict_types=1);
/** @var ?array $row */
/** @var bool $isNew */
/** @var string $error */
/** @var string $path */
?>
<?php if ($error !== ''): ?><p class="flash error"><?= e($error) ?></p><?php endif; ?>
<form method="post" class="form">
  <label><?= te('audio_dicts.col.name') ?><input name="name" required maxlength="50" value="<?= e((string)($row['name'] ?? '')) ?>"></label>
  <label><?= te('audio_dicts.col.descr') ?><input name="descr" value="<?= e((string)($row['descr'] ?? '')) ?>"></label>
  <label><?= te('audio_dicts.col.color') ?><input name="color" value="<?= e((string)($row['color'] ?? '')) ?>"></label>
  <label><?= te('audio_dicts.col.color_bg') ?><input name="color_background" value="<?= e((string)($row['color_background'] ?? '')) ?>"></label>
  <label><?= te('audio_dicts.col.tracking') ?><input name="tracking_code" value="<?= e((string)($row['tracking_code'] ?? '')) ?>"></label>
  <label class="logo-remove"><input type="checkbox" name="disabled" value="1"<?= !empty($row['disabled']) ? ' checked' : '' ?>> <?= te('audio_dicts.col.disabled') ?></label>
  <label class="logo-remove"><input type="checkbox" name="is_review_req" value="1"<?= !empty($row['is_review_req']) ? ' checked' : '' ?>> <?= te('audio_dicts.col.review_req') ?></label>
  <div class="form-actions">
    <button type="submit" class="btn-primary"><?= te('core.common.save') ?></button>
    <a href="<?= e($path) ?>"><?= te('core.common.cancel') ?></a>
  </div>
</form>
