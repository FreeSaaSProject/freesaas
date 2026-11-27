<?php declare(strict_types=1); ?>
<section class="video-presence">
  <form method="get" class="page-actions">
    <label><?= te('video.col.camera') ?>
      <select name="camera_id" onchange="this.form.submit()">
        <?php foreach ($cameras as $c): ?>
          <option value="<?= (int)$c['ID'] ?>"<?= (int)$c['ID'] === $cameraId ? ' selected' : '' ?>><?= e((string)$c['NAME']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= te('video.presence.day') ?> <input type="date" name="day" value="<?= e($day) ?>" onchange="this.form.submit()"></label>
  </form>

  <?php if ($classToken === ''): ?>
    <p class="muted"><?= te('video.presence.no_rule') ?></p>
  <?php else: ?>
    <p class="muted"><?= te('video.presence.class', ['class' => $classToken]) ?>
      · <?= te('video.presence.totals', [
          'present' => (int)$report['totals']['present_min'],
          'absent' => (int)$report['totals']['absent_min'],
          'filtered' => (int)$report['totals']['filtered_buckets'],
      ]) ?></p>
    <table class="table">
      <thead><tr>
        <th><?= te('video.presence.interval') ?></th>
        <th><?= te('video.presence.present_min') ?></th>
        <th><?= te('video.presence.absent_min') ?></th>
        <th><?= te('video.presence.avg_guests') ?></th>
        <th><?= te('video.presence.filtered') ?></th>
      </tr></thead>
      <tbody>
      <?php foreach ($report['intervals'] as $iv): ?>
        <tr>
          <td><?= e(date('H:i', (int)$iv['from'])) ?>–<?= e(date('H:i', (int)$iv['to'])) ?></td>
          <td><?= (int)$iv['present_min'] ?></td>
          <td><?= (int)$iv['absent_min'] ?></td>
          <td><?= e((string)$iv['avg_guests']) ?></td>
          <td><?= (int)$iv['filtered'] ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($report['intervals'] === []): ?>
        <tr><td colspan="5" class="muted"><?= te('video.presence.empty') ?></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>
