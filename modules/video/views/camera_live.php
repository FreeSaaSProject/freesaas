<?php
declare(strict_types=1);
$ann = (string)($row['LAST_ANNOTATED_PATH'] ?? '');
$raw = (string)($row['LAST_FRAME_PATH'] ?? '');
$detWrap = json_decode((string)($row['LAST_DETECT_JSON'] ?? ''), true) ?: [];
$detections = [];
if (isset($detWrap['detections']) && is_array($detWrap['detections'])) {
    $detections = $detWrap['detections'];
} elseif (is_array($detWrap) && isset($detWrap[0])) {
    $detections = $detWrap;
}
/** @var list<array{id:int,name:string,color:string}> $classes */
$classes = $classes ?? [];
$frameRel = $ann !== '' ? $ann : $raw;
?>
<section class="video-live" data-camera-id="<?= (int)$row['ID'] ?>" data-poll-url="/video/cameras/<?= (int)$row['ID'] ?>/live?format=json">
  <p class="muted"><?= te('video.live.hint') ?></p>
  <p class="video-live-toolbar actions">
    <form method="post" action="/video/cameras" class="inline">
      <input type="hidden" name="action" value="enqueue_ondemand">
      <input type="hidden" name="id" value="<?= (int)$row['ID'] ?>">
      <button type="submit" class="btn-primary btn-sm"><?= te('video.cameras.run_ondemand') ?></button>
    </form>
    <button type="button" class="btn-secondary btn-sm video-live-soft-refresh"><?= te('video.live.refresh') ?></button>
    <span class="muted video-live-seen" data-last-seen="<?= (int)($row['LAST_SEEN'] ?? 0) ?>">
      <?= te('video.live.frame_at') ?>:
      <strong class="video-live-seen-text"><?= e(VideoTime::stamp((int)($row['LAST_SEEN'] ?? 0))) ?></strong>
    </span>
  </p>

  <?php if ($classes !== []): ?>
    <ul class="video-legend">
      <?php foreach ($classes as $cl): ?>
        <li>
          <span class="video-swatch" style="background:<?= e((string)$cl['color']) ?>"></span>
          <?= e(VideoColors::displayName($cl)) ?>
          <?php if (($cl['label'] ?? '') !== '' && ($cl['label'] ?? '') !== ($cl['name'] ?? '')): ?>
            <span class="muted">(<?= e((string)$cl['name']) ?>)</span>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <div class="video-live-frame-wrap">
    <?php if ($frameRel !== ''): ?>
      <img class="video-live-frame" data-rel="<?= e($frameRel) ?>"
           src="/video/media?rel=<?= e(rawurlencode($frameRel)) ?>&t=<?= (int)($row['LAST_SEEN'] ?? time()) ?>"
           alt="">
    <?php else: ?>
      <p class="muted video-live-empty"><?= te('video.live.empty') ?></p>
    <?php endif; ?>
  </div>

  <h2><?= te('video.live.detections') ?></h2>
  <table class="table video-det-table">
    <thead><tr>
      <th></th>
      <th><?= te('video.col.name') ?></th>
      <th><?= te('video.col.conf') ?></th>
    </tr></thead>
    <tbody class="video-det-body">
    <?php foreach ($detections as $d):
      if (!is_array($d)) {
          continue;
      }
      $cname = (string)($d['class_name'] ?? $d['class'] ?? '');
      $cid = (int)($d['class_id'] ?? 0);
      $conf = (float)($d['confidence'] ?? $d['conf'] ?? 0);
      $color = (string)($d['color'] ?? '');
      foreach ($classes as $cl) {
          if ((int)($cl['id'] ?? -1) !== $cid) {
              continue;
          }
          if ($color === '') {
              $color = (string)($cl['color'] ?? '');
          }
          // Prefer project label; ignore burned-in "????" from OpenCV overlays.
          $disp = VideoColors::displayName($cl);
          if ($disp !== '' && ($cname === '' || preg_match('/^\?+$/', $cname))) {
              $cname = $disp;
          }
          break;
      }
      if ($color === '') {
          $color = VideoColors::defaultColor($cid);
      }
      if ($cname === '' || preg_match('/^\?+$/', $cname)) {
          $cname = '#' . $cid;
      }
      ?>
      <tr>
        <td><span class="video-swatch" style="background:<?= e($color) ?>"></span></td>
        <td><?= e($cname) ?></td>
        <td><?= e(number_format($conf * 100, 1)) ?>%</td>
      </tr>
    <?php endforeach; ?>
    <?php if ($detections === []): ?>
      <tr><td colspan="3" class="muted"><?= te('video.live.no_detections') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</section>
