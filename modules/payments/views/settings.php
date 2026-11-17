<?php
declare(strict_types=1);
/** @var string $message */
/** @var string $error */
/** @var array<string,mixed> $settings */
/** @var bool $procentsOn */
/** @var bool $contractsOn */
/** @var bool $audioContractsOn */
/** @var bool $pollInvitesOn */
/** @var bool $ledgerOn */
/** @var array<int,string> $statusOptions */
/** @var array<int,string> $accountOptions */
$auto = (string)($settings['auto_procent_accrual'] ?? '1') === '1';
$minPay = (int)($settings['day_pay_min_pay'] ?? 3000);
$accountId = (int)($settings['day_pay_payout_account_id'] ?? 0);
$issuerStatus2 = (int)($settings['day_pay_issuer_status2'] ?? 17);
$deadlinePenalty = (string)($settings['deadline_penalty'] ?? '1') === '1';
$autoPayWork = (string)($settings['auto_pay_on_work_accept'] ?? '0') === '1';
$autoPayAudio = (string)($settings['auto_pay_on_audio_contract'] ?? '0') === '1';
$hideNews = (string)($settings['hide_salary_unread_news'] ?? '0') === '1';
$hideReviews = (string)($settings['hide_salary_unread_reviews'] ?? '0') === '1';
$hideSuper = (string)($settings['hide_salary_apply_superadmin'] ?? '0') === '1';
$pollInvitesOn = !empty($pollInvitesOn);
$audioContractsOn = !empty($audioContractsOn);
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

  <fieldset class="form-fieldset">
    <legend><?= te('payments.settings.sec.salary_gate') ?></legend>
    <p class="muted"><?= te('payments.settings.salary_gate_intro') ?></p>
    <label class="logo-remove">
      <input type="hidden" name="hide_salary_unread_news" value="0">
      <input type="checkbox" name="hide_salary_unread_news" value="1"<?= $hideNews ? ' checked' : '' ?>>
      <?= te('payments.settings.hide_salary_unread_news') ?>
    </label>
    <p class="muted"><?= te('payments.settings.hide_salary_unread_news_hint') ?></p>
    <label class="logo-remove">
      <input type="hidden" name="hide_salary_unread_reviews" value="0">
      <input type="checkbox" name="hide_salary_unread_reviews" value="1"<?= $hideReviews ? ' checked' : '' ?>>
      <?= te('payments.settings.hide_salary_unread_reviews') ?>
    </label>
    <?php if (!$pollInvitesOn): ?>
      <p class="muted"><?= te('payments.settings.poll_invites_off') ?></p>
    <?php else: ?>
      <p class="muted"><?= te('payments.settings.hide_salary_unread_reviews_hint') ?></p>
    <?php endif; ?>
    <label class="logo-remove">
      <input type="hidden" name="hide_salary_apply_superadmin" value="0">
      <input type="checkbox" name="hide_salary_apply_superadmin" value="1"<?= $hideSuper ? ' checked' : '' ?>>
      <?= te('payments.settings.hide_salary_apply_superadmin') ?>
    </label>
    <p class="muted"><?= te('payments.settings.hide_salary_apply_superadmin_hint') ?></p>
  </fieldset>

  <?php if ($contractsOn || $audioContractsOn): ?>
  <fieldset class="form-fieldset">
    <legend><?= te('payments.settings.sec.contracts') ?></legend>
    <?php if ($contractsOn): ?>
      <p class="muted"><?= te('contracts.settings.deadline_penalty_hint') ?></p>
      <label class="logo-remove">
        <input type="hidden" name="deadline_penalty" value="0">
        <input type="checkbox" name="deadline_penalty" value="1"<?= $deadlinePenalty ? ' checked' : '' ?>>
        <?= te('contracts.settings.deadline_penalty_label') ?>
      </label>
      <label class="logo-remove">
        <input type="hidden" name="auto_pay_on_work_accept" value="0">
        <input type="checkbox" name="auto_pay_on_work_accept" value="1"<?= $autoPayWork ? ' checked' : '' ?>>
        <?= te('payments.settings.auto_pay_on_work_accept') ?>
      </label>
      <p class="muted"><?= te('payments.settings.auto_pay_on_work_accept_hint') ?></p>
    <?php endif; ?>
    <?php if ($contractsOn || $audioContractsOn): ?>
      <label class="logo-remove">
        <input type="hidden" name="auto_pay_on_audio_contract" value="0">
        <input type="checkbox" name="auto_pay_on_audio_contract" value="1"<?= $autoPayAudio ? ' checked' : '' ?>>
        <?= te('payments.settings.auto_pay_on_audio_contract') ?>
      </label>
      <p class="muted"><?= te('payments.settings.auto_pay_on_audio_contract_hint') ?></p>
      <?php if (!$audioContractsOn): ?>
        <p class="muted"><?= te('payments.settings.auto_pay_audio_modules_off') ?></p>
      <?php endif; ?>
    <?php endif; ?>
  </fieldset>
  <?php endif; ?>

  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/payments"><?= te('core.common.cancel') ?></a>
  </div>
</form>
