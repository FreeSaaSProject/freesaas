<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $types */
/** @var array<int,int> $balances */
/** @var array<int,string> $accounts */
/** @var int $from */
/** @var int $to */
/** @var string $message */
/** @var string $error */
?>
<section class="page-head"><h1><?= te('laundry.ship.title') ?></h1></section>
<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>
<p class="muted"><?= e($accounts[$from] ?? ('#' . $from)) ?> → <?= e($accounts[$to] ?? ('#' . $to)) ?></p>
<form method="post" class="form-grid form-wide">
  <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
  <fieldset>
    <?php foreach ($types as $t): ?>
      <?php $tid = (int)$t['id']; ?>
      <label><?= e((string)$t['name']) ?>
        <span class="muted">(<?= te('laundry.col.balance') ?>: <?= (int)($balances[$tid] ?? 0) ?>)</span>
        <input type="number" min="0" name="qty[<?= $tid ?>]" value="0">
      </label>
    <?php endforeach; ?>
    <label><?= te('laundry.col.descr') ?><input name="descr" maxlength="255" value=""></label>
    <label><?= te('laundry.col.weight') ?><input name="weight" value="0"></label>
  </fieldset>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('laundry.ship.submit') ?></button>
    <a class="btn-secondary" href="/laundry"><?= te('core.common.cancel') ?></a>
  </div>
</form>
