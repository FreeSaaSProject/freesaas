<?php
/** @var string $message */
/** @var string $error */
/** @var bool $updateConfigured */
/** @var list<array<string,mixed>> $updateSources */
/** @var bool $updateCanApply */
/** @var string|null $updateLastCheck */
/** @var array<string,mixed>|null $updateSource */
/** @var int $updateAvailableCount */
/** @var list<array<string,mixed>> $updateAvailable */
/** @var int $installableCount */
$message = (string)($message ?? '');
$error = (string)($error ?? '');
$updateConfigured = !empty($updateConfigured);
$updateSources = is_array($updateSources ?? null) ? $updateSources : [];
$updateCanApply = !empty($updateCanApply);
$updateLastCheck = isset($updateLastCheck) && is_string($updateLastCheck) && $updateLastCheck !== ''
    ? $updateLastCheck
    : null;
$updateSource = is_array($updateSource ?? null) ? $updateSource : null;
$updateAvailableCount = (int)($updateAvailableCount ?? 0);
$updateAvailable = is_array($updateAvailable ?? null) ? $updateAvailable : [];
$installableCount = (int)($installableCount ?? 0);

$lastCheckTs = null;
if ($updateLastCheck !== null) {
    $parsed = strtotime($updateLastCheck);
    if ($parsed !== false) {
        $lastCheckTs = $parsed;
    }
}
$formatCheckWhen = static function (?int $ts): string {
    if ($ts === null || $ts <= 0) {
        return '';
    }
    // Today → time only; older day → date without time.
    if (date('Y-m-d', $ts) === date('Y-m-d')) {
        return date('H:i', $ts);
    }
    return I18n::formatDate($ts, 'date');
};
$lastCheckWhen = $formatCheckWhen($lastCheckTs);
$lastCheckLabel = $lastCheckWhen !== ''
    ? t('updates.last_check', ['when' => $lastCheckWhen])
    : t('updates.last_check_never');
$statusCurrentLabel = $lastCheckWhen !== ''
    ? t('updates.status_current_at', ['when' => $lastCheckWhen])
    : t('updates.status_current');

$defaultRepo = is_array($updateSource) ? (string)($updateSource['repo'] ?? '') : '';
$defaultChannel = is_array($updateSource) ? (string)($updateSource['channel'] ?? '') : '';
$sourceLine = '';
if ($defaultRepo !== '') {
    $sourceLine = $defaultRepo;
    if ($defaultChannel !== '') {
        $sourceLine .= ' · ' . $defaultChannel;
    }
}

// status()/check rows are authoritative (do not overlay stale session).
$rows = [];
foreach ($updateSources as $src) {
    if (is_array($src)) {
        $rows[] = $src;
    }
}
usort($rows, static function (array $a, array $b): int {
    $aa = !empty($a['update_available']) ? 0 : 1;
    $bb = !empty($b['update_available']) ? 0 : 1;
    if ($aa !== $bb) {
        return $aa <=> $bb;
    }
    $ca = ((string)($a['id'] ?? '') === 'core') ? 0 : 1;
    $cb = ((string)($b['id'] ?? '') === 'core') ? 0 : 1;
    if ($ca !== $cb) {
        return $ca <=> $cb;
    }
    return strcasecmp((string)($a['label'] ?? $a['id'] ?? ''), (string)($b['label'] ?? $b['id'] ?? ''));
});
if ($updateAvailable === []) {
    foreach ($rows as $r) {
        if (!empty($r['update_available'])) {
            $updateAvailable[] = [
                'id' => (string)($r['id'] ?? ''),
                'label' => (string)($r['label'] ?? ''),
                'local_version' => (string)($r['local_version'] ?? ''),
                'remote_version' => (string)($r['remote_version'] ?? ''),
            ];
        }
    }
    $updateAvailableCount = count($updateAvailable);
}

$jobI18n = [
    'title' => t('updates.job.title'),
    'checkTitle' => t('updates.check.title'),
    'doneTitle' => t('updates.job.done_title'),
    'checkDoneTitle' => t('updates.check.done_title'),
    'cancelledTitle' => t('updates.job.cancelled_title'),
    'errorTitle' => t('updates.job.error_title'),
    'confirmApply' => t('updates.confirm_apply'),
    'badJson' => t('updates.job.bad_json'),
    'foundTitle' => t('updates.check.found_title'),
    'foundEmpty' => t('updates.check.found_empty'),
    'foundLine' => t('updates.check.found_line'),
    'applyFound' => t('updates.btn_update_found'),
];
?>
<div id="updates-app"
     data-can-apply="<?= $updateCanApply ? '1' : '0' ?>"
     data-i18n="<?= e((string)json_encode($jobI18n, JSON_UNESCAPED_UNICODE)) ?>">
<section class="page-head">
  <p class="lede"><?= te('updates.lede') ?></p>
  <div class="actions">
    <?php if ($updateConfigured): ?>
    <button type="button" class="btn-primary upd-btn-check-all" data-upd-check="all"><?= te('updates.btn_check_all') ?></button>
    <?php endif; ?>
  </div>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<?php if ($updateAvailableCount > 0): ?>
<div class="flash flash-warn upd-banner" role="status">
  <div class="upd-banner-text">
    <div><?= e(t('updates.banner.available', ['n' => (string)$updateAvailableCount])) ?></div>
    <ul class="upd-banner-list">
      <?php foreach ($updateAvailable as $av):
          $avLabel = (string)($av['label'] ?? $av['id'] ?? '');
          $avLocal = (string)($av['local_version'] ?? '');
          $avRemote = (string)($av['remote_version'] ?? '');
          ?>
      <li><?= e(t('updates.banner.item', [
          'name' => $avLabel,
          'local' => $avLocal,
          'remote' => $avRemote,
      ])) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php if ($updateCanApply): ?>
  <button type="button" class="btn-primary" data-upd-apply="all"><?= te('updates.btn_update_available') ?></button>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($installableCount > 0): ?>
<div class="flash flash-ok upd-banner" role="status">
  <div class="upd-banner-text">
    <?= e(t('updates.banner.installable', ['n' => (string)$installableCount])) ?>
  </div>
  <a class="btn-secondary" href="/modules?status=available"><?= te('updates.banner.installable_link') ?></a>
</div>
<?php endif; ?>

<section class="calendar-section">
  <h2 class="subhead"><?= te('updates.section_configured') ?></h2>
  <p class="muted"><?= e($lastCheckLabel) ?></p>
  <?php if ($sourceLine !== ''): ?>
  <p class="upd-source muted"><?= te('updates.source_line') ?>: <code><?= e($sourceLine) ?></code></p>
  <?php endif; ?>
  <?php if (!$updateConfigured): ?>
    <p class="muted"><?= te('updates.missing_ini') ?></p>
    <p><a class="btn-primary" href="/updates/settings"><?= te('updates.menu.settings') ?></a></p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th><?= te('updates.col_name') ?></th>
            <th><?= te('updates.col_version') ?></th>
            <th><?= te('updates.col_status') ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $src):
            $sid = (string)($src['id'] ?? '');
            $label = (string)($src['label'] ?? UpdateEngine::packageLabel($sid));
            $localVer = (string)($src['local_version'] ?? '');
            $remoteVer = (string)($src['remote_version'] ?? '');
            $avail = !empty($src['update_available']);
            $checked = $remoteVer !== '';
            $remoteMissing = !empty($src['remote_missing']);
            $checkError = trim((string)($src['check_error'] ?? ''));
            $rowRepo = (string)($src['repo'] ?? '');
            $rowBundle = (string)($src['bundle'] ?? '');
            $showSrc = ($rowRepo !== '' && $defaultRepo !== '' && $rowRepo !== $defaultRepo)
                || ($rowBundle === 'external');
            if ($remoteMissing || ($checkError !== '' && !$checked)) {
                $stHtml = '<span class="upd-status upd-status--unk" title="' . e($checkError) . '">'
                    . e(t($remoteMissing ? 'updates.status_local_only' : 'updates.status_check_error'))
                    . '</span>';
            } elseif ($checked && $avail) {
                $stHtml = '<span class="upd-status upd-status--avail">' . e(t('updates.status_available')) . '</span>';
            } elseif ($checked) {
                $stHtml = '<span class="upd-status upd-status--ok"><span class="upd-check" aria-hidden="true">✓</span> '
                    . e($statusCurrentLabel) . '</span>';
            } else {
                $stHtml = '<span class="upd-status upd-status--unk">' . e(t('updates.status_unknown')) . '</span>';
            }
            ?>
          <tr class="<?= $avail ? 'upd-row-avail' : '' ?>">
            <td>
              <strong><?= e($label) ?></strong>
              <span class="muted"> <code><?= e($sid) ?></code></span>
              <?php if ($showSrc): ?>
                <div class="muted upd-row-src"><code><?= e($rowRepo !== '' ? $rowRepo : '—') ?></code></div>
              <?php endif; ?>
            </td>
            <td class="upd-ver">
              <?= e($localVer !== '' ? $localVer : '—') ?>
              <?php if ($checked && $remoteVer !== $localVer): ?>
                <span class="upd-ver-remote<?= $avail ? ' upd-ver-remote--newer' : ' upd-ver-remote--older' ?>"
                      title="<?= e(t('updates.col_remote')) ?>"><?= e($remoteVer) ?></span>
              <?php endif; ?>
            </td>
            <td>
              <div class="upd-status-cell">
                <?= $stHtml ?>
                <button type="button" class="btn-secondary upd-btn-check-one" data-upd-check="<?= e($sid) ?>"><?= te('updates.btn_check_one') ?></button>
              </div>
            </td>
            <td>
              <?php if ($updateCanApply && $avail): ?>
              <button type="button" class="btn-primary" data-upd-apply="<?= e($sid) ?>"><?= te('updates.btn_update') ?></button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($updateCanApply && $updateAvailableCount > 0): ?>
    <div class="actions" style="margin-top:.75rem">
      <button type="button" class="btn-primary" data-upd-apply="all"><?= te('updates.btn_update_available') ?></button>
    </div>
    <?php endif; ?>
  <?php endif; ?>
  <p class="upd-settings-foot muted" style="margin-top:1.25rem">
    <a href="/updates/settings"><?= te('updates.menu.settings') ?></a>
  </p>
</section>

<div id="upd-job-overlay" class="upd-job-overlay" hidden>
  <div class="upd-job-panel" role="dialog" aria-modal="true" aria-labelledby="upd-job-title">
    <h3 id="upd-job-title" class="subhead"><?= te('updates.job.title') ?></h3>
    <p id="upd-job-msg" class="upd-job-msg muted"></p>
    <div class="upd-job-meta">
      <span id="upd-job-pct">0%</span>
      <span id="upd-job-meta" class="muted">0:00</span>
    </div>
    <div class="upd-job-bar" id="upd-job-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
      <div id="upd-job-fill" class="upd-job-fill"></div>
    </div>
    <div id="upd-job-found" class="upd-job-found" hidden>
      <div id="upd-job-found-title" class="upd-job-found-title muted"></div>
      <ul id="upd-job-found-list" class="upd-job-found-list"></ul>
    </div>
    <div class="upd-job-actions actions">
      <button type="button" class="btn-secondary" id="upd-job-abort"><?= te('updates.job.abort') ?></button>
      <?php if ($updateCanApply): ?>
      <button type="button" class="btn-primary" id="upd-job-apply-found" hidden disabled><?= te('updates.btn_update_found') ?></button>
      <?php endif; ?>
      <button type="button" class="btn-secondary" id="upd-job-ok" hidden disabled><?= te('updates.job.ok') ?></button>
    </div>
  </div>
</div>
</div>
