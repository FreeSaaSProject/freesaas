<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var string $message */
/** @var string $error */
/** @var array<int,string> $typeNames */
/** @var array<int,string> $productNames */
/** @var array<int,string> $staffNames */
/** @var string $clientLabel */
/** @var string $returnUrl */

$returnUrl = $returnUrl ?? '/procedures';
$dtstart = ProceduresRepository::tsToLocal((int)($row['dtstart'] ?? 0));
$dtend = ProceduresRepository::tsToLocal((int)($row['dtend'] ?? 0));
$dtendPlan = ProceduresRepository::tsToLocal((int)($row['dtend_plan'] ?? 0));
// POST merge may leave datetime-local strings in row
if (isset($row['dtstart']) && is_string($row['dtstart']) && strpos((string)$row['dtstart'], 'T') !== false) {
    $dtstart = (string)$row['dtstart'];
}
if (isset($row['dtend']) && is_string($row['dtend']) && strpos((string)$row['dtend'], 'T') !== false) {
    $dtend = (string)$row['dtend'];
}
if (isset($row['dtend_plan']) && is_string($row['dtend_plan']) && strpos((string)$row['dtend_plan'], 'T') !== false) {
    $dtendPlan = (string)$row['dtend_plan'];
}
?>
<section class="page-head">
  <h1><?= $isNew ? te('procedures.edit.heading_new') : te('procedures.edit.title', ['id' => (int)($row['id'] ?? 0)]) ?></h1>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid form-wide user-edit-form">
  <input type="hidden" name="ret" value="<?= e($returnUrl) ?>">
  <fieldset>
    <legend><?= te('procedures.edit.sec_main') ?></legend>
    <label><?= te('procedures.col.dtstart') ?>
      <input type="datetime-local" name="dtstart" value="<?= e($dtstart) ?>" required>
    </label>
    <label><?= te('procedures.col.dtend') ?>
      <input type="datetime-local" name="dtend" value="<?= e($dtend) ?>">
    </label>
    <label><?= te('procedures.view.dtend_plan') ?>
      <input type="datetime-local" name="dtend_plan" value="<?= e($dtendPlan) ?>">
    </label>
    <label><?= te('procedures.col.type') ?>
      <select name="type" required>
        <option value=""><?= te('procedures.list.all_types') ?></option>
        <?php foreach ($typeNames as $tid => $tlabel): ?>
          <option value="<?= (int)$tid ?>"<?= (int)($row['type'] ?? 0) === (int)$tid ? ' selected' : '' ?>><?= e($tlabel) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= te('procedures.col.product') ?>
      <select name="id_good" required>
        <option value="">—</option>
        <?php foreach ($productNames as $gid => $glabel): ?>
          <option value="<?= (int)$gid ?>"<?= (int)($row['id_good'] ?? 0) === (int)$gid ? ' selected' : '' ?>><?= e($glabel) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= te('procedures.col.summ') ?>
      <input type="number" min="0" name="summ" value="<?= e((string)($row['summ'] ?? '0')) ?>">
    </label>
    <label><?= te('procedures.view.summ_default') ?>
      <input type="number" min="0" name="summ_default" value="<?= e((string)($row['summ_default'] ?? '0')) ?>">
    </label>
    <label><?= te('procedures.col.notice') ?>
      <input name="notice" maxlength="255" value="<?= e((string)($row['notice'] ?? '')) ?>">
    </label>
    <label class="check">
      <input type="checkbox" name="enabled" value="1"<?= !isset($row['enabled']) || !empty($row['enabled']) ? ' checked' : '' ?>>
      <?= te('procedures.col.enabled') ?>
    </label>
    <label class="check">
      <input type="checkbox" name="restored" value="1"<?= !empty($row['restored']) ? ' checked' : '' ?>>
      <?= te('procedures.col.restored') ?>
    </label>
  </fieldset>

  <fieldset>
    <legend><?= te('procedures.edit.sec_people') ?></legend>
    <label><?= te('procedures.col.client') ?>
      <input type="number" min="1" name="id_user" value="<?= e((string)((int)($row['id_user'] ?? 0) ?: '')) ?>" required>
      <?php if ($clientLabel !== ''): ?>
        <span class="muted"><?= e($clientLabel) ?> · <a href="/clients/<?= (int)$row['id_user'] ?>">#<?= (int)$row['id_user'] ?></a></span>
      <?php else: ?>
        <span class="muted"><?= te('procedures.edit.client_hint') ?></span>
      <?php endif; ?>
    </label>
    <label><?= te('procedures.col.admin') ?>
      <select name="id_admin">
        <option value="0">—</option>
        <?php foreach ($staffNames as $sid => $slabel): ?>
          <option value="<?= (int)$sid ?>"<?= (int)($row['id_admin'] ?? 0) === (int)$sid ? ' selected' : '' ?>><?= e($slabel) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= te('procedures.col.operator') ?>
      <select name="id_operator">
        <option value="0">—</option>
        <?php foreach ($staffNames as $sid => $slabel): ?>
          <option value="<?= (int)$sid ?>"<?= (int)($row['id_operator'] ?? 0) === (int)$sid ? ' selected' : '' ?>><?= e($slabel) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </fieldset>

  <fieldset>
    <legend><?= te('procedures.edit.sec_meta') ?></legend>
    <label><?= te('procedures.col.token') ?>
      <input name="token" maxlength="32" value="<?= e((string)($row['token'] ?? '')) ?>">
    </label>
    <?php if (class_exists('TablesRepository', false) && TablesRepository::isActive()): ?>
    <label><?= te('procedures.view.id_table') ?>
      <select name="id_table">
        <?php
        $curTable = ProceduresRepository::normalizeTableId((int)($row['id_table'] ?? 1));
        foreach (TablesRepository::names(true) as $tid => $tlabel):
        ?>
          <option value="<?= (int)$tid ?>"<?= $curTable === (int)$tid ? ' selected' : '' ?>><?= e($tlabel) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php else: ?>
      <input type="hidden" name="id_table" value="1">
    <?php endif; ?>
    <label><?= te('procedures.view.id_terminal') ?>
      <input type="number" min="0" name="id_terminal" value="<?= e((string)($row['id_terminal'] ?? '0')) ?>">
    </label>
    <label><?= te('procedures.view.id_event_parent') ?>
      <input type="number" min="0" name="id_event_parent" value="<?= e((string)($row['id_event_parent'] ?? '0')) ?>">
    </label>
    <label><?= te('procedures.view.onec_id') ?>
      <input name="onec_id" maxlength="40" value="<?= e((string)($row['onec_id'] ?? '')) ?>">
    </label>
    <label><?= te('procedures.view.onec_bracelet') ?>
      <input name="onec_bracelet" maxlength="32" value="<?= e((string)($row['onec_bracelet'] ?? '')) ?>">
    </label>
  </fieldset>

  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="<?= e($returnUrl) ?>"><?= te('core.common.cancel') ?></a>
  </div>
</form>
