<?php
declare(strict_types=1);
/** @var array<string,mixed> $form */
/** @var list<array> $employees */
/** @var string $error */
$f = is_array($form ?? null) ? $form : [];
?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid" id="audio-llm-new">
  <?= NavContext::hiddenField() ?>
  <label><?= te('audio_llm.field.title') ?>
    <input type="text" name="title" value="<?= e((string)($f['title'] ?? '')) ?>">
  </label>
  <label><?= te('audio_llm.field.prompt') ?>
    <textarea name="prompt" rows="8" required><?= e((string)($f['prompt'] ?? '')) ?></textarea>
  </label>

  <label class="check"><input type="checkbox" name="is_cyclic" value="1" id="is_cyclic"<?= !empty($f['is_cyclic']) ? ' checked' : '' ?>> <?= te('audio_llm.field.is_cyclic') ?></label>

  <div id="cyclic-fields" style="<?= empty($f['is_cyclic']) ? 'display:none' : '' ?>">
    <label class="check"><input type="checkbox" name="cyclic_enabled" value="1"<?= !empty($f['cyclic_enabled']) ? ' checked' : '' ?>> <?= te('audio_llm.field.cyclic_enabled') ?></label>
    <label><?= te('audio_llm.field.trigger_good') ?>
      <input type="number" name="trigger_good_id" min="1" value="<?= (int)($f['trigger_good_id'] ?? 0) ?>">
    </label>
    <label><?= te('audio_llm.field.amount_pos') ?>
      <input type="number" name="amount_positive" value="<?= (int)($f['amount_positive'] ?? 0) ?>">
    </label>
    <label><?= te('audio_llm.field.amount_neg') ?>
      <input type="number" name="amount_negative" value="<?= (int)($f['amount_negative'] ?? -150) ?>">
    </label>
  </div>

  <div id="regular-fields" style="<?= !empty($f['is_cyclic']) ? 'display:none' : '' ?>">
    <fieldset>
      <legend><?= te('audio_llm.field.selection') ?></legend>
      <label class="check"><input type="radio" name="selection_mode" value="admin_range"<?= (($f['selection_mode'] ?? '') !== 'record_ids') ? ' checked' : '' ?>> <?= te('audio_llm.mode.admin_range') ?></label>
      <label class="check"><input type="radio" name="selection_mode" value="record_ids"<?= (($f['selection_mode'] ?? '') === 'record_ids') ? ' checked' : '' ?>> <?= te('audio_llm.mode.record_ids') ?></label>
    </fieldset>
    <label><?= te('audio_llm.field.id_admin') ?>
      <select name="id_admin">
        <option value=""><?= te('audio_llm.pick_employee') ?></option>
        <?php foreach ($employees as $emp): ?>
          <option value="<?= (int)$emp['id'] ?>"<?= (int)($f['id_admin'] ?? 0) === (int)$emp['id'] ? ' selected' : '' ?>><?= e((string)$emp['name']) ?> (<?= (int)$emp['id'] ?>)</option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= te('audio_llm.field.dt_start') ?>
      <input type="text" name="dt_start" value="<?= e((string)($f['dt_start'] ?? '')) ?>" placeholder="dd.mm.yyyy HH:MM">
    </label>
    <label><?= te('audio_llm.field.dt_end') ?>
      <input type="text" name="dt_end" value="<?= e((string)($f['dt_end'] ?? '')) ?>" placeholder="dd.mm.yyyy HH:MM">
    </label>
    <label><?= te('audio_llm.field.record_ids') ?>
      <textarea name="record_ids_raw" rows="3"><?= e((string)($f['record_ids_raw'] ?? '')) ?></textarea>
    </label>
  </div>

  <p class="form-actions">
    <button type="submit" class="btn"><?= te('audio_llm.field.create') ?></button>
    <a class="btn-secondary" href="/audio/llm-studies"><?= te('core.common.cancel') ?></a>
  </p>
</form>
<script>
(function(){
  var cb = document.getElementById('is_cyclic');
  var cyc = document.getElementById('cyclic-fields');
  var reg = document.getElementById('regular-fields');
  if (!cb || !cyc || !reg) return;
  function sync(){ cyc.style.display = cb.checked ? '' : 'none'; reg.style.display = cb.checked ? 'none' : ''; }
  cb.addEventListener('change', sync); sync();
})();
</script>
