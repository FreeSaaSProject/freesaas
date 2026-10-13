<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var list<array<string,mixed>> $candidates */
/** @var int $lookbackDays */
/** @var bool $isSuper */
/** @var bool $canEditFields */
/** @var bool $canSubmit */
/** @var string $message */
/** @var string $error */
$signed = (int)($row['id_operator_reviewed'] ?? 0) > 0;
$canEditFields = !empty($canEditFields);
$fieldDisabled = $canEditFields ? '' : ' disabled';
?>
<section class="page-head">
  <h1><?= $isNew ? te('likespa.restores.edit.heading_new') : e('#' . (int)($row['id'] ?? 0)) ?></h1>
  <?php if ($isSuper): ?>
    <p class="muted"><?= te('likespa.restores.edit.lookback_hint', ['days' => (string)$lookbackDays]) ?></p>
  <?php else: ?>
    <p class="muted"><?= te('likespa.restores.edit.lookback_today') ?></p>
  <?php endif; ?>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<?php if (!$isNew && !$canEditFields): ?>
  <div class="flash flash-warn"><?= te('likespa.restores.err.super_only_edit') ?></div>
<?php elseif ($isNew && !$canSubmit): ?>
  <div class="flash flash-warn"><?= te('likespa.restores.err.no_candidates') ?></div>
<?php endif; ?>

<form method="post" class="form-grid form-wide likespa-restore-edit">
  <fieldset>
    <legend><?= te('likespa.restores.edit.sec_why') ?></legend>
    <label><?= te('likespa.restores.field.reason') ?>
      <input name="reason" required value="<?= e((string)($row['reason'] ?? '')) ?>"<?= $fieldDisabled ?>>
    </label>
    <label><?= te('likespa.restores.field.summ') ?>
      <input name="summ" required value="<?= e((string)($row['summ'] ?? '')) ?>"<?= $fieldDisabled ?>>
    </label>
  </fieldset>

  <fieldset>
    <legend><?= te('likespa.restores.edit.sec_proc') ?></legend>
    <?php if ($isNew): ?>
      <label><?= te('likespa.restores.field.procedure') ?>
        <select name="id_procedure" required<?= $fieldDisabled ?>>
          <option value=""><?= te('likespa.restores.field.procedure_pick') ?></option>
          <?php foreach ($candidates as $c): ?>
            <option value="<?= (int)$c['id'] ?>"<?= (int)($row['id_procedure'] ?? 0) === (int)$c['id'] ? ' selected' : '' ?>><?= e((string)$c['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    <?php else: ?>
      <input type="hidden" name="id_procedure" value="<?= (int)($row['id_procedure'] ?? 0) ?>">
      <p>
        <strong>#<?= (int)($row['id_procedure'] ?? 0) ?></strong>
        <?= e(date('d.m.Y H:i', (int)($row['procedure_dtstart'] ?? 0))) ?>
        <?= e((string)($row['product_name'] ?? '')) ?>
      </p>
      <?php if ((int)($row['id_operator_paid'] ?? 0) > 0): ?>
        <p class="muted"><?= te('likespa.restores.col.paid') ?>: <?= e((string)($row['paid_name'] ?? ('#' . (int)$row['id_operator_paid']))) ?></p>
      <?php endif; ?>
    <?php endif; ?>
  </fieldset>

  <?php if ($isSuper): ?>
  <fieldset>
    <legend><?= te('likespa.restores.edit.sec_sign') ?></legend>
    <label class="check">
      <input type="checkbox" name="signed" value="1"<?= $signed ? ' checked' : '' ?><?= $canSubmit ? '' : ' disabled' ?>>
      <?= te('likespa.restores.field.signed') ?>
    </label>
  </fieldset>
  <?php endif; ?>

  <div class="form-actions">
    <?php if ($canSubmit): ?>
      <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <?php endif; ?>
    <a class="btn-secondary" href="/likespa/restores"><?= te('core.common.cancel') ?></a>
  </div>
</form>
