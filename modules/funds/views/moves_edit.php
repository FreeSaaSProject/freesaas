<?php
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var array<int,string> $funds */
/** @var string $message */
/** @var string $error */
?>
<section class="page-head"><h1><?= $isNew ? te('funds.moves.edit_new') : e('#' . (int)($row['id'] ?? 0)) ?></h1></section>
<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="form-grid form-wide">
  <label><?= te('funds.moves.col.fund') ?><select name="fund_id" required><?php foreach ($funds as $fid => $fname): ?><option value="<?= (int)$fid ?>"<?= (int)($row['fund_id'] ?? 0) === (int)$fid ? ' selected' : '' ?>><?= e($fname) ?></option><?php endforeach; ?></select></label>
  <label><?= te('funds.moves.col.type') ?><select name="type_id"><?php foreach (array_keys(FundMovementsRepository::typeDirections()) as $t): ?><option value="<?= (int)$t ?>"<?= (int)($row['type_id'] ?? 0) === (int)$t ? ' selected' : '' ?>><?= e(FundMovementsRepository::typeLabel((int)$t)) ?></option><?php endforeach; ?></select></label>
  <label><?= te('funds.moves.col.sum') ?><input name="summ" value="<?= e((string)($row['summ'] ?? '0')) ?>" required></label>
  <label><?= te('funds.moves.col.sum_out_cap') ?><input name="summ_out_cap" value="<?= e((string)($row['summ_out_cap'] ?? '0')) ?>"></label>
  <label><?= te('funds.moves.col.sum_out_fund') ?><input name="summ_out_fund" value="<?= e((string)($row['summ_out_fund'] ?? '0')) ?>"></label>
  <label><?= te('funds.moves.col.sum_out_infl') ?><input name="summ_out_infl" value="<?= e((string)($row['summ_out_infl'] ?? '0')) ?>"></label>
  <label><?= te('funds.moves.col.sum_out_destroy') ?><input name="summ_out_destroy" value="<?= e((string)($row['summ_out_destroy'] ?? '0')) ?>"></label>
  <label><?= te('funds.moves.col.amort_start') ?><input type="datetime-local" name="dt_amort_start" value="<?= e(!empty($row['dt_amort_start']) && is_numeric($row['dt_amort_start']) ? date('Y-m-d\TH:i', (int)$row['dt_amort_start']) : '') ?>"></label>
  <label><?= te('funds.moves.col.amort_months') ?><input name="amort_months" value="<?= e((string)($row['amort_months'] ?? '0')) ?>"></label>
  <label><?= te('funds.moves.col.name') ?><input name="name" value="<?= e((string)($row['name'] ?? '')) ?>"></label>
  <label><?= te('ledger.moves.col.descr') ?><input name="descr" value="<?= e((string)($row['descr'] ?? '')) ?>"></label>
  <div class="page-actions"><button class="btn-primary" type="submit"><?= te('core.common.save') ?></button><a class="btn-secondary" href="/fund-movements"><?= te('core.common.cancel') ?></a></div>
</form>
