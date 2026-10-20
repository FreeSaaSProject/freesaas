<?php declare(strict_types=1); ?>
<section>
  <table class="table">
    <thead><tr>
      <th>ID</th>
      <th><?= te('video.col.camera') ?></th>
      <th><?= te('video.col.kind') ?></th>
      <th><?= te('video.col.message') ?></th>
      <th><?= te('video.col.dt') ?></th>
      <th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $a): ?>
      <?php
        $paths = json_decode((string)($a['FRAME_PATHS_JSON'] ?? ''), true) ?: [];
        $first = is_array($paths) && $paths !== [] ? (string)$paths[0] : '';
      ?>
      <tr>
        <td><?= (int)$a['ID'] ?></td>
        <td><?= e((string)($a['CAMERA_NAME'] ?? $a['CAMERA_ID'])) ?></td>
        <td><?= e((string)$a['KIND']) ?></td>
        <td><?= e((string)$a['MESSAGE']) ?></td>
        <td><?= e(date('Y-m-d H:i:s', (int)$a['DT'])) ?></td>
        <td>
          <?php if ($first !== ''): ?>
            <a href="/video/media?rel=<?= e(rawurlencode($first)) ?>" target="_blank"><?= te('video.alerts.frame') ?></a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if ($rows === []): ?>
      <tr><td colspan="6" class="muted"><?= te('video.empty.alerts') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</section>
