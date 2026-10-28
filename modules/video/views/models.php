<?php declare(strict_types=1); ?>
<section class="video-models">
  <?php if (!empty($error)): ?><p class="error"><?= e((string)$error) ?></p><?php endif; ?>

  <div class="video-toolbar">
    <h2 class="video-section-title" style="margin:0"><?= te('video.models.list') ?></h2>
    <span class="spacer"></span>
    <span class="muted"><?= (int)count($rows) ?></span>
  </div>

  <?php if ($rows === []): ?>
    <p class="muted video-empty"><?= te('video.empty.models') ?></p>
  <?php else: ?>
    <div class="video-models-grid">
      <?php foreach ($rows as $m):
        $cls = VideoModelsRepository::classesOf($m);
        ?>
        <article class="video-model-card">
          <header class="video-model-card-head">
            <strong><?= e((string)$m['NAME']) ?></strong>
            <span class="muted">
              <?php if (VideoModelsRepository::isStock($m)): ?>
                <span class="video-badge is-ok"><?= te('video.models.stock_badge') ?></span>
              <?php endif; ?>
              #<?= (int)$m['ID'] ?>
            </span>
          </header>
          <p class="video-model-path muted" title="<?= e((string)$m['WEIGHTS_PATH']) ?>"><?= e((string)$m['WEIGHTS_PATH']) ?></p>
          <?php if ($cls !== []): ?>
            <ul class="video-legend video-legend-compact">
              <?php foreach ($cls as $cl): ?>
                <li>
                  <span class="video-swatch" style="background:<?= e((string)$cl['color']) ?>"></span>
                  <?= e(VideoColors::displayName($cl)) ?>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <p class="muted"><?= te('video.models.no_classes') ?></p>
          <?php endif; ?>
          <footer class="video-model-card-foot">
            <form method="post" class="inline" onsubmit="return confirm(<?= tj('core.bulk.confirm_delete') ?>)">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$m['ID'] ?>">
              <button type="submit" class="btn-danger btn-sm"><?= te('core.common.delete') ?></button>
            </form>
          </footer>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <details class="video-models-panel">
    <summary><?= te('video.models.register') ?></summary>
    <form method="post" enctype="multipart/form-data" class="form">
      <input type="hidden" name="action" value="save">
      <label><?= te('video.col.name') ?> <input name="name" required></label>
      <label><?= te('video.models.weights_path') ?> <input name="weights_path" placeholder="models/best.pt"></label>
      <p class="field-help"><?= te('video.models.weights_path.help') ?></p>
      <label><?= te('video.models.weights_file') ?> <input type="file" name="weights_file" accept=".pt"></label>
      <label><?= te('video.models.classes') ?>
        <textarea name="classes" rows="3" placeholder="guest|гость #e6194b&#10;admin|сотрудник #3cb44b"></textarea>
      </label>
      <p class="field-help"><?= te('video.models.classes.help') ?></p>
      <label><?= te('video.models.notes') ?> <input name="notes"></label>
      <button type="submit" class="btn-primary"><?= te('core.common.save') ?></button>
    </form>
  </details>

  <details class="video-models-panel">
    <summary><?= te('video.models.train') ?></summary>
    <form method="post" class="form">
      <input type="hidden" name="action" value="enqueue_train">
      <label><?= te('video.models.train_project') ?>
        <select name="project_id">
          <option value="0">—</option>
          <?php foreach ($labelProjects as $p): ?>
            <option value="<?= (int)$p['ID'] ?>"><?= e((string)$p['NAME']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <div class="video-models-train-row">
        <label><?= te('video.models.base_weights') ?> <input name="base_weights" placeholder="yolo11n.pt"></label>
        <label><?= te('video.col.name') ?> <input name="model_name" value="trained"></label>
        <label>epochs <input type="number" name="epochs" value="50" min="1"></label>
        <label>imgsz <input type="number" name="imgsz" value="640" min="32"></label>
      </div>
      <button type="submit" class="btn-primary"><?= te('video.models.enqueue_train') ?></button>
    </form>
  </details>
</section>
