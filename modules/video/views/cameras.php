<?php declare(strict_types=1); ?>
<section>
  <p>
    <a class="btn" href="/video/cameras/new"><?= te('video.cameras.new') ?></a>
  </p>
  <form method="get" class="filters">
    <input type="search" name="q" value="<?= e((string)$q) ?>" placeholder="<?= te('video.cameras.search') ?>">
    <button type="submit"><?= te('video.cameras.filter') ?></button>
  </form>
  <table class="table">
    <thead><tr>
      <th></th>
      <th><?= te('video.col.name') ?></th>
      <th><?= te('video.col.model') ?></th>
      <th><?= te('video.col.mode') ?></th>
      <th><?= te('video.col.interval') ?></th>
      <th><?= te('video.col.enabled') ?></th>
      <th><?= te('video.col.last_seen') ?></th>
      <th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $c):
      $thumb = (string)($c['LAST_ANNOTATED_PATH'] ?? '');
      if ($thumb === '') {
          $thumb = (string)($c['LAST_FRAME_PATH'] ?? '');
      }
      $seen = (int)($c['LAST_SEEN'] ?? 0);
      ?>
      <tr>
        <td class="video-list-thumb">
          <?php if ($thumb !== ''): ?>
            <a href="/video/cameras/<?= (int)$c['ID'] ?>/live">
              <img src="/video/media?rel=<?= e(rawurlencode($thumb)) ?>" alt="">
            </a>
          <?php endif; ?>
        </td>
        <td>
          <a href="/video/cameras/<?= (int)$c['ID'] ?>/edit"><?= e((string)$c['NAME']) ?></a>
          <div class="muted"><?= e((string)($c['rtsp_hint'] ?? '')) ?></div>
        </td>
        <td><?= e((string)($c['MODEL_NAME'] ?? '—')) ?></td>
        <td><?= e((string)$c['MODE']) ?></td>
        <td><?= (int)($c['INTERVAL_SEC'] ?? 0) ?></td>
        <td>
          <span class="video-badge <?= !empty($c['ENABLED']) ? 'is-ok' : 'is-stale' ?>">
            <?= !empty($c['ENABLED']) ? te('core.common.yes') : te('core.common.no') ?>
          </span>
        </td>
        <td><?= $seen > 0 ? e(date('Y-m-d H:i:s', $seen)) : '—' ?></td>
        <td class="row-actions">
          <a href="/video/cameras/<?= (int)$c['ID'] ?>/live"><?= te('video.live.crumb') ?></a>
          <form method="post" class="inline">
            <input type="hidden" name="action" value="enqueue_monitor">
            <input type="hidden" name="id" value="<?= (int)$c['ID'] ?>">
            <button type="submit"><?= te('video.cameras.run_tick') ?></button>
          </form>
          <form method="post" class="inline">
            <input type="hidden" name="action" value="enqueue_ondemand">
            <input type="hidden" name="id" value="<?= (int)$c['ID'] ?>">
            <button type="submit"><?= te('video.cameras.run_ondemand') ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if ($rows === []): ?>
      <tr><td colspan="8" class="muted"><?= te('video.empty.cameras') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</section>
