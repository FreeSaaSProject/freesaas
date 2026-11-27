<?php declare(strict_types=1); ?>
<section class="video-appeals">
  <form method="get" class="page-actions">
    <label><?= te('video.appeal.col.status') ?>
      <select name="status" onchange="this.form.submit()">
        <option value=""><?= te('video.appeal.filter_all') ?></option>
        <?php foreach ([0, 1, 2] as $s): ?>
          <option value="<?= $s ?>"<?= $status === $s ? ' selected' : '' ?>><?= te('video.appeal.status.' . $s) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </form>
  <table class="table">
    <thead><tr>
      <th>ID</th>
      <th><?= te('video.appeal.col.staff') ?></th>
      <th><?= te('video.col.camera') ?></th>
      <th><?= te('video.appeal.col.alert_dt') ?></th>
      <th><?= te('video.appeal.col.reason') ?></th>
      <th><?= te('video.appeal.col.status') ?></th>
      <th><?= te('video.appeal.col.decider') ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $a): ?>
      <tr>
        <td><a href="/video/appeals/<?= (int)$a['ID'] ?>">#<?= (int)$a['ID'] ?></a></td>
        <td><?= e((string)($a['STAFF_NAME'] ?? '')) ?></td>
        <td><?= e((string)($a['CAMERA_NAME'] ?? '')) ?></td>
        <td><?= e(date('Y-m-d H:i', (int)$a['ALERT_DT'])) ?></td>
        <td><?= e((string)$a['REASON']) ?></td>
        <td><?= te('video.appeal.status.' . (int)$a['STATUS']) ?></td>
        <td><?= e((string)($a['DECIDER_NAME'] ?? '')) ?><?= (string)$a['COMMENT'] !== '' ? ' — ' . e((string)$a['COMMENT']) : '' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($rows === []): ?>
      <tr><td colspan="7" class="muted"><?= te('video.appeal.empty') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</section>
