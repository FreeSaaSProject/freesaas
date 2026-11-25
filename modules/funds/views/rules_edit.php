<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var list<array{label:string,options:array<int,string>}> $fundOptions */
/** @var array<int,string> $typeNames */
/** @var string $message */
/** @var string $error */
$rid = (int)($row['id'] ?? 0);
$types = array_map('intval', (array)($row['base_types'] ?? []));
$dateVal = static function ($ts): string {
    return (int)$ts > 0 ? date('Y-m-d', (int)$ts) : '';
};
?>
<section class="page-head"><h1><?= $isNew ? te('funds.rules.edit_new') : te('funds.rules.edit', ['id' => $rid]) ?></h1></section>
<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="form-grid form-wide">
  <label><?= te('funds.rules.col.fund') ?>
    <select name="fund_id" required>
      <option value="0">—</option>
      <?php foreach ($fundOptions as $g): ?>
        <optgroup label="<?= e($g['label']) ?>">
          <?php foreach ($g['options'] as $fid => $fname): ?>
            <option value="<?= (int)$fid ?>"<?= (int)($row['fund_id'] ?? 0) === (int)$fid ? ' selected' : '' ?>><?= e($fname) ?></option>
          <?php endforeach; ?>
        </optgroup>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('funds.rules.col.name') ?><input name="name" value="<?= e((string)($row['name'] ?? '')) ?>"></label>
  <label><?= te('funds.rules.field.kind') ?>
    <select name="kind">
      <?php foreach (['percent', 'fixed'] as $k): ?>
        <option value="<?= $k ?>"<?= ($row['kind'] ?? 'percent') === $k ? ' selected' : '' ?>><?= te('funds.rules.kind.' . $k) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('funds.rules.field.percent') ?><input name="percent" inputmode="decimal" value="<?= e((string)($row['percent'] ?? '0')) ?>"></label>
  <label><?= te('funds.rules.field.fixed_sum') ?><input name="fixed_sum" inputmode="decimal" value="<?= e((string)($row['fixed_sum'] ?? '0')) ?>"></label>
  <label><?= te('funds.rules.col.period') ?>
    <select name="period">
      <?php foreach (['week', 'month'] as $p): ?>
        <option value="<?= $p ?>"<?= ($row['period'] ?? 'week') === $p ? ' selected' : '' ?>><?= te('funds.rules.period.' . $p) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('funds.rules.field.base_mode') ?>
    <select name="base_mode">
      <?php foreach (['all_income', 'types'] as $m): ?>
        <option value="<?= $m ?>"<?= ($row['base_mode'] ?? 'all_income') === $m ? ' selected' : '' ?>><?= te('funds.rules.basemode.' . $m) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('funds.rules.field.base_types') ?>
    <select name="base_types[]" multiple size="8">
      <?php foreach ($typeNames as $tid => $tname): ?>
        <option value="<?= (int)$tid ?>"<?= in_array((int)$tid, $types, true) ? ' selected' : '' ?>><?= e($tname) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('funds.rules.field.dt_from') ?><input type="date" name="dt_from" value="<?= e($dateVal($row['dt_from'] ?? 0)) ?>"></label>
  <label><?= te('funds.rules.field.dt_to') ?><input type="date" name="dt_to" value="<?= e($dateVal($row['dt_to'] ?? 0)) ?>"></label>
  <label><?= te('funds.rules.field.note') ?><textarea name="note" rows="3"><?= e((string)($row['note'] ?? '')) ?></textarea></label>
  <label class="check"><input type="checkbox" name="enabled" value="1"<?= !empty($row['enabled']) ? ' checked' : '' ?>> <?= te('funds.rules.col.enabled') ?></label>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/funds/rules"><?= te('core.common.cancel') ?></a>
  </div>
</form>
<?php if (!$isNew): ?>
<?= EntityDelete::form('fund_rules', $rid) ?>
<?php endif; ?>
