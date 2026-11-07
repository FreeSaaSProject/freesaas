<?php
declare(strict_types=1);

/**
 * Staff LongJob: inbox → base_records. Legacy GET opens a blank starter that pumps ticks.
 * Token enqueue: /audio/jobs/ingest; sync: /audio/jobs/ingest/run.
 */
if (!Auth::can('audio.edit')) {
    if (AgentHttp::wantsJson()) {
        AgentHttp::error('Forbidden', 403);
        return;
    }
    http_response_code(403);
    echo 'Forbidden';
    return;
}
if (!ModuleManager::isEnabled('audio_pipeline')) {
    if (AgentHttp::wantsJson()) {
        AgentHttp::error('audio_pipeline disabled', 503);
        return;
    }
    http_response_code(503);
    echo 'audio_pipeline disabled';
    return;
}

require_once base_path('modules/audio/lib/AudioIngestLongJob.php');

$action = trim((string)($_POST['action'] ?? ''));
if (in_array($action, ['job_start', 'job_tick', 'job_cancel'], true)) {
    if (class_exists('Csrf', false) && !Csrf::verify()) {
        Csrf::deny('csrf');
        return;
    }
    try {
        if ($action === 'job_start') {
            $job = LongJob::start(AudioIngestLongJob::TYPE, [
                'import_broken' => !empty($_POST['importBrokenDate']) || !empty($_POST['import_broken']),
                'ret' => (string)($_POST['ret'] ?? '/audio'),
            ], ['context' => 'ui']);
            AgentHttp::emit(['ok' => true, 'job' => LongJob::public($job)]);
        }
        if ($action === 'job_tick') {
            $id = (string)($_POST['job_id'] ?? '');
            $job = LongJob::tick($id, AudioIngestLongJob::TYPE);
            AgentHttp::emit([
                'ok' => ($job['status'] ?? '') !== LongJob::STATUS_ERROR,
                'job' => LongJob::public($job),
            ]);
        }
        if ($action === 'job_cancel') {
            $id = (string)($_POST['job_id'] ?? '');
            $job = LongJob::cancel($id, AudioIngestLongJob::TYPE);
            AgentHttp::emit(['ok' => true, 'job' => LongJob::public($job)]);
        }
    } catch (Throwable $e) {
        AgentHttp::error($e->getMessage(), 400);
    }
    return;
}

// Bookmark / target=_blank: thin page that mounts LongJobPanel.
$retRaw = (string)($_GET['ret'] ?? '/audio');
$ret = NavContext::sanitize($retRaw) ?: '/audio';
$importBroken = !empty($_GET['importBrokenDate']);
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="<?= e(I18n::locale()) ?>">
<head>
  <meta charset="utf-8">
  <title><?= te('audio.inbox.ingest_title') ?></title>
  <link rel="stylesheet" href="/assets/css/core.css">
  <script src="/assets/js/longjob.js?v=<?= (int)@filemtime(base_path('assets/js/longjob.js')) ?>" defer></script>
</head>
<body>
<?= View::capture('_longjob_panel', [
  'longjobId' => 'audio-ingest',
  'longjobTitle' => t('audio.inbox.ingest_title'),
  'longjobDualBars' => false,
  'longjobShowBytes' => false,
], 'core') ?>
<script>
(function () {
  function boot() {
    var overlay = document.getElementById('audio-ingest-overlay');
    if (!overlay || !window.LongJobPanel) {
      setTimeout(boot, 30);
      return;
    }
    var ret = <?= json_encode($ret, JSON_UNESCAPED_UNICODE) ?>;
    var panel = LongJobPanel.mount({
      root: overlay,
      startUrl: '/audio/ingest',
      tickUrl: '/audio/ingest',
      cancelUrl: '/audio/ingest',
      dualBars: false,
      showBytes: false,
      reloadOnOk: false,
      csrf: <?= json_encode(class_exists('Csrf', false) ? Csrf::token() : '', JSON_UNESCAPED_UNICODE) ?>,
      i18n: {
        title: <?= json_encode(t('audio.inbox.ingest_title'), JSON_UNESCAPED_UNICODE) ?>,
        starting: <?= json_encode(t('core.longjob.starting'), JSON_UNESCAPED_UNICODE) ?>,
        badJson: <?= json_encode(t('core.longjob.bad_json'), JSON_UNESCAPED_UNICODE) ?>,
        doneTitle: <?= json_encode(t('core.longjob.done_title'), JSON_UNESCAPED_UNICODE) ?>,
        errorTitle: <?= json_encode(t('core.longjob.error_title'), JSON_UNESCAPED_UNICODE) ?>,
        cancelledTitle: <?= json_encode(t('core.longjob.cancelled_title'), JSON_UNESCAPED_UNICODE) ?>,
        ok: <?= json_encode(t('core.longjob.ok'), JSON_UNESCAPED_UNICODE) ?>
      },
      onOk: function () { window.location.href = ret; }
    });
    var fd = new FormData();
    fd.set('ret', ret);
    <?php if ($importBroken): ?>fd.set('importBrokenDate', '1');<?php endif; ?>
    panel.runFormData(fd, {});
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
</script>
</body>
</html>
