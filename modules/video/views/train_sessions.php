<?php declare(strict_types=1); ?>
<section>
  <p class="video-dash-nav">
    <a class="btn" href="/video/wizard/train"><?= te('video.wizard.train') ?></a>
  </p>

  <h2><?= te('video.train.start') ?></h2>
  <form method="post" class="form">
    <input type="hidden" name="action" value="create">
    <label><?= te('video.col.post') ?>
      <select name="post_id">
        <option value="0">—</option>
        <?php foreach ($posts as $p): ?>
          <option value="<?= (int)$p['ID'] ?>"><?= e((string)$p['NAME']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= te('video.col.camera') ?>
      <select name="camera_id" required>
        <option value="0">—</option>
        <?php foreach ($cameras as $c): ?>
          <option value="<?= (int)$c['ID'] ?>"><?= e((string)$c['NAME']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= te('video.models.train_project') ?>
      <select name="project_id" required>
        <option value="0">—</option>
        <?php foreach ($projects as $p): ?>
          <option value="<?= (int)$p['ID'] ?>"><?= e((string)$p['NAME']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= te('video.train.target_class_id') ?>
      <input type="number" name="target_class_id" value="0" min="0">
    </label>
    <label><?= te('video.train.interval_ms') ?>
      <input type="number" name="interval_ms" value="500" min="100" step="50">
    </label>
    <label><?= te('video.train.source_model') ?>
      <input name="source_model" value="stock_yolo">
    </label>
    <label><?= te('video.train.person_class') ?>
      <input name="person_class" value="person">
    </label>
    <button type="submit"><?= te('video.train.start') ?></button>
  </form>

  <h2><?= te('video.train.recent') ?></h2>
  <table class="table">
    <thead><tr>
      <th>ID</th>
      <th><?= te('video.col.camera') ?></th>
      <th><?= te('video.train.phase') ?></th>
      <th><?= te('video.col.status') ?></th>
      <th><?= te('video.col.updated') ?></th>
      <th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= (int)$r['ID'] ?></td>
        <td><?= (int)$r['CAMERA_ID'] ?></td>
        <td><?= e((string)$r['PHASE']) ?></td>
        <td><?= e((string)$r['STATUS']) ?></td>
        <td><?= e(date('Y-m-d H:i:s', (int)$r['UPDATED_AT'])) ?></td>
        <td><a href="/video/train/<?= (int)$r['ID'] ?>"><?= te('video.posts.open') ?></a></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($rows === []): ?>
      <tr><td colspan="6" class="muted"><?= te('video.empty.sessions') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</section>
