<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var string $message */
/** @var string $error */
/** @var bool $canEdit */
$flag = static function (string $key) use ($row): bool {
    return !empty($row[$key]);
};
?>
<section class="page-head">
  <h1><?= $isNew ? te('terminals.edit.heading_new') : e((string)($row['name'] ?? '')) ?></h1>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid form-wide">
  <label><?= te('terminals.col.name') ?>
    <input name="name" value="<?= e((string)($row['name'] ?? '')) ?>" required<?= $canEdit ? '' : ' disabled' ?>>
  </label>
  <label><?= te('terminals.col.code') ?>
    <input name="code" value="" autocomplete="off" placeholder="<?= $isNew ? '' : e((string)($row['code_masked'] ?? '')) ?>"<?= $canEdit ? '' : ' disabled' ?><?= $isNew ? ' required' : '' ?>>
    <span class="muted"><?= $isNew ? te('terminals.edit.code_new_hint') : te('terminals.edit.code_hint') ?></span>
  </label>
  <label class="check">
    <input type="checkbox" name="enabled" value="1"<?= $flag('enabled') || $isNew ? ' checked' : '' ?><?= $canEdit ? '' : ' disabled' ?>>
    <?= te('terminals.col.enabled') ?>
  </label>

  <fieldset class="terminals-flags">
    <legend><?= te('terminals.edit.flags') ?></legend>
    <?php
    $flags = [
        'is_admin_reg_ignore' => 'terminals.col.is_admin_reg_ignore',
        'masseur_mode' => 'terminals.col.masseur_mode',
        'is_term_admin_mode' => 'terminals.col.is_term_admin_mode',
        'is_employee_procedure_notify' => 'terminals.col.is_employee_procedure_notify',
        'is_bracelet_num_prompt' => 'terminals.col.is_bracelet_num_prompt',
        'is_terminal_review_invite' => 'terminals.col.is_terminal_review_invite',
        'is_terminal_show_day' => 'terminals.col.is_terminal_show_day',
        'is_terminal_flyer_discount' => 'terminals.col.is_terminal_flyer_discount',
        'is_terminal_client_history' => 'terminals.col.is_terminal_client_history',
    ];
    foreach ($flags as $k => $labelKey):
        $checked = $flag($k) || ($isNew && $k === 'is_employee_procedure_notify');
    ?>
    <label class="check">
      <input type="checkbox" name="<?= e($k) ?>" value="1"<?= $checked ? ' checked' : '' ?><?= $canEdit ? '' : ' disabled' ?>>
      <?= te($labelKey) ?>
    </label>
    <?php endforeach; ?>
  </fieldset>

  <label><?= te('terminals.col.executors') ?>
    <input name="executors" value="<?= e((string)($row['executors'] ?? '')) ?>"<?= $canEdit ? '' : ' disabled' ?>>
  </label>
  <label><?= te('terminals.col.goods') ?>
    <input name="goods" value="<?= e((string)($row['goods'] ?? '')) ?>"<?= $canEdit ? '' : ' disabled' ?>>
  </label>
  <label><?= te('terminals.col.id_user_locked') ?>
    <input type="number" name="id_user_locked" min="0" value="<?= (int)($row['id_user_locked'] ?? 0) ?>"<?= $canEdit ? '' : ' disabled' ?>>
  </label>

  <?php if (!$isNew): ?>
  <div class="terminals-runtime muted">
    <strong><?= te('terminals.col.runtime') ?></strong>
    <div><?= te('terminals.col.id_admin') ?>: <?= (int)($row['id_admin'] ?? 0) ?></div>
    <div><?= te('terminals.col.id_executor') ?>: <?= (int)($row['id_executor'] ?? 0) ?></div>
  </div>
  <?php endif; ?>

  <div class="page-actions">
    <?php if ($canEdit): ?>
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <?php endif; ?>
    <a class="btn-secondary" href="/terminals"><?= te('core.common.cancel') ?></a>
  </div>
</form>
