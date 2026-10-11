<?php
declare(strict_types=1);
/** @var string $error */
/** @var string $flash */
/** @var string $path */
/** @var int $id */
/** @var array $root */
/** @var list<array<string,mixed>> $parts */
/** @var bool $canEdit */
/** @var array<int,string> $dictOptions */
/** @var array<int,string> $positionOptions */
/** @var array<string,string> $roleOptions */
/** @var array<string,string> $roleHints */
/** @var string $partsUrl */
$partsJson = json_encode(array_values($parts), JSON_UNESCAPED_UNICODE);
$dictJson = json_encode($dictOptions, JSON_UNESCAPED_UNICODE);
$roleJson = json_encode($roleOptions, JSON_UNESCAPED_UNICODE);
$hintJson = json_encode($roleHints, JSON_UNESCAPED_UNICODE);
$i18n = [
    'main' => t('audio_dicts.edit.sec_main'),
    'role' => t('audio_dicts.scripts.field.role'),
    'dicts' => t('audio_dicts.scripts.field.dicts'),
    'thresholds' => t('audio_dicts.scripts.sec.thresholds'),
    'dangerous' => t('audio_dicts.scripts.sec.dangerous'),
    'name' => t('audio_dicts.col.name'),
    'score' => t('audio_dicts.col.score_value'),
    'scoreHint' => t('audio_dicts.scripts.field.score_hint'),
    'timeout' => t('audio_dicts.scripts.field.timeout'),
    'timeoutHint' => t('audio_dicts.scripts.field.timeout_hint'),
    'diversity' => t('audio_dicts.scripts.field.diversity'),
    'diversityHint' => t('audio_dicts.scripts.field.diversity_hint'),
    'instMin' => t('audio_dicts.scripts.field.inst_min'),
    'instMinHint' => t('audio_dicts.scripts.field.inst_min_hint'),
    'killer' => t('audio_dicts.scripts.field.killer'),
    'killerHint' => t('audio_dicts.scripts.field.killer_hint'),
    'addBlock' => t('audio_dicts.scripts.btn_add_block'),
    'removeBlock' => t('audio_dicts.scripts.btn_remove_block'),
    'savePart' => t('core.common.save'),
    'selectBlock' => t('audio_dicts.scripts.canvas.select_block'),
    'empty' => t('audio_dicts.scripts.canvas.empty'),
    'confirmDelete' => t('audio_dicts.scripts.confirm_delete_part'),
    'moveUp' => t('audio_dicts.scripts.btn_up'),
    'moveDown' => t('audio_dicts.scripts.btn_down'),
    'needName' => t('audio_dicts.err.name_required'),
    'saved' => t('audio_dicts.scripts.saved'),
    'error' => t('audio_dicts.scripts.err.save_failed'),
    'roleCol' => t('audio_dicts.scripts.field.role_col'),
    'roleHintCol' => t('audio_dicts.scripts.field.role_hint_col'),
    'rolesNoneNote' => t('audio_dicts.scripts.field.roles_none'),
    'rolesKillerExclusive' => t('audio_dicts.scripts.field.roles_killer_exclusive'),
];
$selectedPos = is_array($root['position_ids'] ?? null) ? $root['position_ids'] : [];
?>
<?php if ($flash !== ''): ?><p class="flash success"><?= e($flash) ?></p><?php endif; ?>
<?php if ($error !== ''): ?><p class="flash error"><?= e($error) ?></p><?php endif; ?>

<div class="scripts-canvas<?= $canEdit ? '' : ' is-readonly' ?>"
     id="scripts-canvas"
     data-parts="<?= e($partsJson ?: '[]') ?>"
     data-dicts="<?= e($dictJson ?: '{}') ?>"
     data-roles="<?= e($roleJson ?: '{}') ?>"
     data-hints="<?= e($hintJson ?: '{}') ?>"
     data-parts-url="<?= e($partsUrl) ?>"
     data-can-edit="<?= $canEdit ? '1' : '0' ?>"
     data-i18n="<?= e(json_encode($i18n, JSON_UNESCAPED_UNICODE) ?: '{}') ?>">

  <form method="post" class="form scripts-canvas-form" id="scripts-canvas-form">
    <input type="hidden" name="parts_json" id="canvas-parts-json" value="">

    <div class="scripts-canvas-layout">
      <aside class="scripts-canvas-meta">
        <h2><?= te('audio_dicts.scripts.sec.script') ?></h2>
        <label><?= te('audio_dicts.col.name') ?>
          <input name="name" required maxlength="40" value="<?= e((string)$root['name']) ?>"<?= $canEdit ? '' : ' readonly' ?>>
        </label>
        <fieldset class="scripts-checkgrid">
          <legend><?= te('audio_dicts.scripts.field.positions') ?></legend>
          <p class="muted small"><?= te('audio_dicts.scripts.field.positions_hint') ?></p>
          <?php foreach ($positionOptions as $sid => $sname):
              $checked = in_array((int)$sid, array_map('intval', $selectedPos), true);
          ?>
          <label class="logo-remove"><input type="checkbox" name="positions[]" value="<?= (int)$sid ?>"<?= $checked ? ' checked' : '' ?><?= $canEdit ? '' : ' disabled' ?>> <?= e((string)$sname) ?></label>
          <?php endforeach; ?>
        </fieldset>
        <label class="logo-remove"><input type="checkbox" name="disabled" value="1"<?= !empty($root['disabled']) ? ' checked' : '' ?><?= $canEdit ? '' : ' disabled' ?>> <?= te('audio_dicts.col.disabled') ?></label>
        <?php if ($canEdit): ?>
        <div class="form-actions scripts-canvas-meta-actions">
          <button type="submit" class="btn-primary" name="action" value="save"><?= te('core.common.save') ?></button>
          <button type="submit" class="btn-secondary" name="action" value="copy"><?= te('audio_dicts.scripts.btn_copy') ?></button>
          <a href="<?= e($path) ?>"><?= te('core.common.cancel') ?></a>
        </div>
        <?php else: ?>
        <div class="form-actions"><a href="<?= e($path) ?>"><?= te('core.common.cancel') ?></a></div>
        <?php endif; ?>
      </aside>

      <section class="scripts-canvas-flow-wrap">
        <div class="scripts-canvas-flow-head">
          <h2><?= te('audio_dicts.scripts.sec.flow') ?></h2>
          <?php if ($canEdit): ?>
          <button type="button" class="btn-secondary btn-sm" id="canvas-add-block"><?= te('audio_dicts.scripts.btn_add_block') ?></button>
          <?php endif; ?>
        </div>
        <div id="canvas-flow" class="scripts-flow" aria-live="polite"></div>
      </section>

      <aside class="scripts-canvas-panel" id="canvas-side-panel">
        <h2><?= te('audio_dicts.scripts.sec.block') ?></h2>
        <p class="muted" id="canvas-panel-empty"><?= te('audio_dicts.scripts.canvas.select_block') ?></p>
        <div id="canvas-panel-body" hidden></div>
      </aside>
    </div>
  </form>
</div>
