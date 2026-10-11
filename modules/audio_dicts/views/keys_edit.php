<?php
declare(strict_types=1);
/** @var ?array $row */
/** @var bool $isNew */
/** @var string $error */
/** @var string $path */
/** @var array<int,string> $typeOptions */
/** @var array<int,string> $dictOptions */
$typeOptions = is_array($typeOptions ?? null) ? $typeOptions : [];
$dictOptions = is_array($dictOptions ?? null) ? $dictOptions : [];
?>
<?php if ($error !== ''): ?><p class="flash error"><?= e($error) ?></p><?php endif; ?>
<form method="post" class="form">
  <label><?= te('audio_dicts.col.name') ?><input name="name" required value="<?= e((string)($row['name'] ?? '')) ?>"></label>
  <label><?= te('audio_dicts.col.dict') ?>
    <select name="id_dictonary">
      <option value="0">—</option>
      <?php foreach ($dictOptions as $did => $dname): ?>
        <option value="<?= (int)$did ?>"<?= (int)($row['id_dictonary'] ?? 0) === (int)$did ? ' selected' : '' ?>><?= e($dname) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('audio_dicts.col.type') ?>
    <select name="id_type">
      <option value="0">—</option>
      <?php foreach ($typeOptions as $tid => $tname): ?>
        <option value="<?= (int)$tid ?>"<?= (int)($row['id_type'] ?? 0) === (int)$tid ? ' selected' : '' ?>><?= e($tname) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('audio_dicts.col.looklike') ?><input type="number" min="0" name="id_looklike" value="<?= (int)($row['id_looklike'] ?? 0) ?>"></label>
  <label class="logo-remove"><input type="checkbox" name="disabled" value="1"<?= !empty($row['disabled']) ? ' checked' : '' ?>> <?= te('audio_dicts.col.disabled') ?></label>
  <div class="form-actions">
    <button type="submit" class="btn-primary"><?= te('core.common.save') ?></button>
    <a href="<?= e($path) ?>"><?= te('core.common.cancel') ?></a>
  </div>
</form>
