<?php
declare(strict_types=1);
/** @var array<string,string> $form */
/** @var array<int,string> $companies */
$stageMap = [];
foreach ($stagesByPipe as $pid => $list) {
    $stageMap[(int)$pid] = array_map(static function (array $s): array {
        return ['id' => (int)$s['id'], 'name' => (string)$s['name']];
    }, $list);
}
$pipeId = (int)$form['pipeline_id'];
?>
<section class="page-head">
  <h1><?= te('crm.quick.title') ?></h1>
  <p class="muted"><?= te('crm.quick.hint') ?></p>
</section>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="crm-quick">
  <fieldset class="crm-panel">
    <legend><?= te('crm.quick.company') ?></legend>
    <div class="form-grid">
      <?php if ($companies): ?>
      <label class="crm-span-2"><?= te('crm.quick.company_existing') ?>
        <select name="company_id">
          <option value=""><?= te('crm.quick.company_new_option') ?></option>
          <?php foreach ($companies as $cid => $cname): ?>
            <option value="<?= (int)$cid ?>"<?= (string)$form['company_id'] === (string)$cid ? ' selected' : '' ?>><?= e((string)$cname) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php endif; ?>
      <label class="crm-span-2"><?= te('crm.col.name') ?>
        <input name="company_name" value="<?= e($form['company_name']) ?>" placeholder="<?= te('crm.quick.company_name_hint') ?>">
      </label>
      <label><?= te('crm.col.inn') ?><input name="company_inn" value="<?= e($form['company_inn']) ?>" inputmode="numeric"></label>
      <label><?= te('crm.col.phone') ?><input name="company_phone" type="tel" value="<?= e($form['company_phone']) ?>"></label>
    </div>
  </fieldset>

  <fieldset class="crm-panel">
    <legend><?= te('crm.quick.contact') ?></legend>
    <div class="form-grid">
      <label><?= te('crm.contact.name') ?><input name="contact_name" value="<?= e($form['contact_name']) ?>"></label>
      <label><?= te('crm.contact.tel') ?><input name="contact_tel" type="tel" value="<?= e($form['contact_tel']) ?>"></label>
      <label><?= te('crm.contact.mail') ?><input name="contact_mail" type="email" value="<?= e($form['contact_mail']) ?>"></label>
      <label><?= te('crm.contact.telegram') ?><input name="contact_telegram" value="<?= e($form['contact_telegram']) ?>" placeholder="@username"></label>
      <label><?= te('crm.contact.max') ?><input name="contact_max" value="<?= e($form['contact_max']) ?>"></label>
      <label><?= te('crm.contact.whatsapp') ?><input name="contact_whatsapp" type="tel" value="<?= e($form['contact_whatsapp']) ?>"></label>
      <label><?= te('crm.contact.role') ?><input name="contact_role" maxlength="64" value="<?= e($form['contact_role']) ?>" placeholder="<?= te('crm.contact.role_hint') ?>"></label>
    </div>
    <p class="muted"><?= te('crm.quick.contact_any_channel') ?></p>
    <?php if (!$canCreateClient): ?><p class="muted"><?= te('crm.quick.contact_existing_only') ?></p><?php endif; ?>
  </fieldset>

  <fieldset class="crm-panel">
    <legend><?= te('crm.quick.deal') ?></legend>
    <div class="form-grid">
      <label class="crm-span-2"><?= te('crm.col.title') ?>
        <input name="title" value="<?= e($form['title']) ?>" placeholder="<?= te('crm.quick.title_hint') ?>">
      </label>
      <label><?= te('crm.col.pipeline') ?>
        <select name="pipeline_id" data-crm-pipeline>
          <?php foreach ($pipelines as $p): ?>
            <option value="<?= (int)$p['id'] ?>"<?= $pipeId === (int)$p['id'] ? ' selected' : '' ?>><?= e((string)$p['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><?= te('crm.col.stage') ?>
        <select name="stage_id" data-crm-stage data-stages="<?= e((string)json_encode($stageMap, JSON_UNESCAPED_UNICODE)) ?>">
          <?php foreach ($stagesByPipe[$pipeId] ?? [] as $s): ?>
            <option value="<?= (int)$s['id'] ?>"><?= e((string)$s['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><?= te('crm.col.amount') ?><input name="amount" type="number" step="0.01" min="0" value="<?= e($form['amount']) ?>"></label>
      <label><?= te('crm.col.source') ?><input name="source" maxlength="64" value="<?= e($form['source']) ?>" placeholder="<?= te('crm.col.source_hint') ?>"></label>
      <label><?= te('crm.col.responsible') ?>
        <select name="responsible_user_id">
          <option value="0">—</option>
          <?php foreach ($staff as $uid => $uname): ?>
            <option value="<?= (int)$uid ?>"<?= (string)$form['responsible_user_id'] === (string)$uid ? ' selected' : '' ?>><?= e((string)$uname) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="crm-span-2"><?= te('crm.col.description') ?>
        <textarea name="notes" rows="3"><?= e($form['notes']) ?></textarea>
      </label>
    </div>
  </fieldset>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('crm.quick.submit') ?></button>
    <a class="btn-secondary" href="/crm/deals"><?= te('core.common.cancel') ?></a>
  </div>
</form>
