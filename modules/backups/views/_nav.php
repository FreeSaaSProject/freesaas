<?php
declare(strict_types=1);
/**
 * Section tabs for every backups screen + JS i18n root for popups.
 *
 * @var string $backupsTab runs|remote|replica|restore|check|settings
 */
$backupsTab = (string)($backupsTab ?? 'runs');
$isDirector = Auth::capability('SUPERADMIN_ACCESS');
$tabs = [
    'runs' => ['/backups', 'backups.tabs.runs'],
    'remote' => ['/backups?tab=remote', 'backups.tabs.remote'],
];
if ($isDirector) {
    if (BackupConfig::replicaEnabled() || $backupsTab === 'replica') {
        $tabs['replica'] = ['/backups/replica', 'backups.tabs.replica'];
    }
    $tabs['restore'] = ['/backups/restore', 'backups.tabs.restore'];
    $tabs['check'] = ['/backups/check', 'backups.tabs.check'];
    $tabs['settings'] = ['/backups/settings', 'backups.tabs.settings'];
}
$phaseKeys = ['init', 'db', 'cfg', 'files', 'pack', 'encrypt', 'upload', 'verify', 'prune', 'incr_sql', 'done'];
$phaseLabels = [];
foreach ($phaseKeys as $p) {
    $phaseLabels[$p] = t('backups.phase.' . $p);
}
$replicaPhaseKeys = [
    'plan', 'download_baseline', 'prepare_baseline', 'download_cdc',
    'wipe', 'restore', 'apply_cdc', 'finish', 'done',
];
$replicaPhases = [];
foreach ($replicaPhaseKeys as $p) {
    $replicaPhases[$p] = t('backups.replica.phase.' . $p);
}
$uiI18n = [
    'elapsed' => t('backups.ui.elapsed'),
    'busyHint' => t('backups.ui.busy_hint'),
    'runTitle' => [
        'light' => t('backups.ui.run_title.light'),
        'full' => t('backups.ui.run_title.full'),
        'incr' => t('backups.ui.run_title.incr'),
        'continue' => t('backups.ui.run_title.continue'),
    ],
    'phases' => $phaseLabels,
    'replicaPhases' => $replicaPhases,
    'replicaPullTitle' => t('backups.ui.busy.replica_pull'),
    'replicaDoneTitle' => t('backups.ui.replica_done_title'),
    'replicaDoneHint' => t('backups.ui.replica_done_hint'),
    'replicaHideHint' => t('backups.ui.replica_hide_hint'),
    'retrying' => t('backups.ui.retrying'),
    'retry' => t('backups.ui.retry'),
    'login' => t('backups.ui.login'),
    'size' => t('backups.ui.size'),
    'abort' => t('backups.list.cancel_run'),
    'abortConfirm' => t('backups.list.cancel_confirm'),
    'hide' => t('backups.ui.hide'),
    'hideHint' => t('backups.ui.hide_hint'),
    'ok' => t('backups.ui.ok'),
    'doneTitle' => t('backups.ui.done_title'),
    'errorTitle' => t('backups.ui.error_title'),
    'cancelledTitle' => t('backups.ui.cancelled_title'),
    'httpError' => t('backups.ui.http_error'),
    'starting' => t('backups.ui.starting'),
];
?>
<nav class="type-tabs bk-tabs" aria-label="<?= te('backups.tabs.aria') ?>">
  <?php foreach ($tabs as $id => [$href, $label]): ?>
    <a class="type-tab<?= $id === $backupsTab ? ' is-active' : '' ?>" href="<?= e($href) ?>"
       <?= $id === $backupsTab ? 'aria-current="page"' : '' ?>><?= te($label) ?></a>
  <?php endforeach; ?>
</nav>
<div id="bk-ui" hidden data-i18n="<?= e(json_encode($uiI18n, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '{}') ?>"></div>
