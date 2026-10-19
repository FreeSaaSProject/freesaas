<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var array<string,mixed> $acl */
/** @var string $message */
/** @var string $error */
/** @var array<int,string> $typeNames */
/** @var array<int,string> $productNames */
/** @var array<int,string> $staffNames */
/** @var string $clientLabel */
/** @var string $returnUrl */

$returnUrl = $returnUrl ?? '/procedures';
$acl = is_array($acl ?? null) ? $acl : ['editable' => []];
$can = static function (string $field) use ($acl): bool {
    return ProceduresEditAcl::canEditField($acl, $field);
};
$ro = static function (string $field) use ($can): string {
    return $can($field) ? '' : ' readonly disabled';
};

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
<?php if (!empty($acl['lock_dtend_timing'])): ?>
  <p class="muted"><?= te('procedures.edit.dtend_auto_hint') ?></p>
<?php endif; ?>

<form method="post" class="form-grid form-wide user-edit-form">
  <input type="hidden" name="ret" value="<?= e($returnUrl) ?>">
  <fieldset>
    <legend><?= te('procedures.edit.sec_main') ?></legend>
    <label><?= te('procedures.col.dtstart') ?>
      <input type="datetime-local" name="dtstart" value="<?= e($dtstart) ?>"<?= $can('dtstart') ? ' required' : '' ?><?= $ro('dtstart') ?>>
      <?php if (!$can('dtstart')): ?><input type="hidden" name="dtstart" value="<?= e($dtstart) ?>"><?php endif; ?>
    </label>
    <label><?= te('procedures.col.dtend') ?>
      <input type="datetime-local" name="dtend" value="<?= e($dtend) ?>"<?= $ro('dtend') ?>>
      <?php if (!$can('dtend')): ?><input type="hidden" name="dtend" value="<?= e($dtend) ?>"><?php endif; ?>
    </label>
    <label><?= te('procedures.view.dtend_plan') ?>
      <input type="datetime-local" name="dtend_plan" value="<?= e($dtendPlan) ?>"<?= $ro('dtend_plan') ?>>
      <?php if (!$can('dtend_plan')): ?><input type="hidden" name="dtend_plan" value="<?= e($dtendPlan) ?>"><?php endif; ?>
    </label>
    <label><?= te('procedures.col.type') ?>
      <select name="type"<?= $can('type') ? ' required' : ' disabled' ?>>
        <option value=""><?= te('procedures.list.all_types') ?></option>
        <?php foreach ($typeNames as $tid => $tlabel): ?>
          <option value="<?= (int)$tid ?>"<?= (int)($row['type'] ?? 0) === (int)$tid ? ' selected' : '' ?>><?= e($tlabel) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (!$can('type')): ?><input type="hidden" name="type" value="<?= (int)($row['type'] ?? 0) ?>"><?php endif; ?>
    </label>
    <label><?= te('procedures.col.product') ?>
      <select name="id_good"<?= $can('id_good') ? ' required' : ' disabled' ?>>
        <option value="">—</option>
        <?php foreach ($productNames as $gid => $glabel): ?>
          <option value="<?= (int)$gid ?>"<?= (int)($row['id_good'] ?? 0) === (int)$gid ? ' selected' : '' ?>><?= e($glabel) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (!$can('id_good')): ?><input type="hidden" name="id_good" value="<?= (int)($row['id_good'] ?? 0) ?>"><?php endif; ?>
    </label>
    <label><?= te('procedures.col.summ') ?>
      <input type="number" min="0" name="summ" value="<?= e((string)($row['summ'] ?? '0')) ?>"<?= $ro('summ') ?>>
      <?php if (!$can('summ')): ?><input type="hidden" name="summ" value="<?= e((string)($row['summ'] ?? '0')) ?>"><?php endif; ?>
    </label>
    <label><?= te('procedures.view.summ_default') ?>
      <input type="number" min="0" name="summ_default" value="<?= e((string)($row['summ_default'] ?? '0')) ?>"<?= $ro('summ_default') ?>>
      <?php if (!$can('summ_default')): ?><input type="hidden" name="summ_default" value="<?= e((string)($row['summ_default'] ?? '0')) ?>"><?php endif; ?>
    </label>
    <label><?= te('procedures.col.notice') ?>
      <input name="notice" maxlength="255" value="<?= e((string)($row['notice'] ?? '')) ?>"<?= $ro('notice') ?>>
      <?php if (!$can('notice')): ?><input type="hidden" name="notice" value="<?= e((string)($row['notice'] ?? '')) ?>"><?php endif; ?>
    </label>
    <label class="check">
      <input type="checkbox" name="enabled" value="1"<?= !isset($row['enabled']) || !empty($row['enabled']) ? ' checked' : '' ?><?= $can('enabled') ? '' : ' disabled' ?>>
      <?= te('procedures.col.enabled') ?>
    </label>
    <?php if (!$can('enabled')): ?>
      <input type="hidden" name="enabled" value="<?= !isset($row['enabled']) || !empty($row['enabled']) ? '1' : '0' ?>">
    <?php endif; ?>
    <label class="check">
      <input type="checkbox" name="restored" value="1"<?= !empty($row['restored']) ? ' checked' : '' ?><?= $can('restored') ? '' : ' disabled' ?>>
      <?= te('procedures.col.restored') ?>
    </label>
    <?php if (!$can('restored')): ?>
      <input type="hidden" name="restored" value="<?= !empty($row['restored']) ? '1' : '0' ?>">
    <?php endif; ?>
  </fieldset>

  <fieldset>
    <legend><?= te('procedures.edit.sec_people') ?></legend>
    <label><?= te('procedures.col.client') ?>
      <input type="number" min="1" name="id_user" value="<?= e((string)((int)($row['id_user'] ?? 0) ?: '')) ?>"<?= $can('id_user') ? ' required' : '' ?><?= $ro('id_user') ?>>
      <?php if (!$can('id_user')): ?><input type="hidden" name="id_user" value="<?= (int)($row['id_user'] ?? 0) ?>"><?php endif; ?>
      <?php if ($clientLabel !== ''): ?>
        <span class="muted"><?= e($clientLabel) ?> · <a href="/clients/<?= (int)$row['id_user'] ?>">#<?= (int)$row['id_user'] ?></a></span>
      <?php else: ?>
        <span class="muted"><?= te('procedures.edit.client_hint') ?></span>
      <?php endif; ?>
    </label>
    <label><?= te('procedures.col.admin') ?>
      <select name="id_admin"<?= $can('id_admin') ? '' : ' disabled' ?>>
        <option value="0">—</option>
        <?php foreach ($staffNames as $sid => $slabel): ?>
          <option value="<?= (int)$sid ?>"<?= (int)($row['id_admin'] ?? 0) === (int)$sid ? ' selected' : '' ?>><?= e($slabel) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (!$can('id_admin')): ?><input type="hidden" name="id_admin" value="<?= (int)($row['id_admin'] ?? 0) ?>"><?php endif; ?>
    </label>
    <label><?= te('procedures.col.operator') ?>
      <select name="id_operator"<?= $can('id_operator') ? '' : ' disabled' ?>>
        <option value="0">—</option>
        <?php foreach ($staffNames as $sid => $slabel): ?>
          <option value="<?= (int)$sid ?>"<?= (int)($row['id_operator'] ?? 0) === (int)$sid ? ' selected' : '' ?>><?= e($slabel) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (!$can('id_operator')): ?><input type="hidden" name="id_operator" value="<?= (int)($row['id_operator'] ?? 0) ?>"><?php endif; ?>
    </label>
  </fieldset>

  <fieldset>
    <legend><?= te('procedures.edit.sec_meta') ?></legend>
    <label><?= te('procedures.col.token') ?>
      <input name="token" maxlength="32" value="<?= e((string)($row['token'] ?? '')) ?>"<?= $ro('token') ?>>
      <?php if (!$can('token')): ?><input type="hidden" name="token" value="<?= e((string)($row['token'] ?? '')) ?>"><?php endif; ?>
    </label>
    <?php if (class_exists('TablesRepository', false) && TablesRepository::isActive()): ?>
    <label><?= te('procedures.view.id_table') ?>
      <select name="id_table"<?= $can('id_table') ? '' : ' disabled' ?>>
        <?php
        $curTable = ProceduresRepository::normalizeTableId((int)($row['id_table'] ?? 1));
        foreach (TablesRepository::names(true) as $tid => $tlabel):
        ?>
          <option value="<?= (int)$tid ?>"<?= $curTable === (int)$tid ? ' selected' : '' ?>><?= e($tlabel) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (!$can('id_table')): ?><input type="hidden" name="id_table" value="<?= (int)$curTable ?>"><?php endif; ?>
    </label>
    <?php else: ?>
      <input type="hidden" name="id_table" value="1">
    <?php endif; ?>
    <label><?= te('procedures.view.id_terminal') ?>
      <input type="number" min="0" name="id_terminal" value="<?= e((string)($row['id_terminal'] ?? '0')) ?>"<?= $ro('id_terminal') ?>>
      <?php if (!$can('id_terminal')): ?><input type="hidden" name="id_terminal" value="<?= e((string)($row['id_terminal'] ?? '0')) ?>"><?php endif; ?>
    </label>
    <label><?= te('procedures.view.id_event_parent') ?>
      <input type="number" min="0" name="id_event_parent" value="<?= e((string)($row['id_event_parent'] ?? '0')) ?>"<?= $ro('id_event_parent') ?>>
      <?php if (!$can('id_event_parent')): ?><input type="hidden" name="id_event_parent" value="<?= e((string)($row['id_event_parent'] ?? '0')) ?>"><?php endif; ?>
    </label>
    <label><?= te('procedures.view.onec_id') ?>
      <input name="onec_id" maxlength="40" value="<?= e((string)($row['onec_id'] ?? '')) ?>"<?= $ro('onec_id') ?>>
      <?php if (!$can('onec_id')): ?><input type="hidden" name="onec_id" value="<?= e((string)($row['onec_id'] ?? '')) ?>"><?php endif; ?>
    </label>
    <label><?= te('procedures.view.onec_bracelet') ?>
      <input name="onec_bracelet" maxlength="32" value="<?= e((string)($row['onec_bracelet'] ?? '')) ?>"<?= $ro('onec_bracelet') ?>>
      <?php if (!$can('onec_bracelet')): ?><input type="hidden" name="onec_bracelet" value="<?= e((string)($row['onec_bracelet'] ?? '')) ?>"><?php endif; ?>
    </label>
  </fieldset>

  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="<?= e($returnUrl) ?>"><?= te('core.common.cancel') ?></a>
  </div>
</form>
<?php if (!empty($extraHtml)): ?>
<?= $extraHtml ?>
<?php endif; ?>
<?php if (!$isNew): ?>
<?= EntityDelete::form('procedures', (int)($row['id'] ?? 0)) ?>
<?php endif; ?>
