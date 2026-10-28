<?php declare(strict_types=1); ?>
<section class="video-label-workspace">
  <div
    class="video-label-layout"
    id="vl-root"
    data-workspace="<?= e((string)$workspaceJson) ?>"
    data-project-id="<?= (int)$project['ID'] ?>"
    data-i18n-promote="<?= e(t('video_label.workspace.promote')) ?>"
    data-i18n-delete="<?= e(t('core.common.delete')) ?>"
    data-i18n-hidden="<?= e(t('video_label.workspace.preds_hidden')) ?>"
    data-i18n-shown="<?= e(t('video_label.workspace.preds_shown')) ?>"
    data-i18n-nopreds="<?= e(t('video_label.workspace.preds_empty')) ?>"
    data-i18n-marked="<?= e(t('video_label.workspace.marked')) ?>"
    data-i18n-filter-all="<?= e(t('video_label.filter.all')) ?>"
    data-i18n-filter-unlabeled="<?= e(t('video_label.filter.unlabeled')) ?>"
    data-i18n-filter-labeled="<?= e(t('video_label.filter.labeled')) ?>"
    data-i18n-filter-cache="<?= e(t('video_label.filter.cache')) ?>"
    data-i18n-filter-train="<?= e(t('video_label.filter.train')) ?>"
    data-i18n-filter-val="<?= e(t('video_label.filter.val')) ?>"
    data-i18n-filter-test="<?= e(t('video_label.filter.test')) ?>"
  >
    <aside class="video-label-strip-col">
      <div class="video-label-strip-tools" id="vl-strip-tools">
        <label class="video-label-file-btn btn-ghost btn-sm" title="<?= te('video_label.workspace.upload') ?>">
          +
          <input type="file" name="images[]" accept="image/*" multiple hidden form="vl-upload-form" id="vl-file-images">
        </label>
        <label class="video-label-file-btn btn-ghost btn-sm" title="<?= te('video_label.workspace.ingest_zip') ?>">
          zip
          <input type="file" name="zip" accept=".zip,application/zip" hidden form="vl-zip-form" id="vl-file-zip">
        </label>
        <button type="button" class="btn-ghost btn-sm" id="vl-mark-all" title="<?= te('video_label.workspace.mark_all') ?>">☑</button>
        <button type="button" class="btn-ghost btn-sm" id="vl-mark-clear" title="<?= te('video_label.workspace.mark_clear') ?>">☐</button>
        <select id="vl-filter" class="video-label-filter" aria-label="<?= te('video_label.workspace.filter') ?>"></select>
      </div>
      <div class="video-label-bulk" id="vl-bulk" hidden>
        <span class="muted" id="vl-bulk-count"></span>
        <select id="vl-bulk-split">
          <option value="train">train</option>
          <option value="val">val</option>
          <option value="test">test</option>
        </select>
        <button type="button" class="btn-secondary btn-sm" id="vl-bulk-split-btn"><?= te('video_label.workspace.set_split') ?></button>
        <button type="button" class="btn-secondary btn-sm" id="vl-bulk-auto-split" title="<?= te('video_label.workspace.auto_split_help') ?>"><?= te('video_label.workspace.auto_split') ?></button>
        <button type="button" class="btn-ghost btn-sm" id="vl-bulk-clear-labels"><?= te('video_label.workspace.bulk_clear_labels') ?></button>
        <button type="button" class="btn-ghost btn-sm" id="vl-bulk-clear-preds"><?= te('video_label.workspace.clear_preds') ?></button>
        <button type="button" class="btn-danger btn-sm" id="vl-bulk-delete"><?= te('core.common.delete') ?></button>
      </div>
      <div class="video-label-strip" id="vl-strip" aria-label="<?= te('video_label.workspace.strip') ?>"></div>
    </aside>

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
        <form method="post" class="form-inline">
          <input type="hidden" name="action" value="infer">
          <input type="hidden" name="batch" value="1">
          <label class="video-label-check"><input type="checkbox" name="promote" value="1"> <?= te('video_label.workspace.promote') ?></label>
          <button type="submit" class="btn-secondary btn-sm"><?= te('video_label.workspace.infer_all') ?></button>
        </form>
        <form method="post" class="form-inline">
          <input type="hidden" name="action" value="export">
          <button type="submit" class="btn-ghost btn-sm"><?= te('video_label.workspace.export') ?></button>
        </form>
        <a class="btn-ghost btn-sm" href="/video-label/<?= (int)$project['ID'] ?>/edit"><?= te('core.common.edit') ?></a>
        <span class="spacer"></span>
        <div class="video-label-zoom" id="vl-zoom-bar">
          <button type="button" class="btn-ghost btn-sm" id="vl-zoom-out" title="-">−</button>
          <button type="button" class="btn-ghost btn-sm" id="vl-zoom-fit" title="Fit">100%</button>
          <button type="button" class="btn-ghost btn-sm" id="vl-zoom-in" title="+">+</button>
        </div>
      </div>
      <div class="video-label-canvas-wrap" id="vl-canvas-wrap">
        <div class="video-label-canvas-stage" id="vl-stage">
          <canvas id="vl-canvas" width="800" height="600"></canvas>
        </div>
      </div>
      <p class="muted video-label-hint" id="vl-hint"><?= te('video_label.workspace.hover_hint') ?></p>
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
  <form method="post" enctype="multipart/form-data" id="vl-upload-form" style="display:none">
    <input type="hidden" name="action" value="upload">
  </form>
  <form method="post" enctype="multipart/form-data" id="vl-zip-form" style="display:none">
    <input type="hidden" name="action" value="ingest_zip">
  </form>
  <form method="post" id="vl-bulk-form" style="display:none">
    <input type="hidden" name="action" value="bulk">
    <input type="hidden" name="bulk_op" id="vl-bulk-op" value="">
    <input type="hidden" name="split" id="vl-bulk-split-val" value="train">
    <input type="hidden" name="ids" id="vl-bulk-ids" value="">
  </form>
</section>
