<?php declare(strict_types=1); ?>
<section class="video-dash">
  <p class="muted"><?= te('video.dashboard.intro') ?></p>
  <p>
    <strong><?= te('video.dashboard.workers_online') ?>:</strong> <?= (int)$online ?>
    · <strong><?= te('video.dashboard.storage') ?>:</strong> <?= e(number_format((int)$storageBytes / 1048576, 1)) ?> MB
  </p>
  <p>
    <a class="btn" href="/video/cameras"><?= te('video.menu.cameras') ?></a>
    <a class="btn" href="/video/models"><?= te('video.menu.models') ?></a>
    <a class="btn" href="/video/alerts"><?= te('video.menu.alerts') ?></a>
    <a class="btn" href="/video/settings"><?= te('video.menu.settings') ?></a>
  </p>

  <h2><?= te('video.dashboard.cameras') ?></h2>
  <table class="table">
    <thead><tr>
      <th><?= te('video.col.name') ?></th>
      <th><?= te('video.col.mode') ?></th>
      <th><?= te('video.col.last_seen') ?></th>
      <th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($cameras as $c): ?>
      <tr>
        <td><a href="/video/cameras/<?= (int)$c['ID'] ?>/edit"><?= e((string)$c['NAME']) ?></a></td>
        <td><?= e((string)$c['MODE']) ?></td>
        <td><?= !empty($c['LAST_SEEN']) ? e(date('Y-m-d H:i:s', (int)$c['LAST_SEEN'])) : '—' ?></td>
        <td><a href="/video/cameras/<?= (int)$c['ID'] ?>/live"><?= te('video.live.crumb') ?></a></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($cameras === []): ?>
      <tr><td colspan="4" class="muted"><?= te('video.empty.cameras') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>

  <h2><?= te('video.dashboard.workers') ?></h2>
  <table class="table">
    <thead><tr>
      <th><?= te('video.col.worker') ?></th>
      <th><?= te('video.col.host') ?></th>
      <th><?= te('video.col.last_seen') ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($workers as $w): ?>
      <tr>
        <td><?= e((string)$w['WORKER_KEY']) ?></td>
        <td><?= e((string)$w['HOSTNAME']) ?></td>
        <td><?= e(date('Y-m-d H:i:s', (int)$w['LAST_SEEN'])) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($workers === []): ?>
      <tr><td colspan="3" class="muted"><?= te('video.empty.workers') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>

  <h2><?= te('video.dashboard.jobs') ?></h2>
  <table class="table">
    <thead><tr>
      <th>ID</th>
      <th><?= te('video.col.type') ?></th>
      <th><?= te('video.col.status') ?></th>
      <th><?= te('video.col.updated') ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($jobs as $j): ?>
      <tr>
        <td><?= (int)$j['ID'] ?></td>
        <td><?= e((string)$j['TYPE']) ?></td>
        <td><?= e((string)$j['STATUS']) ?></td>
        <td><?= e(date('Y-m-d H:i:s', (int)$j['UPDATED_AT'])) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($jobs === []): ?>
      <tr><td colspan="4" class="muted"><?= te('video.empty.jobs') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</section>
