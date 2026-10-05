<?php
/** @var list<array{id:string,title:string,module:string,enabled:bool,settings:array,settings_schema:array}> $layout */
/** @var list<array{path:string,title:string,group:string}> $menuLayout */
/** @var bool $railPeek */
/** @var string $message */
/** @var string $error */
$menuLayout = $menuLayout ?? [];
$railPeek = !empty($railPeek);
?>
<section class="page-head">
  <p class="lede"><?= te('core.dashboard.lede') ?></p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="dashboard-layout-form">
  <h2 class="form-section-title"><?= te('core.dashboard.sec_widgets') ?></h2>
  <p class="muted dashboard-hint"><?= te('core.dashboard.drag_hint') ?></p>

  <?php if (!$layout): ?>
    <div class="flash flash-warn"><?= te('core.dashboard.no_widgets') ?></div>
  <?php else: ?>
  <ul class="dashboard-layout-list" data-sortable="widgets">
    <?php foreach ($layout as $item): ?>
      <li class="dashboard-layout-item">
        <span class="dashboard-drag-handle" draggable="true" title="<?= te('core.dashboard.drag_handle') ?>" aria-hidden="true">⠿</span>
        <input type="hidden" name="order[]" value="<?= e($item['id']) ?>">
        <div class="dashboard-layout-main">
          <label class="dashboard-layout-check">
            <input type="checkbox" name="enabled[<?= e($item['id']) ?>]" value="1"<?= !empty($item['enabled']) ? ' checked' : '' ?>>
            <span class="dashboard-layout-title"><?= e($item['title']) ?></span>
            <?php if ($item['module'] !== ''): ?>
              <span class="muted dashboard-layout-mod"><?= e($item['module']) ?></span>
            <?php endif; ?>
          </label>
          <?php if (!empty($item['settings_schema'])): ?>
            <div class="dashboard-layout-settings">
              <?php foreach ($item['settings_schema'] as $key => $def): ?>
                <?php
                  $val = $item['settings'][$key] ?? ($def['default'] ?? '');
                  $label = t((string)($def['label'] ?? $key));
                ?>
                <label class="dashboard-setting">
                  <span><?= e($label) ?></span>
                  <input type="number"
                         name="settings[<?= e($item['id']) ?>][<?= e((string)$key) ?>]"
                         value="<?= (int)$val ?>"
                         min="<?= (int)($def['min'] ?? 1) ?>"
                         max="<?= (int)($def['max'] ?? 100) ?>"
                         step="1">
                </label>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>

  <h2 class="form-section-title"><?= te('core.dashboard.sec_menu') ?></h2>
  <p class="muted dashboard-hint"><?= te('core.dashboard.menu_hint') ?></p>

  <label class="dashboard-rail-peek">
    <input type="checkbox" name="rail_peek" value="1"<?= $railPeek ? ' checked' : '' ?>>
    <span><?= te('core.dashboard.rail_peek') ?></span>
  </label>
  <p class="muted dashboard-hint"><?= te('core.dashboard.rail_peek_hint') ?></p>

  <?php if (!$menuLayout): ?>
    <div class="flash flash-warn"><?= te('core.dashboard.no_menu') ?></div>
  <?php else: ?>
  <ul class="dashboard-layout-list" data-sortable="menu">
    <?php foreach ($menuLayout as $item): ?>
      <li class="dashboard-layout-item">
        <span class="dashboard-drag-handle" draggable="true" title="<?= te('core.dashboard.drag_handle') ?>" aria-hidden="true">⠿</span>
        <input type="hidden" name="menu_order[]" value="<?= e($item['path']) ?>">
        <div class="dashboard-layout-main">
          <span class="dashboard-layout-title"><?= e($item['title']) ?></span>
          <span class="muted dashboard-layout-mod"><?= e($item['group']) ?></span>
        </div>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>

  <div class="form-actions">
    <button class="btn-primary" type="submit" name="save" value="1"><?= te('core.common.save') ?></button>
    <button class="btn-secondary" type="submit" name="reset_defaults" value="1"
            onclick="return confirm(<?= tj('core.dashboard.reset_confirm') ?>)"><?= te('core.dashboard.reset') ?></button>
    <a class="btn-secondary" href="/"><?= te('core.dashboard.back_home') ?></a>
  </div>
</form>
