<?php
declare(strict_types=1);
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
$displayRedirect = $redirectUri !== '' ? $redirectUri : $defaultRedirect;
?>
<section class="page-head">
  <h1><?= e($title) ?></h1>
  <p class="lede muted"><?= te('gcal.settings.lede') ?></p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid form-wide">
  <input type="hidden" name="action" value="save">
  <fieldset class="form-fieldset">
    <legend><?= te('gcal.settings.app') ?></legend>
    <label class="check">
      <input type="checkbox" name="enabled" value="1"<?= $enabled ? ' checked' : '' ?>>
      <?= te('gcal.settings.enabled') ?>
    </label>
    <label><?= te('gcal.settings.client_id') ?>
      <input type="text" name="client_id" value="<?= e($clientId) ?>" autocomplete="off" spellcheck="false">
    </label>
    <label><?= te('gcal.settings.client_secret') ?>
      <input type="password" name="client_secret" value="" placeholder="<?= $clientSecretSet ? '••••••••' : '' ?>" autocomplete="new-password">
      <?php if ($clientSecretSet): ?>
        <span class="muted"><?= te('gcal.settings.secret_keep') ?></span>
      <?php endif; ?>
    </label>
    <label><?= te('gcal.settings.redirect_uri') ?>
      <input type="url" name="redirect_uri" value="<?= e($redirectUri) ?>" placeholder="<?= e($defaultRedirect) ?>" spellcheck="false">
      <span class="muted"><?= e(t('gcal.settings.redirect_hint', ['url' => $defaultRedirect])) ?></span>
      <?php if ($displayRedirect !== ''): ?>
        <code class="gcal-redirect-code" onclick="this.select?.()"><?= e($displayRedirect) ?></code>
      <?php endif; ?>
    </label>
    <div class="form-row-inline">
      <label><?= te('gcal.settings.workday_start') ?>
        <input type="text" name="workday_start" value="<?= e($workdayStart) ?>" pattern="\d{1,2}:\d{2}" inputmode="numeric">
      </label>
      <label><?= te('gcal.settings.workday_end') ?>
        <input type="text" name="workday_end" value="<?= e($workdayEnd) ?>" pattern="\d{1,2}:\d{2}" inputmode="numeric">
      </label>
    </div>
  </fieldset>
  <div class="page-actions">
    <button type="submit" class="btn-primary"><?= te('core.common.save') ?></button>
  </div>
</form>

<section class="gcal-queue">
  <h2><?= te('gcal.settings.queue') ?></h2>
  <p class="muted"><?= e(t('gcal.settings.queue_pending', ['n' => (string)$pending])) ?></p>
  <form method="post" class="inline-form">
    <input type="hidden" name="action" value="run_worker">
    <button type="submit" class="btn-secondary"><?= te('gcal.settings.run_worker') ?></button>
  </form>
</section>

<section class="gcal-staff">
  <h2><?= te('gcal.settings.staff') ?></h2>
  <div class="gcal-staff-list">
    <?php foreach ($staff as $s): ?>
      <?php
        $sid = (int)$s['ID'];
        $cals = $calendarsByUser[$sid] ?? [];
        $connected = !empty($s['connected']);
      ?>
      <article class="gcal-staff-card<?= $connected ? ' is-connected' : '' ?>">
        <header class="gcal-staff-head">
          <div>
            <strong><?= e($s['NAME']) ?></strong>
            <span class="muted"><?= $connected ? te('gcal.settings.connected') : te('gcal.settings.not_connected') ?></span>
          </div>
          <a class="btn-secondary" href="/gcal/oauth/start?user_id=<?= $sid ?>">
            <?= $connected ? te('gcal.settings.reconnect') : te('gcal.settings.connect') ?>
          </a>
        </header>
        <?php if ($connected && $cals !== []): ?>
          <form method="post" class="gcal-staff-cals">
            <input type="hidden" name="action" value="save_cals">
            <input type="hidden" name="user_id" value="<?= $sid ?>">
            <label><?= te('gcal.settings.active_cal') ?>
              <select name="active_calendar_id">
                <?php foreach ($cals as $c): ?>
                  <option value="<?= e($c['id']) ?>"<?= $s['active_cal'] === $c['id'] ? ' selected' : '' ?>><?= e($c['summary']) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label><?= te('gcal.settings.done_cal') ?>
              <select name="done_calendar_id">
                <?php foreach ($cals as $c): ?>
                  <option value="<?= e($c['id']) ?>"<?= $s['done_cal'] === $c['id'] ? ' selected' : '' ?>><?= e($c['summary']) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <button type="submit" class="btn-secondary"><?= te('gcal.settings.save_cals') ?></button>
          </form>
        <?php elseif ($connected): ?>
          <p class="muted"><?= e($s['active_cal'] !== '' ? $s['active_cal'] : '—') ?></p>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  </div>
</section>
