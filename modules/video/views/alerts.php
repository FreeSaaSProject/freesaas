<?php declare(strict_types=1); ?>
<section>
  <table class="table">
    <thead><tr>
      <th>ID</th>
      <th><?= te('video.col.camera') ?></th>
      <th><?= te('video.col.kind') ?></th>
      <th><?= te('video.col.message') ?></th>
      <th><?= te('video.alerts.staff') ?></th>
      <th><?= te('video.col.dt') ?></th>
      <th><?= te('video.alerts.frames') ?></th>
      <th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $a): ?>
      <?php
        $paths = array_slice(VideoAlertsRepository::framePaths($a), 0, 10);
        $staffId = (int)($a['STAFF_ID'] ?? 0);
        $state = (int)($a['STATE'] ?? 0);
        $appeal = $staffId > 0 ? VideoAppealsRepository::findByAlert((int)$a['ID']) : null;
        $mine = $staffId > 0 && $staffId === Auth::userId();
      ?>
      <tr>
        <td><?= (int)$a['ID'] ?></td>
        <td><?= e((string)($a['CAMERA_NAME'] ?? $a['CAMERA_ID'])) ?></td>
        <td><?= e((string)$a['KIND']) ?></td>
        <td><?= e((string)$a['MESSAGE']) ?>
          <?php if ($state !== 0): ?><span class="video-badge is-ok"><?= te('video.appeal.status.1') ?></span><?php endif; ?>
        </td>
        <td><?= e((string)($staffNames[$staffId] ?? '')) ?></td>
        <td><?= e(date('Y-m-d H:i:s', (int)$a['DT'])) ?></td>
        <td>
          <?php foreach ($paths as $rel): ?>
            <a href="/video/media?rel=<?= e(rawurlencode((string)$rel)) ?>" target="_blank">
              <img src="/video/media?rel=<?= e(rawurlencode((string)$rel)) ?>" alt="" loading="lazy" style="height:48px;margin:0 2px 2px 0">
            </a>
          <?php endforeach; ?>
        </td>
        <td>
          <?php if ($appeal !== null): ?>
            <a href="/video/appeals/<?= (int)$appeal['ID'] ?>"><?= te('video.appeal.status.' . (int)$appeal['STATUS']) ?></a>
          <?php elseif ($mine && $state === 0): ?>
            <details>
              <summary><?= te('video.bot.btn.appeal') ?></summary>
              <form method="post" action="/video/appeals" class="form">
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="alert_id" value="<?= (int)$a['ID'] ?>">
                <label><?= te('video.appeal.col.reason') ?> <input name="reason" maxlength="1000" required></label>
                <button type="submit" class="btn-primary btn-sm"><?= te('video.appeal.submit') ?></button>
              </form>
            </details>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if ($rows === []): ?>
      <tr><td colspan="8" class="muted"><?= te('video.empty.alerts') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</section>
