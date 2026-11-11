<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var list<array{label:string,options:array<int,string>}> $fundOptions */
/** @var array<string,mixed>|null $dps */
/** @var int $dpsId */
/** @var list<array<string,mixed>> $linkedDps */
/** @var array<string,mixed>|null $fundRow */
/** @var string $message */
/** @var string $error */
$currentFund = (int)($row['fund_id'] ?? 0);
$amortRaw = $row['dt_amort_start'] ?? '';
$amortValue = is_numeric($amortRaw) && (int)$amortRaw > 0
    ? date('Y-m-d\TH:i', (int)$amortRaw)
    : (is_string($amortRaw) ? $amortRaw : '');
?>
<section class="page-head"><h1><?= $isNew ? te('funds.moves.edit_new') : e('#' . (int)($row['id'] ?? 0) . ' ' . (string)($row['name'] ?? '')) ?></h1></section>
<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>
<?php if ($dps !== null): ?>
  <p class="flash flash-ok"><?= te('funds.dps.from_dps', [
      'id' => (int)$dpsId,
      'sum' => FundMovementsRepository::fmt((float)$dps['summ']),
  ]) ?> <a href="/ledger/moves/<?= (int)$dpsId ?>/edit"><?= te('funds.dps.open_dps') ?></a></p>
<?php endif; ?>
<form method="post" class="form-grid form-wide">
  <?php if ($dps !== null): ?><input type="hidden" name="dps" value="<?= (int)$dpsId ?>"><?php endif; ?>
  <label><?= te('funds.moves.col.fund') ?>
    <select name="fund_id" required>
      <option value=""><?= te('funds.moves.fund_pick') ?></option>
      <?php foreach ($fundOptions as $g): ?>
        <?php if ($g['label'] !== ''): ?><optgroup label="<?= e($g['label']) ?>"><?php endif; ?>
        <?php foreach ($g['options'] as $fid => $fname): ?>
          <option value="<?= (int)$fid ?>"<?= $currentFund === (int)$fid ? ' selected' : '' ?>><?= e($fname) ?></option>
        <?php endforeach; ?>
        <?php if ($g['label'] !== ''): ?></optgroup><?php endif; ?>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('funds.moves.col.type') ?><select name="type_id"><?php foreach (array_keys(FundMovementsRepository::typeDirections()) as $t): ?><option value="<?= (int)$t ?>"<?= (int)($row['type_id'] ?? 0) === (int)$t ? ' selected' : '' ?>><?= e(FundMovementsRepository::typeLabel((int)$t)) ?></option><?php endforeach; ?></select></label>
  <label><?= te('funds.moves.col.sum') ?><input name="summ" inputmode="decimal" data-money-input value="<?= e((string)($row['summ'] ?? '0')) ?>" required></label>
  <label><?= te('funds.moves.col.sum_out_cap') ?><input name="summ_out_cap" inputmode="decimal" data-money-input value="<?= e((string)($row['summ_out_cap'] ?? '0')) ?>"></label>
  <label><?= te('funds.moves.col.sum_out_fund') ?><input name="summ_out_fund" inputmode="decimal" data-money-input value="<?= e((string)($row['summ_out_fund'] ?? '0')) ?>"></label>
  <label><?= te('funds.moves.col.sum_out_infl') ?><input name="summ_out_infl" inputmode="decimal" data-money-input value="<?= e((string)($row['summ_out_infl'] ?? '0')) ?>"></label>
  <label><?= te('funds.moves.col.sum_out_destroy') ?><input name="summ_out_destroy" inputmode="decimal" data-money-input value="<?= e((string)($row['summ_out_destroy'] ?? '0')) ?>"></label>
  <label><?= te('funds.moves.col.amort_start') ?><input type="datetime-local" name="dt_amort_start" value="<?= e($amortValue) ?>"></label>
  <label><?= te('funds.moves.col.amort_months') ?><input name="amort_months" inputmode="numeric" value="<?= e((string)($row['amort_months'] ?? '0')) ?>"></label>
  <label><?= te('funds.moves.col.name') ?><input name="name" value="<?= e((string)($row['name'] ?? '')) ?>"></label>
  <label><?= te('funds.moves.col.descr') ?><textarea name="descr" rows="3"><?= e((string)($row['descr'] ?? '')) ?></textarea></label>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te($dps !== null ? 'funds.dps.create_submit' : 'core.common.save') ?></button>
    <?php if ($fundRow !== null): ?><a class="btn-secondary" href="/funds/<?= (int)$fundRow['id'] ?>"><?= te('funds.view.open') ?></a><?php endif; ?>
    <a class="btn-secondary" href="<?= $dps !== null ? '/ledger/moves/' . (int)$dpsId . '/edit' : '/fund-movements' ?>"><?= te('core.common.cancel') ?></a>
  </div>
</form>
<?php if (!$isNew): ?>
<section class="funds-dps-section">
  <h2><?= te('funds.moves.col.dps') ?></h2>
  <?php if ($linkedDps === []): ?>
    <p class="muted"><?= te('funds.moves.no_dps') ?></p>
  <?php else: ?>
    <ul>
      <?php foreach ($linkedDps as $d): ?>
        <li><a href="/ledger/moves/<?= (int)$d['id'] ?>/edit">#<?= (int)$d['id'] ?> <?= e(date('d.m.Y', (int)$d['dt'])) ?></a>
          <?= e(FundMovementsRepository::fmt((float)$d['summ'])) ?> <span class="muted"><?= e(mb_substr((string)$d['descr'], 0, 120)) ?></span></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <?php if (!empty($row['mismatch'])): ?>
    <p class="flash flash-warn"><?= te('funds.dps.mismatch', [
        'diff' => FundMovementsRepository::fmt((float)$row['summ'] - (float)$row['dps_sum']),
    ]) ?></p>
  <?php endif; ?>
</section>
<?= EntityDelete::form('fund_movements', (int)($row['id'] ?? 0)) ?>
<?php endif; ?>
