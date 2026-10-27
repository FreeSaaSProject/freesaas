<?php declare(strict_types=1); ?>
<section>
  <div class="video-toolbar">
    <a class="btn btn-primary" href="/video/wizard/train"><?= te('video.wizard.train') ?></a>
    <a class="btn" href="/video-label"><?= te('video_label.menu.projects') ?></a>
  </div>
  <p class="muted"><?= te('video.train.local_collect_notice') ?></p>

  <h2 class="video-section-title"><?= te('video.train.start') ?></h2>
  <form method="post" class="form video-form">
    <input type="hidden" name="action" value="create">
    <div class="video-form-row">
      <label class="video-field"><?= te('video.col.post') ?>
        <select name="post_id">
          <option value="0">—</option>
          <?php foreach ($posts as $p): ?>
            <option value="<?= (int)$p['ID'] ?>"><?= e((string)$p['NAME']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="video-field"><?= te('video.col.camera') ?>
        <select name="camera_id" required>
          <option value="0">—</option>
          <?php foreach ($cameras as $c): ?>
            <option value="<?= (int)$c['ID'] ?>"><?= e((string)$c['NAME']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="video-field"><?= te('video.models.train_project') ?>
        <select name="project_id" required>
          <option value="0">—</option>
          <?php foreach ($projects as $p): ?>
            <option value="<?= (int)$p['ID'] ?>"><?= e((string)$p['NAME']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <div class="video-form-row">
      <label class="video-field"><?= te('video.train.target_class_id') ?>
        <input type="number" name="target_class_id" value="0" min="0">
      </label>
      <label class="video-field"><?= te('video.train.interval_ms') ?>
        <input type="number" name="interval_ms" value="500" min="100" step="50">
      </label>
      <label class="video-field"><?= te('video.train.source_model') ?>
        <input name="source_model" value="stock_yolo">
      </label>
      <label class="video-field"><?= te('video.train.person_class') ?>
        <input name="person_class" value="person">
      </label>
    </div>
    <div class="video-form-actions">
      <button type="submit" class="btn btn-primary"><?= te('video.train.start') ?></button>
    </div>
  </form>

  <h2 class="video-section-title"><?= te('video.train.recent') ?></h2>
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
        <td><span class="video-badge <?= (string)$r['STATUS'] === 'running' ? 'is-ok' : 'is-stale' ?>"><?= e((string)$r['STATUS']) ?></span></td>
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
