<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var string $message */
/** @var string $error */
$fmt = static function (int $ts): string {
    return $ts > 0 ? date('Y-m-d', $ts) : '';
};
?>
<section class="page-head">
  <h1><?= $isNew ? te('acquisition.sources.new') : e((string)($row['name'] ?? '')) ?></h1>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid form-wide">
  <input type="hidden" name="action" value="save">
  <label><?= te('acquisition.sources.col.name') ?>
    <input name="name" value="<?= e((string)($row['name'] ?? '')) ?>" required maxlength="100">
  </label>
  <label><?= te('acquisition.sources.field.contacts') ?>
    <textarea name="contacts" rows="2"><?= e((string)($row['contacts'] ?? '')) ?></textarea>
  </label>
  <label><?= te('acquisition.sources.field.description') ?>
    <textarea name="description" rows="3"><?= e((string)($row['description'] ?? '')) ?></textarea>
  </label>
  <label><?= te('acquisition.sources.field.result') ?>
    <textarea name="result" rows="2"><?= e((string)($row['result'] ?? '')) ?></textarea>
  </label>
  <label><?= te('acquisition.sources.field.dt_start') ?>
    <input type="date" name="dt_start" value="<?= e($fmt((int)($row['dt_start'] ?? 0))) ?>">
  </label>
  <label><?= te('acquisition.sources.field.dt_end') ?>
    <input type="date" name="dt_end" value="<?= e($fmt((int)($row['dt_end'] ?? 0))) ?>">
  </label>
  <label class="check">
    <input type="checkbox" name="enabled" value="1"<?= !empty($row['enabled']) ? ' checked' : '' ?>>
    <?= te('acquisition.sources.col.enabled') ?>
  </label>
  <label class="check">
    <input type="checkbox" name="src_enabled" value="1"<?= !empty($row['src_enabled']) ? ' checked' : '' ?>>
    <?= te('acquisition.sources.col.src_enabled') ?>
  </label>

  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/acquisition/sources"><?= te('core.common.cancel') ?></a>
  </div>
</form>
<?php if (!$isNew): ?>
<form method="post" class="form-grid" onsubmit="return confirm(<?= json_encode(t('acquisition.common.confirm_delete'), JSON_UNESCAPED_UNICODE) ?>);">
  <input type="hidden" name="action" value="delete">
  <button class="btn-danger" type="submit"><?= te('core.common.delete') ?></button>
</form>
<?php endif; ?>
