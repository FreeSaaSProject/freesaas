<?php
declare(strict_types=1);
/** @var array<string,mixed> $report */
/** @var array<int,string> $accounts */
/** @var int $newsId */
/** @var string $message */
/** @var string $error */
$summary = $report['summary'] ?? [];
$candidates = $report['candidates'] ?? [];
$laundryId = (int)($report['laundry_id'] ?? 0);
$termsId = (int)($report['terms_id'] ?? 0);
$laundryName = $accounts[$laundryId] ?? ('#' . $laundryId);
$termsName = $accounts[$termsId] ?? ('#' . $termsId);
$laundryBal = (int)($report['laundry_balance'] ?? 0);
$termsBal = (int)($report['terms_balance'] ?? 0);
?>
<section class="page-head">
  <div class="page-head-row">
    <h1><?= te('sheets.reconcile.title') ?></h1>
    <div class="page-actions">
      <a class="btn-secondary" href="/news/<?= (int)$newsId ?>"><?= te('sheets.reconcile.open_ticket', ['id' => (int)$newsId]) ?></a>
      <a class="btn-secondary" href="/sheets/moves"><?= te('sheets.menu.moves') ?></a>
    </div>
  </div>
</section>
<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e_nl($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e_nl($error) ?></div><?php endif; ?>

<p class="muted"><?= te('sheets.reconcile.intro') ?></p>
<p class="muted" style="margin:0.5rem 0 1rem">
  <strong><?= te('sheets.balances.title') ?>:</strong>
  <span class="<?= $termsBal < 0 ? 'bad-text' : '' ?>"><?= e($termsName) ?>: <strong><?= $termsBal ?></strong></span>
  ·
  <span class="<?= $laundryBal < 0 ? 'bad-text' : '' ?>"><?= e($laundryName) ?>: <strong><?= $laundryBal ?></strong></span>
</p>
<ul class="muted">
  <li><?= te('sheets.reconcile.stat_matched', ['n' => (int)($summary['matched'] ?? 0)]) ?></li>
  <li><?= te('sheets.reconcile.stat_missing', ['n' => (int)($summary['missing_recv'] ?? 0), 'qty' => (int)($summary['missing_qty'] ?? 0)]) ?></li>
  <li><?= te('sheets.reconcile.stat_mismatch', ['n' => (int)($summary['qty_mismatch'] ?? 0), 'qty' => (int)($summary['mismatch_delta'] ?? 0)]) ?></li>
  <li><?= te('sheets.reconcile.stat_cap', ['left' => $laundryBal]) ?></li>
</ul>

<?php if ($candidates === []): ?>
  <p class="muted"><?= te('sheets.reconcile.empty') ?></p>
<?php else: ?>
<form method="post" class="form-grid form-wide">
  <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
  <input type="hidden" name="news_id" value="<?= (int)$newsId ?>">
  <div class="page-actions" style="margin-bottom:0.75rem">
    <button class="btn-primary" type="submit"><?= te('sheets.reconcile.apply') ?></button>
    <button class="btn-secondary" type="button" id="sheets-reconcile-suggested"><?= te('sheets.reconcile.select_suggested') ?></button>
  </div>
  <div class="table-wrap"><table class="data">
  <thead><tr>
    <th></th>
    <th><?= te('sheets.col.dt') ?></th>
    <th><?= te('sheets.reconcile.col.kind') ?></th>
    <th><?= te('sheets.reconcile.col.comment') ?></th>
    <th><?= te('sheets.col.summ') ?></th>
    <th><?= te('sheets.reconcile.col.move') ?></th>
    <th><?= te('sheets.reconcile.col.delta') ?></th>
    <th><?= te('sheets.col.descr') ?></th>
  </tr></thead>
  <tbody>
  <?php foreach ($candidates as $c): ?>
    <?php
    $kind = (string)($c['kind'] ?? '');
    $kindLabel = $kind === 'qty_mismatch'
        ? t('sheets.reconcile.kind_mismatch')
        : t('sheets.reconcile.kind_missing');
    $suggested = !empty($c['suggested']);
    ?>
    <tr>
      <td><input type="checkbox" name="keys[]" value="<?= e((string)$c['key']) ?>"<?= $suggested ? ' data-suggested="1"' : '' ?>></td>
      <td class="muted"><?= e((string)($c['day'] ?? '')) ?></td>
      <td><?= e($kindLabel) ?></td>
      <td><a href="/news/<?= (int)$newsId ?>#c<?= (int)$c['comment_id'] ?>">#<?= (int)$c['comment_id'] ?></a></td>
      <td><strong><?= (int)($c['qty'] ?? 0) ?></strong></td>
      <td class="muted"><?php
        $mid = (int)($c['move_id'] ?? 0);
        if ($mid > 0):
          ?><a href="/sheets/moves/<?= $mid ?>/edit">#<?= $mid ?></a> (<?= (int)($c['move_qty'] ?? 0) ?>)<?php
        else:
          echo '—';
          if (!empty($c['nearby_moves']) && is_array($c['nearby_moves'])) {
              echo ' <span title="' . e(implode(', ', $c['nearby_moves'])) . '">(' . e(t('sheets.reconcile.nearby')) . ')</span>';
          }
        endif;
      ?></td>
      <td class="<?= (int)($c['delta'] ?? 0) > 0 ? 'bad-text' : '' ?>">+<?= (int)($c['delta'] ?? 0) ?></td>
      <td><?= e((string)($c['text'] ?? '')) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('sheets.reconcile.apply') ?></button>
  </div>
</form>
<script>
(function () {
  var btn = document.getElementById('sheets-reconcile-suggested');
  if (!btn) return;
  btn.addEventListener('click', function () {
    document.querySelectorAll('input[name="keys[]"][data-suggested="1"]').forEach(function (el) {
      el.checked = true;
    });
  });
})();
</script>
<?php endif; ?>
