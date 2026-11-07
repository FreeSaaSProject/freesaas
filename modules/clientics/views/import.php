<?php
declare(strict_types=1);
/** @var string $message */
/** @var string $error */
/** @var array<string,mixed>|null $report */
/** @var array<string,mixed>|null $last */
/** @var array<string,mixed>|null $last_appointments */
/** @var string $from */
/** @var string $to */
/** @var bool $api_ready */
/** @var bool $pull_enabled */
/** @var bool $push_enabled */
/** @var string $appointment_sot */
/** @var bool $waiting_list_available */
/** @var bool $medical_cards_available */
$pullDisabled = (!$pull_enabled || !$api_ready) ? ' disabled' : '';
?>
<section class="page-head">
  <h1><?= te('clientics.import.title') ?></h1>
  <p class="muted"><?= te('clientics.import.lede') ?></p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<?php if (!$api_ready): ?>
  <div class="flash flash-warn">
    <?= te('clientics.status.missing') ?>
    — <a href="/clientics/settings"><?= te('clientics.menu.settings') ?></a>
  </div>
<?php elseif (!$pull_enabled): ?>
  <div class="flash flash-warn">
    <?= te('clientics.status.off') ?>
    — <a href="/clientics/settings"><?= te('clientics.menu.settings') ?></a>
  </div>
<?php endif; ?>

<section>
  <h2><?= te('clientics.import.section_range') ?></h2>
  <form method="post" class="form-grid" data-clientics-import="clients">
    <label><?= te('clientics.import.from') ?>
      <input type="date" name="from" value="<?= e($from) ?>" required>
    </label>
    <label><?= te('clientics.import.to') ?>
      <input type="date" name="to" value="<?= e($to) ?>" required>
    </label>
    <label class="check"><input type="checkbox" name="dry_run" value="1" checked> <?= te('clientics.import.dry_run') ?></label>
    <div class="page-actions">
      <button class="btn-primary" type="submit"<?= $pullDisabled ?>><?= te('clientics.import.clients') ?></button>
    </div>
  </form>
  <form method="post" class="form-grid" style="margin-top:1rem" data-clientics-import="appointments">
    <label><?= te('clientics.import.from') ?>
      <input type="date" name="from" value="<?= e($from) ?>" required>
    </label>
    <label><?= te('clientics.import.to') ?>
      <input type="date" name="to" value="<?= e($to) ?>" required>
    </label>
    <label class="check"><input type="checkbox" name="dry_run" value="1" checked> <?= te('clientics.import.dry_run') ?></label>
    <div class="page-actions">
      <button class="btn-secondary" type="submit"<?= $pullDisabled ?>><?= te('clientics.import.appointments') ?></button>
    </div>
  </form>
</section>

<section>
  <h2><?= te('clientics.import.section_refs') ?></h2>
  <form method="post" class="form-grid" data-clientics-import="users">
    <label class="check"><input type="checkbox" name="dry_run" value="1" checked> <?= te('clientics.import.dry_run') ?></label>
    <div class="page-actions">
      <button class="btn-secondary" type="submit"<?= $pullDisabled ?>><?= te('clientics.import.users') ?></button>
    </div>
  </form>
  <form method="post" class="form-grid" style="margin-top:1rem" data-clientics-import="services">
    <label class="check"><input type="checkbox" name="dry_run" value="1" checked> <?= te('clientics.import.dry_run') ?></label>
    <div class="page-actions">
      <button class="btn-secondary" type="submit"<?= $pullDisabled ?>><?= te('clientics.import.services') ?></button>
    </div>
  </form>
  <form method="post" class="form-grid" style="margin-top:1rem" data-clientics-import="inventory">
    <div class="page-actions">
      <button class="btn-secondary" type="submit"<?= !$api_ready ? ' disabled' : '' ?>><?= te('clientics.import.inventory') ?></button>
    </div>
  </form>
  <?php if (!empty($waiting_list_available)): ?>
  <form method="post" class="form-grid" style="margin-top:1rem" data-clientics-import="waiting_list">
    <label class="check"><input type="checkbox" name="dry_run" value="1" checked> <?= te('clientics.import.dry_run') ?></label>
    <div class="page-actions">
      <button class="btn-secondary" type="submit"<?= $pullDisabled ?>><?= te('clientics.import.waiting_list') ?></button>
    </div>
  </form>
  <?php endif; ?>
  <?php if (!empty($push_enabled)): ?>
  <form method="post" class="form-grid" style="margin-top:1rem" data-clientics-import="outbox">
    <div class="page-actions">
      <button class="btn-secondary" type="submit"<?= !$api_ready ? ' disabled' : '' ?>><?= te('clientics.import.outbox') ?></button>
    </div>
  </form>
  <?php endif; ?>
  <?php if (empty($medical_cards_available)): ?>
    <p class="muted"><?= te('clientics.import.medcards_skip') ?></p>
  <?php endif; ?>
</section>

<?= View::capture('_longjob_panel', [
  'longjobId' => 'clientics-import',
  'longjobTitle' => t('clientics.import.title'),
  'longjobDualBars' => false,
  'longjobShowBytes' => false,
], 'core') ?>
<script>
(function () {
  var kindTitles = {
    clients: <?= json_encode(t('clientics.import.clients'), JSON_UNESCAPED_UNICODE) ?>,
    appointments: <?= json_encode(t('clientics.import.appointments'), JSON_UNESCAPED_UNICODE) ?>,
    users: <?= json_encode(t('clientics.import.users'), JSON_UNESCAPED_UNICODE) ?>,
    services: <?= json_encode(t('clientics.import.services'), JSON_UNESCAPED_UNICODE) ?>,
    waiting_list: <?= json_encode(t('clientics.import.waiting_list'), JSON_UNESCAPED_UNICODE) ?>,
    inventory: <?= json_encode(t('clientics.import.inventory'), JSON_UNESCAPED_UNICODE) ?>,
    outbox: <?= json_encode(t('clientics.import.outbox'), JSON_UNESCAPED_UNICODE) ?>
  };
  function boot() {
    var overlay = document.getElementById('clientics-import-overlay');
    if (!overlay) return;
    if (!window.LongJobPanel) { setTimeout(boot, 30); return; }
    if (overlay.getAttribute('data-bound') === '1') return;
    overlay.setAttribute('data-bound', '1');
    var panel = LongJobPanel.mount({
      root: overlay,
      startUrl: '/clientics/import/longjob',
      tickUrl: '/clientics/import/longjob',
      cancelUrl: '/clientics/import/longjob',
      dualBars: false,
      showBytes: false,
      reloadOnOk: true,
      csrf: <?= json_encode(class_exists('Csrf', false) ? Csrf::token() : '', JSON_UNESCAPED_UNICODE) ?>,
      i18n: {
        title: <?= json_encode(t('clientics.import.title'), JSON_UNESCAPED_UNICODE) ?>,
        starting: <?= json_encode(t('core.longjob.starting'), JSON_UNESCAPED_UNICODE) ?>,
        badJson: <?= json_encode(t('core.longjob.bad_json'), JSON_UNESCAPED_UNICODE) ?>,
        doneTitle: <?= json_encode(t('core.longjob.done_title'), JSON_UNESCAPED_UNICODE) ?>,
        errorTitle: <?= json_encode(t('core.longjob.error_title'), JSON_UNESCAPED_UNICODE) ?>,
        cancelledTitle: <?= json_encode(t('core.longjob.cancelled_title'), JSON_UNESCAPED_UNICODE) ?>,
        ok: <?= json_encode(t('core.longjob.ok'), JSON_UNESCAPED_UNICODE) ?>
      }
    });
    document.querySelectorAll('[data-clientics-import]').forEach(function (form) {
      form.addEventListener('submit', function (ev) {
        var btn = form.querySelector('button[type="submit"]');
        if (btn && btn.disabled) return;
        ev.preventDefault();
        var kind = form.getAttribute('data-clientics-import') || 'clients';
        var fd = new FormData(form);
        fd.set('kind', kind);
        panel.runFormData(fd, { title: kindTitles[kind] || kind });
      });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
</script>

<?php if (is_array($report)): ?>
<section>
  <h2><?= te('clientics.import.report') ?></h2>
  <pre class="code-block"><?= e(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '') ?></pre>
</section>
<?php endif; ?>

<section>
  <h2><?= te('clientics.import.last') ?></h2>
  <?php if (!is_array($last)): ?>
    <p class="muted"><?= te('clientics.status.none') ?></p>
  <?php else: ?>
    <pre class="code-block"><?= e(json_encode($last, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '') ?></pre>
  <?php endif; ?>
</section>

<section>
  <h2><?= te('clientics.import.last_appointments') ?></h2>
  <?php if (!is_array($last_appointments)): ?>
    <p class="muted"><?= te('clientics.status.none') ?></p>
  <?php else: ?>
    <pre class="code-block"><?= e(json_encode($last_appointments, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '') ?></pre>
  <?php endif; ?>
</section>
