<?php
declare(strict_types=1);
/** @var string $message */
/** @var string $error */
/** @var array<string,mixed> $settings */
/** @var bool $procentsOn */
/** @var bool $contractsOn */
/** @var bool $ledgerOn */
/** @var array<int,string> $statusOptions */
/** @var array<int,string> $accountOptions */
$auto = (string)($settings['auto_procent_accrual'] ?? '1') === '1';
$minPay = (int)($settings['day_pay_min_pay'] ?? 3000);
$accountId = (int)($settings['day_pay_payout_account_id'] ?? 0);
$issuerStatus2 = (int)($settings['day_pay_issuer_status2'] ?? 17);
$deadlinePenalty = (string)($settings['deadline_penalty'] ?? '1') === '1';
?>
<section class="page-head">
  <p class="lede"><?= te('payments.settings.lede') ?></p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid" style="max-width:40rem">
  <fieldset class="form-fieldset">
    <legend><?= te('payments.settings.sec.procent') ?></legend>
    <?php if (!$procentsOn): ?>
      <p class="muted"><?= te('payments.settings.procents_off') ?></p>
    <?php endif; ?>
    <label class="logo-remove">
      <input type="hidden" name="auto_procent_accrual" value="0">
      <input type="checkbox" name="auto_procent_accrual" value="1"<?= $auto ? ' checked' : '' ?>>
      <?= te('payments.settings.auto_procent_accrual') ?>
    </label>
    <p class="muted"><?= te('payments.settings.auto_procent_hint') ?></p>
  </fieldset>

  <fieldset class="form-fieldset">
    <legend><?= te('payments.settings.sec.day_pay') ?></legend>
    <p class="muted"><?= te('payments.settings.day_pay_intro') ?></p>

    <label>
      <?= te('payments.settings.day_pay_min_pay') ?>
      <input type="number" name="day_pay_min_pay" min="0" step="1" value="<?= (int)$minPay ?>">
    </label>
    <p class="muted"><?= te('payments.settings.day_pay_min_pay_hint') ?></p>

    <label>
      <?= te('payments.settings.day_pay_account') ?>
      <?php if ($ledgerOn): ?>
        <select name="day_pay_payout_account_id">
          <?php foreach ($accountOptions as $aid => $aname): ?>
            <option value="<?= (int)$aid ?>"<?= (int)$aid === $accountId ? ' selected' : '' ?>><?= e($aname) ?></option>
          <?php endforeach; ?>
        </select>
      <?php else: ?>
        <input type="number" name="day_pay_payout_account_id" min="0" step="1" value="<?= (int)$accountId ?>">
      <?php endif; ?>
    </label>
    <?php if (!$ledgerOn): ?>
      <p class="muted"><?= te('payments.settings.ledger_off_hint') ?></p>
    <?php else: ?>
      <p class="muted"><?= te('payments.settings.day_pay_account_hint') ?></p>
    <?php endif; ?>

    <label>
      <?= te('payments.settings.day_pay_issuer_status2') ?>
      <select name="day_pay_issuer_status2">
        <?php foreach ($statusOptions as $sid => $sname): ?>
          <option value="<?= (int)$sid ?>"<?= (int)$sid === $issuerStatus2 ? ' selected' : '' ?>><?= e($sname) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <p class="muted"><?= te('payments.settings.day_pay_issuer_status2_hint') ?></p>
  </fieldset>

  <?php if ($contractsOn): ?>
  <fieldset class="form-fieldset">
    <legend><?= te('contracts.settings.deadline_penalty') ?></legend>
    <p class="muted"><?= te('contracts.settings.deadline_penalty_hint') ?></p>
    <label class="logo-remove">
      <input type="hidden" name="deadline_penalty" value="0">
      <input type="checkbox" name="deadline_penalty" value="1"<?= $deadlinePenalty ? ' checked' : '' ?>>
      <?= te('contracts.settings.deadline_penalty_label') ?>
    </label>
  </fieldset>
  <?php endif; ?>

  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/payments"><?= te('core.common.cancel') ?></a>
  </div>
</form>
