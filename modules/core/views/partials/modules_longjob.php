<?php
declare(strict_types=1);
/** Shared LongJobPanel for schema migrate + module uninstall on /modules. */
?>
<?= View::capture('_longjob_panel', [
  'longjobId' => 'core-modules-job',
  'longjobTitle' => t('core.longjob.title'),
  'longjobDualBars' => false,
  'longjobShowBytes' => false,
], 'core') ?>
<script>
(function () {
  function boot() {
    var overlay = document.getElementById('core-modules-job-overlay');
    if (!overlay) return;
    if (!window.LongJobPanel) { setTimeout(boot, 30); return; }
    if (overlay.getAttribute('data-bound') === '1') return;
    overlay.setAttribute('data-bound', '1');
    var csrf = <?= json_encode(class_exists('Csrf', false) ? Csrf::token() : '', JSON_UNESCAPED_UNICODE) ?>;
    var schemaUrl = '/db/schema/longjob';
    var uninstallUrl = '/modules/uninstall/longjob';
    var opts = {
      root: overlay,
      startUrl: schemaUrl,
      tickUrl: schemaUrl,
      cancelUrl: schemaUrl,
      dualBars: false,
      showBytes: false,
      reloadOnOk: true,
      csrf: csrf,
      i18n: {
        starting: <?= json_encode(t('core.longjob.starting'), JSON_UNESCAPED_UNICODE) ?>,
        badJson: <?= json_encode(t('core.longjob.bad_json'), JSON_UNESCAPED_UNICODE) ?>,
        doneTitle: <?= json_encode(t('core.longjob.done_title'), JSON_UNESCAPED_UNICODE) ?>,
        errorTitle: <?= json_encode(t('core.longjob.error_title'), JSON_UNESCAPED_UNICODE) ?>,
        cancelledTitle: <?= json_encode(t('core.longjob.cancelled_title'), JSON_UNESCAPED_UNICODE) ?>,
        ok: <?= json_encode(t('core.longjob.ok'), JSON_UNESCAPED_UNICODE) ?>
      }
    };
    var panel = LongJobPanel.mount(opts);
    function setUrls(url) {
      opts.startUrl = url;
      opts.tickUrl = url;
      opts.cancelUrl = url;
    }
    document.querySelectorAll('[data-schema-module-longjob]').forEach(function (form) {
      form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var mid = form.getAttribute('data-schema-module-longjob') || '';
        var title = <?= json_encode(t('core.modules.btn_migrate'), JSON_UNESCAPED_UNICODE) ?> + (mid ? (' — ' + mid) : '');
        setUrls(schemaUrl);
        var fd = new FormData();
        fd.set('module_id', mid);
        panel.runFormData(fd, { title: title });
      });
    });
    document.querySelectorAll('[data-uninstall-longjob]').forEach(function (form) {
      form.addEventListener('submit', function (ev) {
        var conf = form.getAttribute('data-uninstall-confirm') || '';
        if (conf && !window.confirm(conf)) {
          ev.preventDefault();
          return;
        }
        ev.preventDefault();
        var mid = form.getAttribute('data-uninstall-longjob') || '';
        var title = <?= json_encode(t('core.modules.btn_uninstall'), JSON_UNESCAPED_UNICODE) ?> + (mid ? (' — ' + mid) : '');
        setUrls(uninstallUrl);
        var fd = new FormData(form);
        fd.set('module_id', mid);
        panel.runFormData(fd, { title: title });
      });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
</script>
