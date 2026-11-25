<?php
declare(strict_types=1);
/** @var array<string,mixed> $row */
/** @var list<array{label:string,options:array<int,string>}> $fundOptions */
/** @var list<array<string,mixed>> $overdrawn */
/** @var array<int,float> $balances */
/** @var string $error */
$select = static function (string $name, int $current) use ($fundOptions, $balances): void {
    echo '<select name="' . e($name) . '" required><option value="0">—</option>';
    foreach ($fundOptions as $g) {
        echo '<optgroup label="' . e($g['label']) . '">';
        foreach ($g['options'] as $fid => $fname) {
            $bal = FundMovementsRepository::fmt((float)($balances[(int)$fid] ?? 0.0));
            echo '<option value="' . (int)$fid . '"' . ($current === (int)$fid ? ' selected' : '') . '>'
                . e($fname . ' (' . $bal . ')') . '</option>';
        }
        echo '</optgroup>';
    }
    echo '</select>';
};
?>
<section class="page-head"><h1><?= te('funds.menu.transfer') ?></h1></section>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>
<p class="muted"><?= te('funds.transfer.hint') ?></p>
<?php if ($overdrawn): ?>
  <div class="flash flash-warn">
    <?= te('funds.statement.overdrawn', ['n' => count($overdrawn)]) ?>:
    <?php foreach ($overdrawn as $i => $o): ?>
      <?= $i > 0 ? ', ' : ' ' ?><a href="/funds/transfer?to=<?= (int)$o['id'] ?>"><?= e((string)$o['name']) ?></a> (<?= e(FundMovementsRepository::fmt((float)$o['balance'])) ?>)
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<form method="post" class="form-grid form-wide">
  <label><?= te('funds.transfer.from') ?> <?php $select('from_fund_id', (int)$row['from_fund_id']); ?></label>
  <label><?= te('funds.transfer.to') ?> <?php $select('to_fund_id', (int)$row['to_fund_id']); ?></label>
  <label><?= te('funds.moves.col.sum') ?><input name="summ" inputmode="decimal" value="<?= e((string)$row['summ']) ?>" required></label>
  <label><?= te('funds.transfer.date') ?><input type="date" name="date" value="<?= e((string)$row['date']) ?>"></label>
  <label><?= te('funds.transfer.note') ?><textarea name="note" rows="2"><?= e((string)$row['note']) ?></textarea></label>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('funds.transfer.submit') ?></button>
    <a class="btn-secondary" href="/funds/statement"><?= te('core.common.cancel') ?></a>
  </div>
</form>
