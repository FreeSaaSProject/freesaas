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
    <a class="btn" href="/video/posts"><?= te('video.menu.posts') ?></a>
    <a class="btn" href="/video/wizard/setup"><?= te('video.wizard.setup') ?></a>
    <a class="btn" href="/video/wizard/train"><?= te('video.wizard.train') ?></a>
    <a class="btn" href="/video/cameras"><?= te('video.menu.cameras') ?></a>
    <a class="btn" href="/video/models"><?= te('video.menu.models') ?></a>
    <a class="btn" href="/video/alerts"><?= te('video.menu.alerts') ?></a>
    <a class="btn" href="/video/settings"><?= te('video.menu.settings') ?></a>
  </p>

  <h2><?= te('video.dashboard.posts') ?></h2>
  <div class="video-cam-grid">
    <?php foreach ($posts as $card):
      $seen = (int)($card['LAST_SEEN'] ?? 0);
      $fresh = $seen > 0 && ($now - $seen) <= $staleSec;
      ?>
      <article class="video-cam-card video-post-card">
        <div class="video-cam-body">
          <h3>
            <a href="/video/posts/<?= (int)$card['ID'] ?>"><?= e((string)$card['NAME']) ?></a>
            <span class="video-badge <?= $fresh ? 'is-ok' : 'is-stale' ?>">
              <?= $fresh ? te('video.badge.fresh') : te('video.badge.stale') ?>
            </span>
          </h3>
          <p class="muted">
            <?= te('video.posts.cameras_n', ['n' => (string)(int)$card['CAMERA_COUNT']]) ?>
            <?php if ($card['MODEL_NAME'] !== ''): ?> · <?= e((string)$card['MODEL_NAME']) ?><?php endif; ?>
          </p>
          <?php if (!empty($card['class_counts'])): ?>
            <p class="video-counts">
              <?php foreach ($card['class_counts'] as $name => $cnt): ?>
                <span><?= e((string)$name) ?>: <strong><?= (int)$cnt ?></strong></span>
              <?php endforeach; ?>
            </p>
          <?php endif; ?>
          <div class="video-post-thumbs">
            <?php foreach (array_slice($card['cameras'], 0, 4) as $c):
              $thumb = (string)(($c['LAST_ANNOTATED_PATH'] ?? '') ?: ($c['LAST_FRAME_PATH'] ?? ''));
              ?>
              <a href="/video/cameras/<?= (int)$c['ID'] ?>/live" class="video-mini-thumb">
                <?php if ($thumb !== ''): ?>
                  <img src="/video/media?rel=<?= e(rawurlencode($thumb)) ?>" alt="">
                <?php else: ?>
                  <span><?= e((string)$c['NAME']) ?></span>
                <?php endif; ?>
              </a>
            <?php endforeach; ?>
          </div>
          <p class="video-cam-actions">
            <a href="/video/posts/<?= (int)$card['ID'] ?>"><?= te('video.posts.open') ?></a>
            <a href="/video/posts/<?= (int)$card['ID'] ?>/edit"><?= te('core.common.edit') ?></a>
          </p>
        </div>
      </article>
    <?php endforeach; ?>
    <?php if ($posts === []): ?>
      <p class="muted"><?= te('video.empty.posts') ?>
        — <a href="/video/wizard/setup"><?= te('video.wizard.setup') ?></a>
      </p>
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
