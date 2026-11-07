<?php
declare(strict_types=1);
$pipeId = (int)($row['pipeline_id'] ?? 0);
$stages = $stagesByPipe[$pipeId] ?? [];
?>
<section class="page-head">
  <h1><?= $isNew ? te('crm.deals.heading_new') : e((string)($row['title'] ?? '')) ?></h1>
</section>
<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="form-grid form-wide" id="crm-deal-form">
  <label><?= te('crm.col.title') ?>
    <input name="title" value="<?= e((string)($row['title'] ?? '')) ?>" required>
  </label>
  <label><?= te('crm.col.pipeline') ?>
    <select name="pipeline_id" id="crm-pipeline">
      <?php foreach ($pipelines as $p): ?>
        <option value="<?= (int)$p['id'] ?>"<?= $pipeId === (int)$p['id'] ? ' selected' : '' ?>>
          <?= e((string)$p['name']) ?> (<?= e((string)$p['kind']) ?>)
        </option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('crm.col.stage') ?>
    <select name="stage_id" id="crm-stage">
      <?php foreach ($stages as $s): ?>
        <option value="<?= (int)$s['id'] ?>"<?= (int)($row['stage_id'] ?? 0) === (int)$s['id'] ? ' selected' : '' ?>>
          <?= e((string)$s['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('crm.col.company') ?>
    <select name="company_id">
      <option value="0">—</option>
      <?php foreach ($companies as $cid => $cname): ?>
        <option value="<?= (int)$cid ?>"<?= (int)($row['company_id'] ?? 0) === (int)$cid ? ' selected' : '' ?>><?= e((string)$cname) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('crm.col.amount') ?>
    <input name="amount" type="number" step="0.01" value="<?= e((string)($row['amount'] ?? '0')) ?>">
  </label>
  <label><?= te('crm.col.currency') ?>
    <input name="currency" value="<?= e((string)($row['currency'] ?? 'RUB')) ?>">
  </label>
  <label><?= te('crm.col.source') ?>
    <input name="source" value="<?= e((string)($row['source'] ?? '')) ?>">
  </label>
  <label><?= te('crm.col.responsible') ?>
    <input name="responsible_user_id" type="number" min="0" value="<?= (int)($row['responsible_user_id'] ?? 0) ?>">
  </label>
  <label><?= te('crm.col.contacts') ?>
    <input name="client_ids" value="<?= e(implode(',', array_map('intval', $clientIds))) ?>">
  </label>
  <label><?= te('crm.col.primary_contact') ?>
    <input name="primary_client_id" type="number" min="0" value="<?= (int)$primaryClientId ?>">
  </label>
  <label><?= te('crm.col.notes') ?>
    <textarea name="notes" rows="4"><?= e((string)($row['notes'] ?? '')) ?></textarea>
  </label>
  <?php foreach ($cfDefs as $f): ?>
    <label><?= e((string)$f['name']) ?>
      <input name="cf[<?= (int)$f['id'] ?>]" value="<?= e((string)($cfValues[(int)$f['id']] ?? '')) ?>">
    </label>
  <?php endforeach; ?>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/crm/deals"><?= te('core.common.cancel') ?></a>
  </div>
</form>
<?php if (!empty($canDelete)): ?>
<form method="post" onsubmit="return confirm(<?= tj('core.bulk.confirm_delete') ?>);">
  <input type="hidden" name="action" value="delete">
  <button class="btn-danger" type="submit"><?= te('core.common.delete') ?></button>
</form>
<?php endif; ?>
<script>
(function () {
  var map = <?= json_encode($stagesByPipe, JSON_UNESCAPED_UNICODE) ?>;
  var pipe = document.getElementById('crm-pipeline');
  var stage = document.getElementById('crm-stage');
  if (!pipe || !stage) return;
  pipe.addEventListener('change', function () {
    var list = map[pipe.value] || [];
    stage.innerHTML = '';
    list.forEach(function (s) {
      var o = document.createElement('option');
      o.value = s.id;
      o.textContent = s.name;
      stage.appendChild(o);
    });
  });
})();
</script>
