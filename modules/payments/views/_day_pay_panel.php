<?php
declare(strict_types=1);
/** @var array<string,mixed>|null $dayPayPanel */
/** @var string $actionUrl */
$p = is_array($dayPayPanel ?? null) ? $dayPayPanel : null;
if ($p === null) {
    return;
}
$actionUrl = (string)($actionUrl ?? '/payments');
$fmt = static function ($v): string {
    return number_format((float)$v, 2, '.', ' ');
};
?>
<?php if ((string)($p['message'] ?? '') !== ''): ?>
  <div class="flash flash-ok"><?= e((string)$p['message']) ?></div>
<?php endif; ?>
<?php if ((string)($p['error'] ?? '') !== ''): ?>
  <div class="flash flash-warn"><?= e((string)$p['error']) ?></div>
<?php endif; ?>

<?php if (!empty($p['show_issuer'])): ?>
<section class="payments-day-pay payments-day-pay-issuer">
  <h2><?= te('payments.day_pay.issuer_title') ?></h2>
  <?php $pendingAll = is_array($p['pending_all'] ?? null) ? $p['pending_all'] : []; ?>
  <?php if ($pendingAll === []): ?>
    <p class="muted"><?= te('payments.day_pay.issuer_empty') ?></p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead>
          <tr>
            <th><?= te('payments.day_pay.col_who') ?></th>
            <th><?= te('payments.col.sum') ?></th>
            <th><?= te('payments.col.dt') ?></th>
            <th><?= te('payments.day_pay.col_issuer') ?></th>
            <th><?= te('payments.day_pay.col_pin') ?></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($pendingAll as $row): ?>
          <tr>
            <td><?= e((string)($row['name'] ?? '')) ?></td>
            <td><?= e($fmt($row['sum'] ?? 0)) ?></td>
            <td><?= e(date('d.m.Y', (int)($row['dt'] ?? 0))) ?></td>
            <td><?= e((string)($row['issuer_line'] ?? '—')) ?></td>
            <td>
              <form method="post" action="<?= e($actionUrl) ?>" class="payments-day-pay-pin-form">
                <input type="hidden" name="day_pay_action" value="verify_pin">
                <input type="hidden" name="payment_id" value="<?= (int)($row['id'] ?? 0) ?>">
                <input name="pin" inputmode="numeric" maxlength="4" size="4" autocomplete="one-time-code" placeholder="····" required>
                <button type="submit" class="btn-primary"><?= te('payments.day_pay.confirm') ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if (!empty($p['show_request'])): ?>
<section class="payments-day-pay payments-day-pay-request">
  <h2><?= te('payments.day_pay.request_title') ?></h2>
  <p><?= te('payments.day_pay.balance', ['sum' => $fmt($p['balance'] ?? 0)]) ?></p>
  <?php $pendingMine = is_array($p['pending_mine'] ?? null) ? $p['pending_mine'] : []; ?>
  <?php if ($pendingMine !== []): ?>
    <?php foreach ($pendingMine as $row): ?>
      <div class="payments-day-pay-pending">
        <p>
          <?= te('payments.day_pay.pending_line', [
              'sum' => $fmt($row['sum'] ?? 0),
              'date' => date('d.m.Y', (int)($row['dt'] ?? 0)),
          ]) ?>
        </p>
        <?php if ((string)($row['issuer_line'] ?? '') !== ''): ?>
          <p><?= te('payments.day_pay.issuer_line', ['issuer' => (string)$row['issuer_line']]) ?></p>
        <?php endif; ?>
        <?php if ((string)($row['pin'] ?? '') !== ''): ?>
          <p class="payments-day-pay-pin"><?= te('payments.day_pay.pin_line', ['pin' => (string)$row['pin']]) ?></p>
        <?php endif; ?>
        <form method="post" action="<?= e($actionUrl) ?>">
          <input type="hidden" name="day_pay_action" value="cancel">
          <input type="hidden" name="payment_id" value="<?= (int)($row['id'] ?? 0) ?>">
          <button type="submit" class="btn-secondary"><?= te('payments.day_pay.cancel') ?></button>
        </form>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <?php
      $gate = is_array($p['gate'] ?? null) ? $p['gate'] : ['ok' => false, 'reason' => ''];
      $opts = is_array($p['request_options'] ?? null) ? $p['request_options'] : [];
      $maxSum = (int)($p['max_sum'] ?? 0);
    ?>
    <?php if (empty($gate['ok'])): ?>
      <p><strong><?= e((string)($gate['reason'] ?? '')) ?></strong></p>
    <?php elseif (!empty($p['blocked'])): ?>
      <p><strong><?= te('payments.bot.day_pay.after_shift') ?></strong></p>
    <?php elseif ($maxSum < 100): ?>
      <p><?= te('payments.bot.day_pay.low_balance') ?></p>
    <?php elseif ($opts === []): ?>
      <p><?= te('payments.bot.day_pay.unavailable') ?></p>
    <?php else: ?>
      <p><?= te('payments.day_pay.available', ['sum' => $fmt($maxSum)]) ?></p>
      <form method="post" action="<?= e($actionUrl) ?>" class="form-grid payments-day-pay-form">
        <input type="hidden" name="day_pay_action" value="request">
        <label>
          <?= te('payments.day_pay.pickup') ?>
          <select name="payout_slot" required>
            <?php foreach ($opts as $opt): ?>
              <option value="<?= e((string)($opt['value'] ?? '')) ?>"><?= e((string)($opt['label'] ?? '')) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <div class="page-actions">
          <button type="submit" class="btn-primary"><?= te('payments.day_pay.request_btn') ?></button>
        </div>
      </form>
    <?php endif; ?>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if (!empty($p['show_director_note'])): ?>
  <div class="flash flash-ok"><?= te('payments.day_pay.director_sync') ?></div>
<?php endif; ?>
