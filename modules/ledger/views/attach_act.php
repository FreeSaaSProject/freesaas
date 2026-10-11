<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $moves */
/** @var list<int> $ids */
/** @var array<int,string> $actOptions */
/** @var array<int,string> $accounts */
/** @var array<int,string> $types */
/** @var string $listUrl */
/** @var string $error */
?>
<section class="page-head"><h1><?= te('ledger.moves.attach_act.title') ?></h1></section>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<p class="muted"><?= te('ledger.moves.attach_act.hint') ?></p>
<ul class="ledger-attach-moves">
  <?php foreach ($moves as $m): ?>
    <li>
      <a href="/ledger/moves/<?= (int)$m['id'] ?>/edit">#<?= (int)$m['id'] ?></a>
      —
      <?= e((string)($types[(int)$m['type_id']] ?? ('#' . (int)$m['type_id']))) ?>
      /
      <?= e((string)($accounts[(int)$m['account_id']] ?? ('#' . (int)$m['account_id']))) ?>
      —
      <?= money_signed_html((float)$m['summ'], (int)$m['direction']) ?>
      <?php if (trim((string)$m['descr']) !== ''): ?>
        <span class="muted">— <?= e(LedgerMovesRepository::descrForList((string)$m['descr'])) ?></span>
      <?php endif; ?>
    </li>
  <?php endforeach; ?>
</ul>

<form method="post" class="form-grid form-wide">
  <input type="hidden" name="ids" value="<?= e(implode(',', $ids)) ?>">
  <input type="hidden" name="ret" value="<?= e($listUrl) ?>">
  <label><?= te('ledger.moves.attach_act.pick') ?>
    <select name="act2_in" required>
      <option value=""><?= te('ledger.moves.type_pick') ?></option>
      <?php foreach ($actOptions as $aid => $alabel): ?>
        <option value="<?= (int)$aid ?>"><?= e($alabel) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <?php if ($actOptions === []): ?>
  <p class="flash flash-warn"><?= te('ledger.moves.attach_act.err.empty_list') ?></p>
  <?php endif; ?>
  <div class="page-actions">
    <button class="btn-primary" type="submit"<?= $actOptions === [] ? ' disabled' : '' ?>><?= te('ledger.moves.attach_act.save') ?></button>
    <a class="btn-secondary" href="<?= e($listUrl) ?>"><?= te('core.common.cancel') ?></a>
  </div>
</form>
