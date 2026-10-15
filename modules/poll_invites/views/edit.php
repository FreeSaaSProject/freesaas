<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var array<int,string> $pollNames */
/** @var array<int,string> $staff */
/** @var string $message */
/** @var string $error */
$id = (int)($row['id'] ?? 0);
$chk = static function (string $key) use ($row): string {
    return !empty($row[$key]) ? ' checked' : '';
};
?>
<section class="page-head">
  <div class="page-head-row">
    <h1><?= $isNew ? te('poll_invites.edit.heading_new') : e('#' . $id) ?></h1>
    <?php if (!$isNew): ?>
    <div class="page-actions">
      <a class="btn-secondary" href="/poll-invites/<?= $id ?>/fill"><?= te('poll_invites.action.fill') ?></a>
    </div>
    <?php endif; ?>
  </div>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid form-wide">
  <fieldset>
    <legend><?= te('poll_invites.edit.section_main') ?></legend>
    <label><?= te('poll_invites.field.poll') ?>
      <select name="id_poll" required>
        <option value="0">—</option>
        <?php foreach ($pollNames as $pid => $pname): ?>
          <option value="<?= (int)$pid ?>"<?= (int)($row['id_poll'] ?? 0) === (int)$pid ? ' selected' : '' ?>><?= e($pname) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= te('poll_invites.field.client') ?>
      <input type="number" name="id_user" min="0" value="<?= e((string)($row['id_user'] ?? 0)) ?>">
    </label>
    <label><?= te('poll_invites.field.operator_linked') ?>
      <select name="id_operator_linked">
        <option value="0">—</option>
        <?php foreach ($staff as $sid => $sname): ?>
          <option value="<?= (int)$sid ?>"<?= (int)($row['id_operator_linked'] ?? 0) === (int)$sid ? ' selected' : '' ?>><?= e($sname) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= te('poll_invites.field.event') ?>
      <input type="number" name="id_event" min="0" value="<?= e((string)($row['id_event'] ?? 0)) ?>">
    </label>
    <label><?= te('poll_invites.field.proc') ?>
      <input type="number" name="id_proc" min="0" value="<?= e((string)($row['id_proc'] ?? 0)) ?>">
    </label>
    <label><?= te('poll_invites.field.text_admin') ?>
      <textarea name="text_admin_to_user" rows="2"><?= e((string)($row['text_admin_to_user'] ?? '')) ?></textarea>
    </label>
    <?php if (!$isNew): ?>
    <label><?= te('poll_invites.field.code') ?>
      <input value="<?= e((string)($row['code'] ?? '')) ?>" disabled>
    </label>
    <?php endif; ?>
  </fieldset>

  <fieldset>
    <legend><?= te('poll_invites.edit.section_dates') ?></legend>
    <label><?= te('poll_invites.field.dt_avail') ?>
      <input type="datetime-local" name="dt_avail" value="<?= e(PollInvitesRepository::dtLocal((int)($row['dt_avail'] ?? 0))) ?>">
    </label>
    <label><?= te('poll_invites.field.dt_expire') ?>
      <input type="datetime-local" name="dt_expire" value="<?= e(PollInvitesRepository::dtLocal((int)($row['dt_expire'] ?? 0))) ?>">
    </label>
    <label><?= te('poll_invites.field.dt_nextcall') ?>
      <input type="datetime-local" name="dt_nextcall" value="<?= e(PollInvitesRepository::dtLocal((int)($row['dt_nextcall'] ?? 0))) ?>">
    </label>
    <label><?= te('poll_invites.field.status') ?>
      <input type="number" name="status" min="0" value="<?= e((string)($row['status'] ?? 0)) ?>">
    </label>
  </fieldset>

  <fieldset>
    <legend><?= te('poll_invites.edit.section_bonus') ?></legend>
    <label class="check">
      <input type="checkbox" name="bonus_auto" value="1"<?= $chk('bonus_auto') ?>>
      <?= te('poll_invites.field.bonus_auto') ?>
    </label>
    <label><?= te('poll_invites.field.bonus_value') ?>
      <input type="number" name="bonus_value" min="0" value="<?= e((string)($row['bonus_value'] ?? 0)) ?>">
    </label>
    <label><?= te('poll_invites.field.bonus_given') ?>
      <input type="number" name="bonus_given" min="0" value="<?= e((string)($row['bonus_given'] ?? 0)) ?>">
    </label>
  </fieldset>

  <fieldset>
    <legend><?= te('poll_invites.edit.section_extra') ?></legend>
    <label class="check">
      <input type="checkbox" name="sign_req" value="1"<?= $chk('sign_req') ?>>
      <?= te('poll_invites.field.sign_req') ?>
    </label>
    <label class="check">
      <input type="checkbox" name="is_special" value="1"<?= $chk('is_special') ?>>
      <?= te('poll_invites.field.is_special') ?>
    </label>
    <label class="check">
      <input type="checkbox" name="is_special_friend" value="1"<?= $chk('is_special_friend') ?>>
      <?= te('poll_invites.field.is_special_friend') ?>
    </label>
    <label><?= te('poll_invites.field.log') ?>
      <textarea name="log" rows="4"><?= e((string)($row['log'] ?? '')) ?></textarea>
    </label>
  </fieldset>

  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/poll-invites"><?= te('core.common.cancel') ?></a>
  </div>
</form>

<?php if (empty($isNew) && empty($is_new)): ?>
<?= EntityDelete::form('poll_invites', (int)($row['id'] ?? 0)) ?>
<?php endif; ?>

