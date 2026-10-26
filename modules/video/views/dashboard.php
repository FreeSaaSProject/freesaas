<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $cameras */
/** @var list<array<string,mixed>> $workers */
/** @var list<array<string,mixed>> $jobs */
/** @var array<string,mixed> $diagnose */
$now = time();
$staleSec = 120;
?>
<section class="video-dash">
  <p class="muted"><?= te('video.dashboard.intro') ?></p>

  <div class="video-dash-stats">
    <span><strong><?= te('video.dashboard.workers_online') ?>:</strong>
      <span class="<?= (int)$online > 0 ? 'video-ok' : 'video-warn' ?>"><?= (int)$online ?></span>
    </span>
    <span><strong><?= te('video.dashboard.storage') ?>:</strong>
      <?= e(number_format((int)$storageBytes / 1048576, 1)) ?> MB
    </span>
  </div>

  <?php if (!empty($diagnose['issues'])): ?>
    <div class="video-next">
      <h2><?= te('video.status.issues') ?></h2>
      <ul>
        <?php foreach ($diagnose['issues'] as $iss): ?>
          <li class="video-issue-<?= e((string)($iss['severity'] ?? 'warn')) ?>">
            <?= e((string)($iss['message'] ?? '')) ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php if (!empty($diagnose['agent_next'])): ?>
        <h3><?= te('video.status.next') ?></h3>
        <ol>
          <?php foreach ($diagnose['agent_next'] as $step): ?>
            <li><?= e((string)$step) ?></li>
          <?php endforeach; ?>
        </ol>
      <?php endif; ?>
      <p><a href="/video/status"><?= te('video.status.title') ?></a></p>
    </div>
  <?php endif; ?>

  <p class="video-dash-nav">
    <a class="btn" href="/video/cameras"><?= te('video.menu.cameras') ?></a>
    <a class="btn" href="/video/models"><?= te('video.menu.models') ?></a>
    <a class="btn" href="/video/alerts"><?= te('video.menu.alerts') ?></a>
    <a class="btn" href="/video/settings"><?= te('video.menu.settings') ?></a>
  </p>

  <h2><?= te('video.dashboard.cameras') ?></h2>
  <div class="video-cam-grid">
    <?php foreach ($cameras as $c):
      $id = (int)$c['ID'];
      $seen = (int)($c['LAST_SEEN'] ?? 0);
      $fresh = $seen > 0 && ($now - $seen) <= $staleSec;
      $ann = (string)($c['LAST_ANNOTATED_PATH'] ?? '');
      $raw = (string)($c['LAST_FRAME_PATH'] ?? '');
      $thumb = $ann !== '' ? $ann : $raw;
      $det = json_decode((string)($c['LAST_DETECT_JSON'] ?? ''), true) ?: [];
      $boxes = is_array($det['detections'] ?? null) ? $det['detections'] : (is_array($det) && isset($det[0]) ? $det : []);
      $nDet = is_array($boxes) ? count($boxes) : 0;
      ?>
      <article class="video-cam-card">
        <a class="video-cam-thumb" href="/video/cameras/<?= $id ?>/live">
          <?php if ($thumb !== ''): ?>
            <img src="/video/media?rel=<?= e(rawurlencode($thumb)) ?>" alt="">
          <?php else: ?>
            <span class="video-cam-placeholder"><?= te('video.live.empty') ?></span>
          <?php endif; ?>
        </a>
        <div class="video-cam-body">
          <h3>
            <a href="/video/cameras/<?= $id ?>/edit"><?= e((string)$c['NAME']) ?></a>
            <span class="video-badge <?= $fresh ? 'is-ok' : 'is-stale' ?>">
              <?= $fresh ? te('video.badge.fresh') : te('video.badge.stale') ?>
            </span>
          </h3>
          <p class="muted">
            <?= e((string)($c['MODE'] ?? '')) ?>
            <?php if (!empty($c['MODEL_NAME'])): ?> · <?= e((string)$c['MODEL_NAME']) ?><?php endif; ?>
            · <?= te('video.live.detections_n', ['n' => (string)$nDet]) ?>
          </p>
          <p class="muted"><?= $seen > 0 ? e(date('Y-m-d H:i:s', $seen)) : '—' ?></p>
          <p class="video-cam-actions">
            <a href="/video/cameras/<?= $id ?>/live"><?= te('video.live.crumb') ?></a>
            <form method="post" action="/video/cameras" class="inline">
              <input type="hidden" name="action" value="enqueue_ondemand">
              <input type="hidden" name="id" value="<?= $id ?>">
              <button type="submit"><?= te('video.cameras.run_ondemand') ?></button>
            </form>
          </p>
        </div>
      </article>
    <?php endforeach; ?>
    <?php if ($cameras === []): ?>
      <p class="muted"><?= te('video.empty.cameras') ?></p>
    <?php endif; ?>
  </div>

  <h2><?= te('video.dashboard.workers') ?></h2>
  <table class="table">
    <thead><tr>
      <th><?= te('video.col.worker') ?></th>
      <th><?= te('video.col.host') ?></th>
      <th><?= te('video.col.status') ?></th>
      <th><?= te('video.col.last_seen') ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($workers as $w):
      $ls = (int)$w['LAST_SEEN'];
      $on = $ls >= $now - $staleSec;
      ?>
      <tr>
        <td><?= e((string)$w['WORKER_KEY']) ?></td>
        <td><?= e((string)$w['HOSTNAME']) ?></td>
        <td><span class="video-badge <?= $on ? 'is-ok' : 'is-stale' ?>"><?= $on ? te('video.badge.online') : te('video.badge.offline') ?></span></td>
        <td><?= e(date('Y-m-d H:i:s', $ls)) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($workers === []): ?>
      <tr><td colspan="4" class="muted"><?= te('video.empty.workers') ?></td></tr>
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
