<?php
/** @var string $message */
/** @var string $error */
/** @var bool $enabled */
/** @var string $clientId */
/** @var bool $clientSecretSet */
/** @var string $redirectUri */
/** @var string $defaultRedirect */
/** @var string $workdayStart */
/** @var string $workdayEnd */
/** @var list<array{ID:int,NAME:string,connected:bool,active_cal:string,done_cal:string}> $staff */
/** @var array<int,list<array{id:string,summary:string,primary:bool}>> $calendarsByUser */
/** @var int $pending */
?>
<section class="page-head">
  <h1><?= e($title) ?></h1>
</section>

<?php if ($message !== ''): ?><p class="flash ok"><?= e($message) ?></p><?php endif; ?>
<?php if ($error !== ''): ?><p class="flash error"><?= e($error) ?></p><?php endif; ?>

<form method="post" class="form-card">
  <input type="hidden" name="action" value="save">
  <label class="check-row">
    <input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
    <?= te('gcal.settings.enabled') ?>
  </label>
  <label>
    <?= te('gcal.settings.client_id') ?>
    <input type="text" name="client_id" value="<?= e($clientId) ?>" autocomplete="off">
  </label>
  <label>
    <?= te('gcal.settings.client_secret') ?>
    <input type="password" name="client_secret" value="" placeholder="<?= $clientSecretSet ? '••••••••' : '' ?>" autocomplete="new-password">
  </label>
  <label>
    <?= te('gcal.settings.redirect_uri') ?>
    <input type="text" name="redirect_uri" value="<?= e($redirectUri) ?>" placeholder="<?= e($defaultRedirect) ?>">
    <span class="muted"><?= e(t('gcal.settings.redirect_hint', ['url' => $defaultRedirect])) ?></span>
  </label>
  <div class="form-row">
    <label>
      <?= te('gcal.settings.workday_start') ?>
      <input type="text" name="workday_start" value="<?= e($workdayStart) ?>" pattern="\d{1,2}:\d{2}">
    </label>
    <label>
      <?= te('gcal.settings.workday_end') ?>
      <input type="text" name="workday_end" value="<?= e($workdayEnd) ?>" pattern="\d{1,2}:\d{2}">
    </label>
  </div>
  <button type="submit" class="btn"><?= te('core.common.save') ?></button>
</form>

<section class="form-card">
  <h2><?= te('gcal.settings.queue') ?></h2>
  <p><?= e(t('gcal.settings.queue_pending', ['n' => (string)$pending])) ?></p>
  <form method="post" class="inline-form">
    <input type="hidden" name="action" value="run_worker">
    <button type="submit" class="btn-secondary"><?= te('gcal.settings.run_worker') ?></button>
  </form>
</section>

<section class="form-card">
  <h2><?= te('gcal.settings.staff') ?></h2>
  <table class="data-table">
    <thead>
      <tr>
        <th><?= te('core.users.col_name') ?></th>
        <th></th>
        <th><?= te('gcal.settings.active_cal') ?></th>
        <th><?= te('gcal.settings.done_cal') ?></th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($staff as $s): ?>
      <?php
        $sid = (int)$s['ID'];
        $cals = $calendarsByUser[$sid] ?? [];
      ?>
      <tr>
        <td><?= e($s['NAME']) ?></td>
        <td>
          <?php if ($s['connected']): ?>
            <span class="muted"><?= te('gcal.settings.connected') ?></span>
            <a class="btn-secondary" href="/gcal/oauth/start?user_id=<?= $sid ?>"><?= te('gcal.settings.reconnect') ?></a>
          <?php else: ?>
            <span class="muted"><?= te('gcal.settings.not_connected') ?></span>
            <a class="btn-secondary" href="/gcal/oauth/start?user_id=<?= $sid ?>"><?= te('gcal.settings.connect') ?></a>
          <?php endif; ?>
        </td>
        <?php if ($s['connected'] && $cals !== []): ?>
          <td colspan="3">
            <form method="post" class="inline-form form-row">
              <input type="hidden" name="action" value="save_cals">
              <input type="hidden" name="user_id" value="<?= $sid ?>">
              <select name="active_calendar_id">
                <?php foreach ($cals as $c): ?>
                  <option value="<?= e($c['id']) ?>" <?= $s['active_cal'] === $c['id'] ? 'selected' : '' ?>><?= e($c['summary']) ?></option>
                <?php endforeach; ?>
              </select>
              <select name="done_calendar_id">
                <?php foreach ($cals as $c): ?>
                  <option value="<?= e($c['id']) ?>" <?= $s['done_cal'] === $c['id'] ? 'selected' : '' ?>><?= e($c['summary']) ?></option>
                <?php endforeach; ?>
              </select>
              <button type="submit" class="btn-secondary"><?= te('gcal.settings.save_cals') ?></button>
            </form>
          </td>
        <?php else: ?>
          <td colspan="3" class="muted"><?= e($s['active_cal'] ?: '—') ?></td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
