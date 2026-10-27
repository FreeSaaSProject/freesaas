<?php declare(strict_types=1); ?>
<section class="video-label-workspace">
  <div class="video-label-topbar actions">
    <a class="btn-secondary btn-sm" href="/video-label/<?= (int)$project['ID'] ?>/edit"><?= te('core.common.edit') ?></a>
    <form method="post" enctype="multipart/form-data" class="form-inline">
      <input type="hidden" name="action" value="upload">
      <input type="file" name="images[]" accept="image/*" multiple required>
      <button type="submit" class="btn-secondary btn-sm"><?= te('video_label.workspace.upload') ?></button>
    </form>
    <form method="post" enctype="multipart/form-data" class="form-inline">
      <input type="hidden" name="action" value="ingest_zip">
      <input type="file" name="zip" accept=".zip,application/zip" required>
      <button type="submit" class="btn-secondary btn-sm"><?= te('video_label.workspace.ingest_zip') ?></button>
    </form>
    <form method="post" class="form-inline">
      <input type="hidden" name="action" value="export">
      <button type="submit" class="btn-secondary btn-sm"><?= te('video_label.workspace.export') ?></button>
    </form>
    <form method="post" class="form-inline">
      <input type="hidden" name="action" value="infer">
      <input type="hidden" name="batch" value="1">
      <label class="video-label-check"><input type="checkbox" name="promote" value="1"> <?= te('video_label.workspace.promote') ?></label>
      <button type="submit" class="btn-primary btn-sm"><?= te('video_label.workspace.infer_all') ?></button>
    </form>
    <form method="post" class="form-inline" onsubmit="return confirm(<?= tj('video_label.workspace.clear_preds_confirm') ?>);">
      <input type="hidden" name="action" value="clear_predictions">
      <button type="submit" class="btn-ghost btn-sm"><?= te('video_label.workspace.clear_preds') ?></button>
    </form>
  </div>
  <p class="muted"><?= te('video_label.workspace.ingest_zip_help') ?></p>

  <div
    class="video-label-layout"
    id="vl-root"
    data-workspace="<?= e((string)$workspaceJson) ?>"
    data-i18n-promote="<?= e(t('video_label.workspace.promote')) ?>"
    data-i18n-delete="<?= e(t('core.common.delete')) ?>"
    data-i18n-hidden="<?= e(t('video_label.workspace.preds_hidden')) ?>"
    data-i18n-shown="<?= e(t('video_label.workspace.preds_shown')) ?>"
    data-i18n-nopreds="<?= e(t('video_label.workspace.preds_empty')) ?>"
  >
    <aside class="video-label-strip" id="vl-strip" aria-label="<?= te('video_label.workspace.strip') ?>"></aside>
    <div class="video-label-main">
      <div class="toolbar video-label-toolbar" id="vl-toolbar" role="toolbar">
        <button type="button" class="btn-secondary btn-sm is-active" data-tool="box"><?= te('video_label.tool.box') ?></button>
        <button type="button" class="btn-secondary btn-sm" data-tool="drag"><?= te('video_label.tool.drag') ?></button>
        <button type="button" class="btn-secondary btn-sm" data-tool="null"><?= te('video_label.tool.null') ?></button>
        <button type="button" class="btn-ghost btn-sm" id="vl-undo"><?= te('video_label.tool.undo') ?></button>
        <button type="button" class="btn-primary btn-sm" id="vl-save"><?= te('core.common.save') ?></button>
        <form method="post" class="form-inline" id="vl-infer-one">
          <input type="hidden" name="action" value="infer">
          <input type="hidden" name="image_id" id="vl-infer-image-id" value="<?= (int)$currentId ?>">
          <button type="submit" class="btn-secondary btn-sm"><?= te('video_label.workspace.infer_one') ?></button>
        </form>
      </div>
      <div class="video-label-canvas-wrap">
        <canvas id="vl-canvas" width="800" height="600"></canvas>
      </div>
      <p class="muted" id="vl-hint"><?= te('video_label.workspace.hover_hint') ?></p>
      <p class="muted" id="vl-pred-status" hidden></p>
    </div>
    <aside class="video-label-rail" id="vl-rail">
      <div class="video-label-rail-head">
        <h3><?= te('video_label.workspace.boxes') ?></h3>
        <span class="muted" id="vl-box-count"></span>
      </div>
      <ul id="vl-boxlist" class="video-label-boxlist"></ul>
      <div class="video-label-rail-preds">
        <h4><?= te('video_label.workspace.preds') ?></h4>
        <p class="muted" id="vl-pred-meta"><?= te('video_label.workspace.preds_empty') ?></p>
        <ul id="vl-predlist" class="video-label-boxlist video-label-predlist"></ul>
      </div>
      <form method="post" id="vl-split-form" class="video-label-split">
        <input type="hidden" name="action" value="set_split">
        <input type="hidden" name="image_id" id="vl-split-image-id" value="<?= (int)$currentId ?>">
        <label>
          <span class="muted">split</span>
          <select name="split" id="vl-split">
            <option value="train">train</option>
            <option value="val">val</option>
            <option value="test">test</option>
          </select>
        </label>
        <button type="submit" class="btn-secondary btn-sm"><?= te('video_label.workspace.set_split') ?></button>
      </form>
    </aside>
  </div>

  <div id="vl-ctx" class="video-label-ctx" hidden role="menu"></div>

  <form method="post" id="vl-save-form" style="display:none">
    <input type="hidden" name="action" value="save_labels">
    <input type="hidden" name="image_id" id="vl-save-image-id" value="">
    <input type="hidden" name="labels_json" id="vl-save-labels" value="">
  </form>
</section>
