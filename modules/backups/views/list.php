<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $runs */
/** @var array<string,mixed>|null $running */
/** @var list<array<string,mixed>> $remote */
/** @var string $remoteError */
/** @var bool $canManage */
$statusLabel = static function (string $s): string {
    $key = 'backups.status.' . $s;
    $t = t($key);
    return $t !== $key ? $t : $s;
};
?>
<section class="page-head">
  <p class="lede"><?= te('backups.list.lede') ?></p>
  <?php if (!empty($canManage)): ?>
  <div class="page-actions">
    <form method="post" action="/backups/run" class="inline">
      <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
      <button type="submit" class="btn-primary"><?= te('backups.list.run_now') ?></button>
    </form>
    <a class="btn" href="/backups/settings"><?= te('backups.menu.settings') ?></a>
    <a class="btn" href="/backups/restore"><?= te('backups.menu.restore') ?></a>
  </div>
  <?php endif; ?>
</section>

<?php if (is_array($running)): ?>
  <div class="flash flash-warn"><?= te('backups.list.running') ?>
    #<?= (int)$running['id'] ?> · <?= e((string)$running['phase']) ?>
  </div>
<?php endif; ?>

<section>
  <h2><?= te('backups.list.title') ?></h2>
  <?php if ($runs === []): ?>
    <p class="muted"><?= te('backups.list.empty') ?></p>
  <?php else: ?>
  <table class="data">
    <thead>
      <tr>
        <th><?= te('backups.list.col.id') ?></th>
        <th><?= te('backups.list.col.started') ?></th>
        <th><?= te('backups.list.col.status') ?></th>
        <th><?= te('backups.list.col.phase') ?></th>
        <th><?= te('backups.list.col.bytes') ?></th>
        <th><?= te('backups.list.col.remote') ?></th>
        <th><?= te('backups.list.col.trigger') ?></th>
        <th><?= te('backups.list.col.error') ?></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($runs as $r): ?>
      <tr>
        <td><?= (int)$r['id'] ?></td>
        <td><?= e($r['started_at'] ? date('Y-m-d H:i', (int)$r['started_at']) : '—') ?></td>
        <td><?= e($statusLabel((string)$r['status'])) ?></td>
        <td><?= e((string)$r['phase']) ?></td>
        <td><?= e(BackupNotify::formatBytes((int)$r['bytes'])) ?></td>
        <td class="mono"><?= e((string)$r['remote_dir']) ?></td>
        <td><?= e((string)$r['triggered_by']) ?></td>
        <td><?= e((string)$r['error']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>

<section>
  <h2><?= te('backups.list.remote') ?></h2>
  <?php if ($remoteError !== ''): ?>
    <div class="flash flash-warn"><?= e($remoteError) ?></div>
  <?php elseif ($remote === []): ?>
    <p class="muted"><?= te('backups.list.remote_empty') ?></p>
  <?php else: ?>
  <ul class="plain">
    <?php foreach ($remote as $set): ?>
      <li class="mono">
        <?= e((string)$set['path']) ?>
        <?php if (empty($set['has_manifest'])): ?>
          <span class="muted">(<?= te('backups.list.remote_incomplete') ?>)</span>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
