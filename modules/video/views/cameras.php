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
      <th><?= te('video.col.name') ?></th>
      <th>RTSP</th>
      <th><?= te('video.col.mode') ?></th>
      <th><?= te('video.col.enabled') ?></th>
      <th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $c): ?>
      <tr>
        <td><a href="/video/cameras/<?= (int)$c['ID'] ?>/edit"><?= e((string)$c['NAME']) ?></a></td>
        <td class="muted"><?= e(mb_substr((string)$c['RTSP_URL'], 0, 48)) ?></td>
        <td><?= e((string)$c['MODE']) ?></td>
        <td><?= !empty($c['ENABLED']) ? te('core.common.yes') : te('core.common.no') ?></td>
        <td class="row-actions">
          <a href="/video/cameras/<?= (int)$c['ID'] ?>/live"><?= te('video.live.crumb') ?></a>
          <form method="post" style="display:inline">
            <input type="hidden" name="action" value="enqueue_monitor">
            <input type="hidden" name="id" value="<?= (int)$c['ID'] ?>">
            <button type="submit"><?= te('video.cameras.run_tick') ?></button>
          </form>
          <form method="post" style="display:inline">
            <input type="hidden" name="action" value="enqueue_ondemand">
            <input type="hidden" name="id" value="<?= (int)$c['ID'] ?>">
            <button type="submit"><?= te('video.cameras.run_ondemand') ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if ($rows === []): ?>
      <tr><td colspan="5" class="muted"><?= te('video.empty.cameras') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</section>
