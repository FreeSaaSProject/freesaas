<?php declare(strict_types=1); ?>
<section>
  <?php if (!empty($error)): ?><p class="error"><?= e((string)$error) ?></p><?php endif; ?>

  <h2><?= te('video.models.register') ?></h2>
  <form method="post" enctype="multipart/form-data" class="form">
    <input type="hidden" name="action" value="save">
    <label><?= te('video.col.name') ?> <input name="name" required></label>
    <label><?= te('video.models.weights_path') ?> <input name="weights_path" placeholder="models/best.pt or worker local path"></label>
    <p class="field-help"><?= te('video.models.weights_path.help') ?></p>
    <label><?= te('video.models.weights_file') ?> <input type="file" name="weights_file" accept=".pt"></label>
    <label><?= te('video.models.classes') ?>
      <textarea name="classes" rows="4" placeholder="guest&#10;admin #3cb44b"></textarea>
    </label>
    <p class="field-help"><?= te('video.models.classes.help') ?></p>
    <label><?= te('video.models.notes') ?> <input name="notes"></label>
    <button type="submit"><?= te('core.common.save') ?></button>
  </form>

  <h2><?= te('video.models.train') ?></h2>
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
    <label><?= te('video.models.base_weights') ?> <input name="base_weights" placeholder="yolo11n.pt"></label>
    <label><?= te('video.col.name') ?> <input name="model_name" value="trained"></label>
    <label>epochs <input type="number" name="epochs" value="50"></label>
    <label>imgsz <input type="number" name="imgsz" value="640"></label>
    <button type="submit"><?= te('video.models.enqueue_train') ?></button>
  </form>

  <h2><?= te('video.models.list') ?></h2>
  <table class="table">
    <thead><tr>
      <th>ID</th>
      <th><?= te('video.col.name') ?></th>
      <th><?= te('video.models.weights_path') ?></th>
      <th><?= te('video.models.classes') ?></th>
      <th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $m):
      $cls = VideoModelsRepository::classesOf($m);
      ?>
      <tr>
        <td><?= (int)$m['ID'] ?></td>
        <td><?= e((string)$m['NAME']) ?></td>
        <td class="muted"><?= e((string)$m['WEIGHTS_PATH']) ?></td>
        <td>
          <ul class="video-legend video-legend-compact">
            <?php foreach ($cls as $cl): ?>
              <li>
                <span class="video-swatch" style="background:<?= e((string)$cl['color']) ?>"></span>
                <?= e((string)$cl['name']) ?>
              </li>
            <?php endforeach; ?>
            <?php if ($cls === []): ?>
              <li class="muted">—</li>
            <?php endif; ?>
          </ul>
        </td>
        <td>
          <form method="post" class="inline" onsubmit="return confirm(<?= tj('core.bulk.confirm_delete') ?>)">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$m['ID'] ?>">
            <button type="submit"><?= te('core.common.delete') ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if ($rows === []): ?>
      <tr><td colspan="5" class="muted"><?= te('video.empty.models') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</section>
