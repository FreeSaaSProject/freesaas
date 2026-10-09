<?php
/** @var string $message */
/** @var string $error */
/** @var bool $updateConfigured */
/** @var list<array<string,mixed>> $updateSources */
/** @var list<array<string,mixed>> $updateHints */
/** @var array<string,array<string,mixed>> $updateRemoteById */
/** @var bool $updateCanApply */
$message = (string)($message ?? '');
$error = (string)($error ?? '');
$updateConfigured = !empty($updateConfigured);
$updateSources = is_array($updateSources ?? null) ? $updateSources : [];
$updateHints = is_array($updateHints ?? null) ? $updateHints : [];
$updateRemoteById = is_array($updateRemoteById ?? null) ? $updateRemoteById : [];
$updateCanApply = !empty($updateCanApply);
?>
<section class="page-head">
  <p class="lede"><?= te('updates.lede') ?></p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<section class="calendar-section">
  <h2 class="subhead"><?= te('updates.section_configured') ?></h2>
  <?php if (!$updateConfigured): ?>
    <p class="muted"><?= te('updates.missing_ini') ?></p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th><?= te('updates.col_id') ?></th>
            <th><?= te('updates.col_provider') ?></th>
            <th><?= te('updates.col_local') ?></th>
            <th><?= te('updates.col_remote') ?></th>
            <th><?= te('updates.col_status') ?></th>
            <?php if ($updateCanApply): ?><th></th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($updateSources as $src):
            $sid = (string)($src['id'] ?? '');
            $remote = $updateRemoteById[$sid] ?? null;
            $remoteVer = is_array($remote) ? (string)($remote['remote_version'] ?? '') : '';
            $avail = is_array($remote) && !empty($remote['update_available']);
            $checked = is_array($remote);
            if ($checked && $avail) {
                $stLabel = t('updates.status_available');
            } elseif ($checked) {
                $stLabel = t('updates.status_current');
            } else {
                $stLabel = t('updates.status_unknown');
            }
            $prov = (string)($src['provider'] ?? '');
            $repo = (string)($src['repo'] ?? '');
            $provLabel = $prov . ($repo !== '' ? ' · ' . $repo : '');
            $tok = !empty($src['token_set'])
                ? t('updates.token_set')
                : t('updates.token_missing');
            ?>
          <tr>
            <td><code><?= e($sid) ?></code></td>
            <td><?= e($provLabel) ?> <span class="muted">(<?= e($tok) ?>)</span></td>
            <td><?= e((string)($src['local_version'] ?? '')) ?></td>
            <td><?= e($remoteVer !== '' ? $remoteVer : '—') ?></td>
            <td><?= e($stLabel) ?></td>
            <?php if ($updateCanApply): ?>
            <td>
              <form method="post" style="display:inline"
                    onsubmit="return confirm(<?= tj('updates.confirm_apply') ?>);">
                <input type="hidden" name="action" value="update_apply">
                <input type="hidden" name="target" value="<?= e($sid) ?>">
                <button class="btn-secondary" type="submit"><?= te('updates.btn_apply') ?></button>
              </form>
            </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="actions" style="margin-top:.75rem">
      <form method="post" style="display:inline">
        <input type="hidden" name="action" value="update_check">
        <button class="btn-secondary" type="submit"><?= te('updates.btn_check') ?></button>
      </form>
      <?php if ($updateCanApply): ?>
      <form method="post" style="display:inline"
            onsubmit="return confirm(<?= tj('updates.confirm_apply') ?>);">
        <input type="hidden" name="action" value="update_apply">
        <input type="hidden" name="target" value="all">
        <button class="btn-primary" type="submit"><?= te('updates.btn_apply_all') ?></button>
      </form>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</section>

<section class="calendar-section" style="margin-top:1.5rem">
  <h2 class="subhead"><?= te('updates.section_hints') ?></h2>
  <p class="muted"><?= te('updates.hints_help') ?></p>
  <?php if ($updateHints === []): ?>
    <p class="muted"><?= te('updates.hints_empty') ?></p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th><?= te('updates.col_id') ?></th>
            <th><?= te('updates.col_provider') ?></th>
            <th><?= te('updates.col_local') ?></th>
            <th><?= te('updates.col_status') ?></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($updateHints as $hint):
            $hid = (string)($hint['id'] ?? '');
            $repo = (string)($hint['repository'] ?? $hint['repo'] ?? '');
            $channel = (string)($hint['channel'] ?? 'stable');
            ?>
          <tr>
            <td><code><?= e($hid) ?></code></td>
            <td><?= e($repo !== '' ? $repo : '—') ?> <span class="muted">(<?= e($channel) ?>)</span></td>
            <td><?= e((string)($hint['local_version'] ?? '')) ?></td>
            <td><?= te('updates.status_hint') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
