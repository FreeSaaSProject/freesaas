<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $accounts */
/** @var list<array<string,mixed>> $types */
/** @var array<int,array<int,int>> $matrix */
/** @var string $message */
/** @var string $error */
?>
<section class="page-head"><h1><?= te('laundry.inventory.title') ?></h1></section>
<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>
<p class="muted"><?= te('laundry.inventory.intro') ?></p>
<form method="post" class="form-grid form-wide">
  <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
  <div class="table-wrap"><table class="data">
  <thead><tr>
    <th><?= te('laundry.col.account') ?></th>
    <th><?= te('laundry.col.type') ?></th>
    <th><?= te('laundry.inventory.book') ?></th>
    <th><?= te('laundry.inventory.fact') ?></th>
  </tr></thead>
  <tbody>
  <?php foreach ($accounts as $a): ?>
    <?php foreach ($types as $t): ?>
      <?php
      $aid = (int)$a['id'];
      $tid = (int)$t['id'];
      $book = (int)($matrix[$aid][$tid] ?? 0);
      ?>
      <tr>
        <td><?= e((string)$a['name']) ?></td>
        <td><?= e((string)$t['name']) ?></td>
        <td class="<?= $book < 0 ? 'bad-text' : '' ?>"><?= $book ?></td>
        <td>
          <input type="number" min="0" name="fact[<?= $aid ?>][<?= $tid ?>]" value="<?= $book ?>" style="width:6rem">
        </td>
      </tr>
    <?php endforeach; ?>
  <?php endforeach; ?>
  </tbody></table></div>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('laundry.inventory.submit') ?></button>
    <a class="btn-secondary" href="/laundry"><?= te('core.common.cancel') ?></a>
  </div>
</form>
