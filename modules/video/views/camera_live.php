<?php declare(strict_types=1);
$ann = (string)($row['LAST_ANNOTATED_PATH'] ?? '');
$raw = (string)($row['LAST_FRAME_PATH'] ?? '');
$det = json_decode((string)($row['LAST_DETECT_JSON'] ?? ''), true) ?: [];
?>
<section class="video-live" data-camera-id="<?= (int)$row['ID'] ?>">
  <p class="muted"><?= te('video.live.hint') ?></p>
  <p>
    <form method="post" action="/video/cameras" style="display:inline">
      <input type="hidden" name="action" value="enqueue_ondemand">
      <input type="hidden" name="id" value="<?= (int)$row['ID'] ?>">
      <button type="submit"><?= te('video.cameras.run_ondemand') ?></button>
    </form>
    <a class="btn" href="/video/cameras/<?= (int)$row['ID'] ?>/live"><?= te('video.live.refresh') ?></a>
  </p>
  <?php if ($ann !== ''): ?>
    <p><img class="video-live-frame" src="/video/media?rel=<?= e(rawurlencode($ann)) ?>" alt=""></p>
  <?php elseif ($raw !== ''): ?>
    <p><img class="video-live-frame" src="/video/media?rel=<?= e(rawurlencode($raw)) ?>" alt=""></p>
  <?php else: ?>
    <p class="muted"><?= te('video.live.empty') ?></p>
  <?php endif; ?>
  <pre class="muted"><?= e(json_encode($det, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '') ?></pre>
</section>
