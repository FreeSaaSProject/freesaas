<?php
declare(strict_types=1);
/** @var array<int,string> $accounts */
/** @var list<array<string,mixed>> $types */
/** @var array<int,array<int,int>> $matrix */
/** @var bool $started */
/** @var list<array<string,mixed>> $open */
/** @var bool $canShip */
/** @var bool $canEdit */
$typeNames = [];
foreach ($types as $t) {
    $typeNames[(int)$t['id']] = (string)$t['name'];
}
$termsId = LaundryConfig::termsAccountId();
$laundryId = LaundryConfig::laundryAccountId();
?>
<section id="laundry" class="laundry-ticket-panel" style="margin:1.25rem 0;padding:1rem;border:1px solid var(--line,#ddd);border-radius:8px">
  <div class="page-head-row" style="margin-bottom:0.75rem">
    <h2 style="margin:0;font-size:1.1rem"><?= te('laundry.embed.title') ?></h2>
    <div class="page-actions">
      <a class="btn-secondary" href="/laundry"><?= te('laundry.embed.open_module') ?></a>
      <?php if ($canEdit): ?><a class="btn-secondary" href="/laundry/inventory"><?= te('laundry.home.inventory') ?></a><?php endif; ?>
    </div>
  </div>
  <?php if (!$started): ?>
    <div class="flash flash-warn"><?= te('laundry.balances.none') ?></div>
  <?php else: ?>
    <p class="muted" style="margin:0 0 0.75rem">
      <strong><?= te('laundry.balances.title') ?>:</strong>
      <?php
      $parts = [];
      foreach ([$termsId => ($accounts[$termsId] ?? 'ТЕРМЫ'), $laundryId => ($accounts[$laundryId] ?? 'Стирка')] as $aid => $aname) {
          $bits = [];
          $sum = 0;
          foreach ($types as $t) {
              $b = (int)($matrix[(int)$aid][(int)$t['id']] ?? 0);
              if ($b !== 0) {
                  $bits[] = (string)$t['name'] . ' ' . $b;
              }
              $sum += $b;
          }
          $parts[] = e($aname) . ': <strong class="' . ($sum < 0 ? 'bad-text' : '') . '">' . $sum . '</strong>'
              . ($bits ? ' <span class="muted">(' . e(implode(', ', $bits)) . ')</span>' : '');
      }
      echo implode(' · ', $parts);
      ?>
    </p>
  <?php endif; ?>

  <?php if ($canShip && $started): ?>
  <details open style="margin-bottom:1rem">
    <summary><strong><?= te('laundry.ship.title') ?></strong></summary>
    <form method="post" class="form-grid" style="margin-top:0.75rem">
      <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
      <input type="hidden" name="action" value="laundry_ship">
      <div style="display:flex;flex-wrap:wrap;gap:0.75rem">
        <?php foreach ($types as $t): ?>
          <?php $tid = (int)$t['id']; $bal = (int)($matrix[$termsId][$tid] ?? 0); ?>
          <label style="min-width:7rem"><?= e((string)$t['name']) ?>
            <input type="number" min="0" name="qty[<?= $tid ?>]" value="0" style="width:5rem"
                   title="<?= te('laundry.col.balance') ?>: <?= $bal ?>">
          </label>
        <?php endforeach; ?>
        <label><?= te('laundry.col.weight') ?><input name="weight" value="0" style="width:5rem"></label>
        <label style="flex:1;min-width:10rem"><?= te('laundry.col.descr') ?><input name="descr" maxlength="255"></label>
        <button class="btn-primary" type="submit"><?= te('laundry.ship.submit') ?></button>
      </div>
    </form>
  </details>

  <details open>
    <summary><strong><?= te('laundry.accept.title') ?></strong></summary>
    <?php if ($open === []): ?>
      <p class="muted"><?= te('laundry.accept.no_open') ?></p>
    <?php else: ?>
    <form method="post" style="margin-top:0.75rem">
      <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
      <input type="hidden" name="action" value="laundry_accept">
      <div class="table-wrap"><table class="data">
      <thead><tr>
        <th><?= te('laundry.col.dt') ?></th>
        <th>ID</th>
        <th><?= te('laundry.col.type') ?></th>
        <th><?= te('laundry.col.open') ?></th>
        <th><?= te('laundry.accept.return_now') ?></th>
      </tr></thead>
      <tbody>
      <?php foreach ($open as $s): ?>
        <?php foreach ($s['lines'] ?? [] as $l): ?>
          <tr>
            <td class="muted"><?= (int)$s['dt'] > 0 ? e(date('d.m.Y H:i', (int)$s['dt'])) : '—' ?></td>
            <td>#<?= (int)$s['id'] ?></td>
            <td><?= e($typeNames[(int)$l['id_item_type']] ?? ('#' . (int)$l['id_item_type'])) ?></td>
            <td><?= (int)$l['qty_open'] ?></td>
            <td><input type="number" min="0" max="<?= (int)$l['qty_open'] ?>" name="ret[<?= (int)$l['id'] ?>]" value="0" style="width:5rem"></td>
          </tr>
        <?php endforeach; ?>
      <?php endforeach; ?>
      </tbody></table></div>
      <div class="page-actions" style="margin-top:0.5rem">
        <label><?= te('laundry.col.weight') ?> <input name="weight" value="0" style="width:5rem"></label>
        <label><?= te('laundry.col.descr') ?> <input name="descr" maxlength="255"></label>
        <button class="btn-primary" type="submit"><?= te('laundry.accept.submit') ?></button>
      </div>
    </form>
    <?php endif; ?>
  </details>
  <?php elseif ($canShip && !$started): ?>
    <p class="muted"><a href="/laundry/inventory"><?= te('laundry.home.inventory') ?></a></p>
  <?php endif; ?>
</section>
