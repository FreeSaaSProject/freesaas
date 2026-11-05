<?php
declare(strict_types=1);
/** @var string $message */
/** @var string $error */
/** @var bool $enabled */
/** @var array{ok:bool,error:string} $configured */
/** @var string $instance */
/** @var string $ftp_host */
/** @var array{full:string,diff:string,at:int} $applied */
/** @var bool $standby */
/** @var array<string,mixed>|null $check */
/** @var array<string,mixed>|null $pull */
$check = is_array($check ?? null) ? $check : null;
$pull = is_array($pull ?? null) ? $pull : null;
$pullMode = is_array($check) ? (string)($check['pull_mode'] ?? '') : '';
// Pull CTA only after a successful check, and only when there is something to apply.
$canPull = !empty($enabled)
    && !empty($configured['ok'])
    && empty($standby)
    && is_array($check)
    && !empty($check['ok'])
    && ($pullMode === 'full' || $pullMode === 'incr');
$backupsTab = 'replica';
require __DIR__ . '/_nav.php';
?>
<section class="page-head">
  <p class="lede"><?= te('backups.replica.lede') ?></p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<?php if (!empty($standby)): ?>
  <div class="flash flash-warn"><?= te('backups.replica.err.standby') ?></div>
<?php elseif (empty($enabled)): ?>
  <div class="flash flash-warn"><?= te('backups.replica.disabled_hint') ?></div>
<?php elseif (empty($configured['ok'])): ?>
  <div class="flash flash-warn"><?= e((string)$configured['error']) ?></div>
<?php endif; ?>

<section>
  <h2><?= te('backups.replica.status') ?></h2>
  <p>
    <?= te('backups.replica.source') ?>:
    <code><?= e($ftp_host !== '' ? $ftp_host : '—') ?></code> /
    <code><?= e($instance !== '' ? $instance : '—') ?></code>
  </p>
  <?php if ($applied['full'] !== ''): ?>
    <p>
      <?= te('backups.replica.applied', [
          'full' => $applied['full'],
          'diff' => $applied['diff'] !== '' ? $applied['diff'] : '—',
          'at' => $applied['at'] > 0 ? date('Y-m-d H:i', $applied['at']) : '—',
      ]) ?>
    </p>
  <?php else: ?>
    <p class="muted"><?= te('backups.replica.never') ?></p>
  <?php endif; ?>
  <p class="muted"><a href="/backups/settings"><?= te('backups.replica.settings_link') ?></a></p>
</section>

<div class="page-actions">
  <form method="post" class="inline" data-bk-busy="<?= te('backups.ui.busy.replica_check') ?>">
    <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
    <input type="hidden" name="action" value="check">
    <button class="btn-secondary" type="submit"<?= empty($configured['ok']) ? ' disabled' : '' ?>><?= te('backups.replica.check') ?></button>
  </form>
</div>

<?php if ($check !== null): ?>
<section>
  <h2><?= te('backups.replica.latest') ?></h2>
  <?php $b = is_array($check['baseline'] ?? null) ? $check['baseline'] : null; ?>
  <?php if ($b !== null): ?>
    <p>
      <?= te('backups.replica.baseline') ?>:
      <code><?= e((string)$b['name']) ?></code>
      (<?= e(BackupNotify::formatBytes((int)($b['bytes'] ?? 0))) ?>)
    </p>
  <?php endif; ?>
  <?php $packs = (array)($check['cdc']['packs'] ?? []); ?>
  <?php if ($pullMode === 'noop'): ?>
    <p class="flash flash-ok"><?= te('backups.replica.mode.noop') ?></p>
  <?php elseif ($pullMode === 'incr'): ?>
    <p class="flash flash-ok"><?= te('backups.replica.mode.incr') ?></p>
  <?php elseif ($pullMode === 'full'): ?>
    <p class="flash flash-warn"><?= te('backups.replica.mode.full') ?></p>
  <?php endif; ?>
  <?php if ($packs === []): ?>
    <p class="muted"><?= te('backups.replica.no_cdc') ?></p>
  <?php else: ?>
    <p><?= te('backups.replica.cdc', ['n' => (string)count($packs)]) ?></p>
    <ul class="plain">
      <?php foreach ($packs as $p): ?>
        <li class="mono"><?= e((string)$p['name']) ?> (<?= e(BackupNotify::formatBytes((int)($p['bytes'] ?? 0))) ?>)</li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <?php if (!empty($check['cdc_error'])): ?>
    <div class="flash flash-warn"><?= e((string)$check['cdc_error']) ?></div>
  <?php endif; ?>
  <?php $pp = is_array($check['passphrase'] ?? null) ? $check['passphrase'] : []; ?>
  <?php if (($pp['ok'] ?? null) === true): ?>
    <p><?= te('backups.replica.passphrase_ok', ['pack' => (string)($pp['pack'] ?? '')]) ?></p>
  <?php elseif (($pp['ok'] ?? null) === null): ?>
    <p class="muted"><?= te('backups.replica.passphrase_unchecked') ?></p>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($canPull): ?>
<?php $needsWipe = $pullMode === 'full'; ?>
<section>
  <h2><?= te('backups.replica.pull_title') ?></h2>
  <?php if ($needsWipe): ?>
    <div class="flash flash-warn"><?= te('backups.replica.pull_warn') ?></div>
  <?php else: ?>
    <div class="flash flash-ok"><?= te('backups.replica.pull_warn_incr') ?></div>
  <?php endif; ?>
  <form method="post" class="form-grid" data-bk-replica-pull="1"
        <?php if ($needsWipe): ?>onsubmit="return confirm(<?= tj('backups.replica.pull_confirm') ?>);"<?php endif; ?>>
    <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
    <input type="hidden" name="action" value="pull_start">
    <?php if ($needsWipe): ?>
      <label class="check">
        <input type="checkbox" name="confirm_wipe" value="1" required>
        <?= te('backups.replica.confirm_wipe') ?>
      </label>
    <?php else: ?>
      <input type="hidden" name="confirm_wipe" value="1">
    <?php endif; ?>
    <p class="field-help"><?= te('backups.replica.pull_cli_hint') ?></p>
    <div class="page-actions">
      <button class="btn-primary" type="submit">
        <?= $pullMode === 'incr' ? te('backups.replica.pull_incr') : te('backups.replica.pull') ?>
      </button>
    </div>
  </form>
</section>
<?php endif; ?>

<?php if ($pull !== null && empty($pull['ok'])): ?>
  <pre class="mono"><?= e(json_encode($pull, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '') ?></pre>
<?php endif; ?>
