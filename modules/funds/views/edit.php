<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var array<int,string> $parents */
/** @var array<int,string> $amtTypes */
/** @var string $message */
/** @var string $error */
?>
<section class="page-head"><h1><?= $isNew ? te('funds.edit_new') : e((string)($row['name'] ?? '')) ?></h1></section>
<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="form-grid form-wide">
  <label><?= te('funds.col.name') ?><input name="name" value="<?= e((string)($row['name'] ?? '')) ?>" required></label>
  <label><?= te('funds.col.parent') ?><select name="pid"><?php foreach ($parents as $pid => $pname): ?><option value="<?= (int)$pid ?>"<?= (int)($row['pid'] ?? 0) === (int)$pid ? ' selected' : '' ?>><?= e($pname) ?></option><?php endforeach; ?></select></label>
  <label><?= te('funds.col.type') ?><select name="type"><?php foreach ([2, 1] as $t): ?><option value="<?= $t ?>"<?= (int)($row['type'] ?? 0) === $t ? ' selected' : '' ?>><?= e(FundsRepository::typeLabel($t)) ?></option><?php endforeach; ?></select></label>
  <label>REAL_PRICE<input name="real_price" value="<?= e((string)($row['real_price'] ?? '0')) ?>"></label>
  <label>ID_AMT_OUT<select name="amt_out"><option value="0">—</option><?php foreach ($amtTypes as $tid => $tname): ?><option value="<?= (int)$tid ?>"<?= (int)($row['amt_out'] ?? 0) === (int)$tid ? ' selected' : '' ?>><?= e($tname) ?></option><?php endforeach; ?></select></label>
  <label class="check"><input type="checkbox" name="inventory_req" value="1"<?= !empty($row['inventory_req']) ? ' checked' : '' ?>> inventory</label>
  <div class="page-actions"><button class="btn-primary" type="submit"><?= te('core.common.save') ?></button><a class="btn-secondary" href="/funds"><?= te('core.common.cancel') ?></a></div>
</form>
<?php if (empty($isNew) && empty($is_new)): ?>
<?= EntityDelete::form('funds', (int)($row['id'] ?? $item['id'] ?? 0)) ?>
<?php endif; ?>

