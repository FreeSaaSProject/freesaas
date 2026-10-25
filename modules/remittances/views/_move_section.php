<?php
declare(strict_types=1);
/** @var int $moveId */
/** @var array<string,mixed> $row */
/** @var array<string,mixed>|null $ag */
/** @var array<string,mixed> $batch */
/** @var array<string,float> $byDay */
/** @var bool $ignored */
/** @var bool $canFee */
/** @var string $status */
$csrf = class_exists('Csrf', false) ? Csrf::token() : '';
$agName = is_array($ag) ? (string)($ag['name'] ?? '') : '';
?>
<section class="remit-move-section card-like">
  <h2><?= te('remittances.move.section_title') ?><?= $agName !== '' ? ': ' . e($agName) : '' ?></h2>
  <?php if ($ignored): ?>
    <p class="muted"><?= te('remittances.move.ignored_hint') ?></p>
  <?php elseif ($status === 'unparsed'): ?>
    <p class="flash flash-warn"><?= te('remittances.move.unparsed', ['err' => (string)($batch['parse_error'] ?? '')]) ?></p>
  <?php else: ?>
    <table class="data-table remit-summary">
      <tr><th><?= te('remittances.col.period') ?></th>
        <td><?= e((string)($batch['period_from'] ?? '')) ?> — <?= e((string)($batch['period_to'] ?? '')) ?></td></tr>
      <tr><th><?= te('remittances.col.expected') ?></th>
        <td><?= e(RemittanceReconcile::fmtMoney((float)($batch['expected'] ?? 0))) ?></td></tr>
      <tr><th><?= te('remittances.col.gross') ?></th>
        <td><?= e(RemittanceReconcile::fmtMoney((float)($batch['gross'] ?? 0))) ?></td></tr>
      <tr><th><?= te('remittances.col.fee') ?></th>
        <td><?= e(RemittanceReconcile::fmtMoney((float)($batch['fee'] ?? 0))) ?></td></tr>
      <tr><th><?= te('remittances.col.net') ?></th>
        <td><?= e(RemittanceReconcile::fmtMoney((float)($batch['net'] ?? 0))) ?></td></tr>
      <tr><th><?= te('remittances.col.delta_gross') ?></th>
        <td class="<?= abs((float)($batch['delta_gross'] ?? 0)) > 0.02 ? 'remit-delta-bad' : 'remit-delta-ok' ?>">
          <?= e(RemittanceReconcile::fmtMoney((float)($batch['delta_gross'] ?? 0))) ?>
        </td></tr>
      <tr><th><?= te('remittances.col.delta_net') ?></th>
        <td class="<?= abs((float)($batch['delta_net'] ?? 0)) > 0.02 ? 'remit-delta-bad' : 'remit-delta-ok' ?>">
          <?= e(RemittanceReconcile::fmtMoney((float)($batch['delta_net'] ?? 0))) ?>
        </td></tr>
      <?php if ((int)($batch['fee_move_id'] ?? 0) > 0): ?>
      <tr><th><?= te('remittances.col.fee_move') ?></th>
        <td><a href="/ledger/moves/<?= (int)$batch['fee_move_id'] ?>/edit">#<?= (int)$batch['fee_move_id'] ?></a></td></tr>
      <?php endif; ?>
    </table>
    <?php if ($byDay !== []): ?>
    <h3><?= te('remittances.move.by_day') ?></h3>
    <table class="data-table">
      <thead><tr><th><?= te('remittances.col.day') ?></th><th><?= te('remittances.col.expected') ?></th></tr></thead>
      <tbody>
      <?php foreach ($byDay as $d => $amt): ?>
        <tr><td><?= e((string)$d) ?></td><td><?= e(RemittanceReconcile::fmtMoney((float)$amt)) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  <?php endif; ?>

  <form method="post" action="/remittances/moves/<?= (int)$moveId ?>/action" class="inline-form remit-actions">
    <?php if ($csrf !== ''): ?><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><?php endif; ?>
    <input type="hidden" name="ret" value="/ledger/moves/<?= (int)$moveId ?>/edit">
    <button type="submit" name="action" value="sync" class="btn-secondary"><?= te('remittances.action.sync') ?></button>
    <?php if ($ignored): ?>
      <button type="submit" name="action" value="unignore" class="btn-secondary"><?= te('remittances.action.unignore') ?></button>
    <?php else: ?>
      <button type="submit" name="action" value="ignore" class="btn-ghost"><?= te('remittances.action.ignore') ?></button>
    <?php endif; ?>
    <?php if ($canFee): ?>
      <button type="submit" name="action" value="post_fee" class="btn-primary"><?= te('remittances.action.post_fee') ?></button>
    <?php endif; ?>
  </form>
  <p class="muted"><a href="/remittances"><?= te('remittances.move.settings_link') ?></a></p>
</section>
