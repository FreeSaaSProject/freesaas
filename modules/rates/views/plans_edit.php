<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var array<int,string> $staff */
/** @var string $message */
/** @var string $error */
$tiersText = (string)($row['bonus_tiers_raw'] ?? '');
if ($tiersText === '' && !empty($row['bonus_tiers']) && is_array($row['bonus_tiers'])) {
    $lines = [];
    foreach ($row['bonus_tiers'] as $t) {
        if (!is_array($t)) {
            continue;
        }
        $lines[] = ((float)($t['from'] ?? 0)) . ':' . ((float)($t['pct'] ?? 0));
    }
    $tiersText = implode("\n", $lines);
}
?>
<section class="page-head">
  <h1><?= $isNew ? te('rates.plans.new') : ('#' . (int)($row['id'] ?? 0)) ?></h1>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid form-wide">
  <input type="hidden" name="action" value="save">
  <label><?= te('rates.col.operator') ?>
    <select name="operator_id" required>
      <option value="0">—</option>
      <?php foreach ($staff as $sid => $sname): ?>
        <option value="<?= (int)$sid ?>"<?= (int)($row['operator_id'] ?? 0) === (int)$sid ? ' selected' : '' ?>><?= e($sname) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('rates.plans.col.period') ?>
    <input type="month" name="period_ym" value="<?= e((string)($row['period_ym'] ?? date('Y-m'))) ?>" required>
  </label>
  <label><?= te('rates.plans.col.plan_sum') ?>
    <input type="number" step="1" name="plan_sum" value="<?= e((string)(float)($row['plan_sum'] ?? 0)) ?>">
  </label>
  <label><?= te('rates.plans.col.norm') ?>
    <input type="number" step="1" name="norm_rub_hour" value="<?= e((string)(float)($row['norm_rub_hour'] ?? 0)) ?>">
  </label>
  <label><?= te('rates.plans.col.tiers') ?>
    <textarea name="bonus_tiers" rows="5" placeholder="<?= te('rates.plans.tiers_hint') ?>"><?= e($tiersText) ?></textarea>
  </label>
  <label><?= te('rates.col.descr') ?>
    <input name="note" value="<?= e((string)($row['note'] ?? '')) ?>" maxlength="255">
  </label>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/rates/plans"><?= te('core.common.cancel') ?></a>
  </div>
</form>
<?php if (!$isNew): ?>
<form method="post" onsubmit="return confirm(<?= json_encode(t('rates.plans.confirm_delete'), JSON_UNESCAPED_UNICODE) ?>);">
  <input type="hidden" name="action" value="delete">
  <button class="btn-danger" type="submit"><?= te('core.common.delete') ?></button>
</form>
<?php endif; ?>
