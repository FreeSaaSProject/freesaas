<?php declare(strict_types=1); ?>
<section class="video-label-workspace">
  <p>
    <a href="/video-label/<?= (int)$project['ID'] ?>/edit"><?= te('core.common.edit') ?></a>
  </p>
  <form method="post" enctype="multipart/form-data" class="form-inline">
    <input type="hidden" name="action" value="upload">
    <input type="file" name="images[]" accept="image/*" multiple required>
    <button type="submit"><?= te('video_label.workspace.upload') ?></button>
  </form>
  <form method="post" class="form-inline">
    <input type="hidden" name="action" value="export">
    <button type="submit"><?= te('video_label.workspace.export') ?></button>
  </form>
  <form method="post" class="form-inline">
    <input type="hidden" name="action" value="infer">
    <input type="hidden" name="batch" value="1">
    <label><input type="checkbox" name="promote" value="1"> <?= te('video_label.workspace.promote') ?></label>
    <button type="submit"><?= te('video_label.workspace.infer_all') ?></button>
  </form>

  <div class="video-label-layout" id="vl-root" data-workspace="<?= e((string)$workspaceJson) ?>">
    <aside class="video-label-strip" id="vl-strip"></aside>
    <div>
      <div class="toolbar" id="vl-toolbar">
        <button type="button" data-tool="box" class="is-active"><?= te('video_label.tool.box') ?></button>
        <button type="button" data-tool="drag"><?= te('video_label.tool.drag') ?></button>
        <button type="button" data-tool="null"><?= te('video_label.tool.null') ?></button>
        <button type="button" id="vl-undo"><?= te('video_label.tool.undo') ?></button>
        <button type="button" id="vl-save"><?= te('core.common.save') ?></button>
        <form method="post" class="form-inline" id="vl-infer-one">
          <input type="hidden" name="action" value="infer">
          <input type="hidden" name="image_id" id="vl-infer-image-id" value="<?= (int)$currentId ?>">
          <button type="submit"><?= te('video_label.workspace.infer_one') ?></button>
        </form>
      </div>
      <div class="video-label-canvas-wrap">
        <canvas id="vl-canvas" width="800" height="600"></canvas>
      </div>
      <p class="muted" id="vl-hint"><?= te('video_label.workspace.hover_hint') ?></p>
    </div>
    <aside>
      <h3><?= te('video_label.workspace.boxes') ?></h3>
      <ul id="vl-boxlist"></ul>
      <form method="post" id="vl-split-form">
        <input type="hidden" name="action" value="set_split">
        <input type="hidden" name="image_id" id="vl-split-image-id" value="<?= (int)$currentId ?>">
        <select name="split" id="vl-split">
          <option value="train">train</option>
          <option value="val">val</option>
          <option value="test">test</option>
        </select>
        <button type="submit"><?= te('video_label.workspace.set_split') ?></button>
      </form>
    </aside>
  </div>
  <form method="post" id="vl-save-form" style="display:none">
    <input type="hidden" name="action" value="save_labels">
    <input type="hidden" name="image_id" id="vl-save-image-id" value="">
    <input type="hidden" name="labels_json" id="vl-save-labels" value="">
  </form>
</section>
