<?php
declare(strict_types=1);
/** @var string $error */
/** @var array<string,mixed>|null $report */
/** @var bool $apiReady */
/** @var bool $pullEnabled */
$disabled = (!$pullEnabled || !$apiReady) ? ' disabled' : '';
?>
<section class="page-head"><h1><?= te('amocrm.menu.import') ?></h1></section>
<?php if (!$pullEnabled): ?><div class="flash flash-warn"><?= te('amocrm.status.pull_off') ?></div><?php endif; ?>
<?php if (!$apiReady): ?><div class="flash flash-warn"><?= te('amocrm.warn.api') ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>
<?php if (is_array($report)): ?>
  <pre class="muted"><?= e(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '') ?></pre>
<?php endif; ?>
<?php foreach (['contact', 'company', 'lead'] as $ent): ?>
<form method="post" class="form-grid" style="margin-bottom:1rem" data-amocrm-import="<?= e($ent) ?>">
  <input type="hidden" name="action" value="<?= e($ent) ?>">
  <label class="check"><input type="checkbox" name="dry_run" value="1" checked> <?= te('amocrm.import.dry_run') ?></label>
  <button class="btn-primary" type="submit"<?= $disabled ?>><?= te('amocrm.import.run') ?>: <?= e($ent) ?></button>
</form>
<?php endforeach; ?>
<p class="muted"><?= te('amocrm.import.hint') ?></p>

<?= View::capture('_longjob_panel', [
  'longjobId' => 'amocrm-import',
  'longjobTitle' => t('amocrm.menu.import'),
  'longjobDualBars' => false,
  'longjobShowBytes' => false,
], 'core') ?>
<script>
(function () {
  function boot() {
    var overlay = document.getElementById('amocrm-import-overlay');
    if (!overlay) return;
    if (!window.LongJobPanel) { setTimeout(boot, 30); return; }
    if (overlay.getAttribute('data-bound') === '1') return;
    overlay.setAttribute('data-bound', '1');
    var panel = LongJobPanel.mount({
      root: overlay,
      startUrl: '/amocrm/import/longjob',
      tickUrl: '/amocrm/import/longjob',
      cancelUrl: '/amocrm/import/longjob',
      dualBars: false,
      showBytes: false,
      reloadOnOk: true,
      csrf: <?= json_encode(class_exists('Csrf', false) ? Csrf::token() : '', JSON_UNESCAPED_UNICODE) ?>,
      i18n: {
        title: <?= json_encode(t('amocrm.menu.import'), JSON_UNESCAPED_UNICODE) ?>,
        starting: <?= json_encode(t('core.longjob.starting'), JSON_UNESCAPED_UNICODE) ?>,
        badJson: <?= json_encode(t('core.longjob.bad_json'), JSON_UNESCAPED_UNICODE) ?>,
        doneTitle: <?= json_encode(t('core.longjob.done_title'), JSON_UNESCAPED_UNICODE) ?>,
        errorTitle: <?= json_encode(t('core.longjob.error_title'), JSON_UNESCAPED_UNICODE) ?>,
        cancelledTitle: <?= json_encode(t('core.longjob.cancelled_title'), JSON_UNESCAPED_UNICODE) ?>,
        ok: <?= json_encode(t('core.longjob.ok'), JSON_UNESCAPED_UNICODE) ?>
      }
    });
    document.querySelectorAll('[data-amocrm-import]').forEach(function (form) {
      form.addEventListener('submit', function (ev) {
        var btn = form.querySelector('button[type="submit"]');
        if (btn && btn.disabled) return;
        ev.preventDefault();
        var entity = form.getAttribute('data-amocrm-import') || 'contact';
        var fd = new FormData(form);
        fd.set('entity', entity);
        fd.delete('action');
        var title = <?= json_encode(t('amocrm.import.run'), JSON_UNESCAPED_UNICODE) ?> + ': ' + entity;
        panel.runFormData(fd, { title: title });
      });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
</script>
