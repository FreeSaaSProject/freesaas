<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var array<int,string> $suppliers */
/** @var array<int,string> $accounts */
/** @var array<int,string> $types */
/** @var array<string,array<string,string>> $presets */
/** @var list<array<string,mixed>> $dryRun */
/** @var string $message */
/** @var string $error */
/** @var bool $canDelete */
$preset = (string)($row['parser_preset'] ?? '');
?>
<section class="page-head"><h1><?= $isNew ? te('remittances.edit.title_new') : e('#' . (int)$row['id']) ?></h1></section>
<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e_nl($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e_nl($error) ?></div><?php endif; ?>

<form method="post" class="form-grid form-wide">
  <label><?= te('remittances.field.name') ?>
    <input type="text" name="name" value="<?= e((string)($row['name'] ?? '')) ?>" maxlength="255"
           placeholder="<?= te('remittances.field.name_ph') ?>">
  </label>
  <label class="check"><input type="checkbox" name="enabled" value="1"<?= !empty($row['enabled']) ? ' checked' : '' ?>>
    <?= te('remittances.field.enabled') ?></label>

  <label><?= te('remittances.field.supplier') ?>
    <select name="supplier_id" required>
      <option value=""><?= te('remittances.field.supplier_pick') ?></option>
      <?php foreach ($suppliers as $sid => $sname): ?>
        <option value="<?= (int)$sid ?>"<?= (int)($row['supplier_id'] ?? 0) === (int)$sid ? ' selected' : '' ?>><?= e($sname) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <p class="muted"><?= te('remittances.field.supplier_hint') ?></p>

  <label><?= te('remittances.field.account') ?>
    <select name="account_id">
      <option value="0"><?= te('remittances.field.any') ?></option>
      <?php foreach ($accounts as $aid => $aname): ?>
        <option value="<?= (int)$aid ?>"<?= (int)($row['account_id'] ?? 0) === (int)$aid ? ' selected' : '' ?>><?= e($aname) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('remittances.field.type') ?>
    <select name="type_id">
      <option value="0"><?= te('remittances.field.any') ?></option>
      <?php foreach ($types as $tid => $tname): ?>
        <option value="<?= (int)$tid ?>"<?= (int)($row['type_id'] ?? 0) === (int)$tid ? ' selected' : '' ?>><?= e($tname) ?></option>
      <?php endforeach; ?>
    </select>
  </label>

  <label><?= te('remittances.field.fee_percent') ?>
    <input type="text" name="fee_percent" value="<?= e((string)($row['fee_percent'] ?? '2')) ?>" inputmode="decimal">
  </label>
  <label><?= te('remittances.field.fee_posting') ?>
    <select name="fee_posting">
      <option value="none"<?= ($row['fee_posting'] ?? '') === 'none' ? ' selected' : '' ?>><?= te('remittances.fee_posting.none') ?></option>
      <option value="expense_move"<?= ($row['fee_posting'] ?? '') === 'expense_move' ? ' selected' : '' ?>><?= te('remittances.fee_posting.expense_move') ?></option>
    </select>
  </label>
  <label><?= te('remittances.field.fee_move_type') ?>
    <select name="fee_move_type_id">
      <option value="0"><?= te('remittances.field.none') ?></option>
      <?php foreach ($types as $tid => $tname): ?>
        <option value="<?= (int)$tid ?>"<?= (int)($row['fee_move_type_id'] ?? 0) === (int)$tid ? ' selected' : '' ?>><?= e($tname) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('remittances.field.fee_account') ?>
    <select name="fee_account_id">
      <option value="0"><?= te('remittances.field.same_as_move') ?></option>
      <?php foreach ($accounts as $aid => $aname): ?>
        <option value="<?= (int)$aid ?>"<?= (int)($row['fee_account_id'] ?? 0) === (int)$aid ? ' selected' : '' ?>><?= e($aname) ?></option>
      <?php endforeach; ?>
    </select>
  </label>

  <label><?= te('remittances.field.expected_channels') ?>
    <input type="text" name="expected_channels" value="<?= e((string)($row['expected_channels'] ?? 'spa_ops')) ?>">
  </label>
  <p class="muted"><?= te('remittances.field.channels_hint') ?></p>
  <label class="check"><input type="checkbox" name="auto_set_supplier" value="1"<?= !empty($row['auto_set_supplier']) ? ' checked' : '' ?>>
    <?= te('remittances.field.auto_set_supplier') ?></label>

  <h2><?= te('remittances.edit.sec_parser') ?></h2>
  <label><?= te('remittances.field.preset') ?>
    <select name="parser_preset">
      <option value=""><?= te('remittances.field.preset_none') ?></option>
      <?php foreach ($presets as $pid => $meta): ?>
        <option value="<?= e($pid) ?>"<?= $preset === $pid ? ' selected' : '' ?>><?= te((string)$meta['label_key']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <div class="page-actions">
    <button type="submit" name="action" value="apply_preset" class="btn-secondary"><?= te('remittances.action.apply_preset') ?></button>
  </div>
  <label><?= te('remittances.field.pattern_period_from') ?>
    <input type="text" name="pattern_period_from" value="<?= e((string)($row['pattern_period_from'] ?? '')) ?>" class="mono"></label>
  <label><?= te('remittances.field.pattern_period_to') ?>
    <input type="text" name="pattern_period_to" value="<?= e((string)($row['pattern_period_to'] ?? '')) ?>" class="mono"></label>
  <label><?= te('remittances.field.pattern_gross') ?>
    <input type="text" name="pattern_gross" value="<?= e((string)($row['pattern_gross'] ?? '')) ?>" class="mono"></label>
  <label><?= te('remittances.field.pattern_fee') ?>
    <input type="text" name="pattern_fee" value="<?= e((string)($row['pattern_fee'] ?? '')) ?>" class="mono"></label>
  <p class="muted"><?= te('remittances.field.pattern_hint') ?></p>

  <div class="page-actions">
    <button type="submit" name="action" value="save" class="btn-primary"><?= te('core.common.save') ?></button>
    <button type="submit" name="action" value="dry_run" class="btn-secondary"><?= te('remittances.action.dry_run') ?></button>
    <a class="btn-ghost" href="/remittances"><?= te('core.common.cancel') ?></a>
    <?php if ($canDelete): ?>
      <button type="submit" name="action" value="delete" class="btn-danger"
              onclick="return confirm(<?= tj('remittances.confirm.delete') ?>)"><?= te('core.common.delete') ?></button>
    <?php endif; ?>
  </div>
</form>

<?php if ($dryRun !== []): ?>
<section class="remit-dryrun">
  <h2><?= te('remittances.dry_run.title') ?></h2>
  <table class="data-table">
    <thead>
      <tr>
        <th><?= te('remittances.col.move') ?></th>
        <th><?= te('remittances.col.net') ?></th>
        <th><?= te('remittances.col.parsed') ?></th>
        <th><?= te('remittances.col.descr') ?></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($dryRun as $d): ?>
      <?php $p = is_array($d['parsed'] ?? null) ? $d['parsed'] : []; ?>
      <tr>
        <td><a href="/ledger/moves/<?= (int)$d['move_id'] ?>/edit">#<?= (int)$d['move_id'] ?></a></td>
        <td><?= e(RemittanceReconcile::fmtMoney((float)$d['summ'])) ?></td>
        <td>
          <?php if (!empty($p['ok'])): ?>
            <?= e((string)$p['period_from']) ?>…<?= e((string)$p['period_to']) ?>;
            G <?= e(RemittanceReconcile::fmtMoney((float)$p['gross'])) ?>;
            F <?= e(RemittanceReconcile::fmtMoney((float)$p['fee'])) ?>
          <?php else: ?>
            <span class="flash-warn"><?= e((string)($p['error'] ?? 'fail')) ?></span>
          <?php endif; ?>
        </td>
        <td><code><?= e((string)$d['descr']) ?></code></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endif; ?>
