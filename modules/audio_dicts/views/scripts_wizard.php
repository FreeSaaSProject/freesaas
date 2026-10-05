<?php
/** @var string $error */
/** @var string $path */
/** @var array $meta */
/** @var list<array<string,mixed>> $parts */
/** @var array<int,string> $dictOptions */
/** @var array<int,string> $positionOptions */
/** @var array<string,string> $roleOptions */
/** @var array<string,string> $roleHints */
$partsJson = json_encode(array_values($parts), JSON_UNESCAPED_UNICODE);
$dictJson = json_encode($dictOptions, JSON_UNESCAPED_UNICODE);
$roleJson = json_encode($roleOptions, JSON_UNESCAPED_UNICODE);
$hintJson = json_encode($roleHints, JSON_UNESCAPED_UNICODE);
$i18n = [
    'step1' => t('audio_dicts.scripts.wizard.step1'),
    'step2' => t('audio_dicts.scripts.wizard.step2'),
    'step3' => t('audio_dicts.scripts.wizard.step3'),
    'step4' => t('audio_dicts.scripts.wizard.step4'),
    'step5' => t('audio_dicts.scripts.wizard.step5'),
    'next' => t('audio_dicts.scripts.wizard.next'),
    'back' => t('audio_dicts.scripts.wizard.back'),
    'save' => t('core.common.save'),
    'addBlock' => t('audio_dicts.scripts.btn_add_block'),
    'removeBlock' => t('audio_dicts.scripts.btn_remove_block'),
    'blockName' => t('audio_dicts.col.name'),
    'dicts' => t('audio_dicts.scripts.field.dicts'),
    'role' => t('audio_dicts.scripts.field.role'),
    'score' => t('audio_dicts.col.score_value'),
    'scoreHint' => t('audio_dicts.scripts.field.score_hint'),
    'timeout' => t('audio_dicts.scripts.field.timeout'),
    'timeoutHint' => t('audio_dicts.scripts.field.timeout_hint'),
    'diversity' => t('audio_dicts.scripts.field.diversity'),
    'diversityHint' => t('audio_dicts.scripts.field.diversity_hint'),
    'instMin' => t('audio_dicts.scripts.field.inst_min'),
    'instMinHint' => t('audio_dicts.scripts.field.inst_min_hint'),
    'killer' => t('audio_dicts.scripts.field.killer'),
    'advanced' => t('audio_dicts.scripts.field.advanced'),
    'needName' => t('audio_dicts.err.name_required'),
    'needParts' => t('audio_dicts.scripts.err.parts_required'),
    'needDict' => t('audio_dicts.scripts.err.dicts_required'),
    'previewEmpty' => t('audio_dicts.scripts.preview_empty'),
    'roleCol' => t('audio_dicts.scripts.field.role_col'),
    'roleHintCol' => t('audio_dicts.scripts.field.role_hint_col'),
    'rolesNoneNote' => t('audio_dicts.scripts.field.roles_none'),
    'rolesKillerExclusive' => t('audio_dicts.scripts.field.roles_killer_exclusive'),
];
?>
<?php if ($error !== ''): ?><p class="flash error"><?= e($error) ?></p><?php endif; ?>

<div class="scripts-wizard" id="scripts-wizard"
     data-parts="<?= e($partsJson ?: '[]') ?>"
     data-dicts="<?= e($dictJson ?: '{}') ?>"
     data-roles="<?= e($roleJson ?: '{}') ?>"
     data-hints="<?= e($hintJson ?: '{}') ?>"
     data-i18n="<?= e(json_encode($i18n, JSON_UNESCAPED_UNICODE) ?: '{}') ?>">

  <nav class="scripts-wizard-steps" aria-label="<?= te('audio_dicts.scripts.wizard_title') ?>">
    <button type="button" class="scripts-wizard-step is-active" data-step="1">1. <?= te('audio_dicts.scripts.wizard.step1_short') ?></button>
    <button type="button" class="scripts-wizard-step" data-step="2">2. <?= te('audio_dicts.scripts.wizard.step2_short') ?></button>
    <button type="button" class="scripts-wizard-step" data-step="3">3. <?= te('audio_dicts.scripts.wizard.step3_short') ?></button>
    <button type="button" class="scripts-wizard-step" data-step="4">4. <?= te('audio_dicts.scripts.wizard.step4_short') ?></button>
    <button type="button" class="scripts-wizard-step" data-step="5">5. <?= te('audio_dicts.scripts.wizard.step5_short') ?></button>
  </nav>

  <form method="post" class="form scripts-wizard-form" id="scripts-wizard-form">
    <input type="hidden" name="parts_json" id="scripts-parts-json" value="">

    <section class="scripts-wizard-panel is-active" data-panel="1">
      <h2><?= te('audio_dicts.scripts.wizard.step1') ?></h2>
      <label><?= te('audio_dicts.col.name') ?>
        <input name="name" id="script-name" required maxlength="40" value="<?= e((string)($meta['name'] ?? '')) ?>">
      </label>
      <fieldset class="scripts-checkgrid">
        <legend><?= te('audio_dicts.scripts.field.positions') ?></legend>
        <p class="muted small"><?= te('audio_dicts.scripts.field.positions_hint') ?></p>
        <?php
        $selectedPos = is_array($meta['position_ids'] ?? null) ? $meta['position_ids'] : [];
        foreach ($positionOptions as $sid => $sname):
            $checked = in_array((int)$sid, array_map('intval', $selectedPos), true);
        ?>
        <label class="logo-remove"><input type="checkbox" name="positions[]" value="<?= (int)$sid ?>"<?= $checked ? ' checked' : '' ?>> <?= e((string)$sname) ?></label>
        <?php endforeach; ?>
        <?php if (!$positionOptions): ?><p class="muted"><?= te('audio_dicts.scripts.no_positions') ?></p><?php endif; ?>
      </fieldset>
      <label class="logo-remove"><input type="checkbox" name="disabled" value="1"<?= !empty($meta['disabled']) ? ' checked' : '' ?>> <?= te('audio_dicts.col.disabled') ?></label>
    </section>

    <section class="scripts-wizard-panel" data-panel="2" hidden>
      <h2><?= te('audio_dicts.scripts.wizard.step2') ?></h2>
      <p class="muted"><?= te('audio_dicts.scripts.wizard.step2_hint') ?></p>
      <div id="wizard-blocks-list" class="scripts-blocks-edit"></div>
      <button type="button" class="btn-secondary" id="wizard-add-block"><?= te('audio_dicts.scripts.btn_add_block') ?></button>
    </section>

    <section class="scripts-wizard-panel" data-panel="3" hidden>
      <h2><?= te('audio_dicts.scripts.wizard.step3') ?></h2>
      <p class="muted"><?= te('audio_dicts.scripts.wizard.step3_hint') ?></p>
      <div id="wizard-roles-list" class="scripts-roles-edit"></div>
    </section>

    <section class="scripts-wizard-panel" data-panel="4" hidden>
      <h2><?= te('audio_dicts.scripts.wizard.step4') ?></h2>
      <p class="muted"><?= te('audio_dicts.scripts.wizard.step4_hint') ?></p>
      <div id="wizard-thresholds-list" class="scripts-thresholds-edit"></div>
    </section>

    <section class="scripts-wizard-panel" data-panel="5" hidden>
      <h2><?= te('audio_dicts.scripts.wizard.step5') ?></h2>
      <div id="wizard-preview" class="scripts-flow scripts-flow--preview"></div>
    </section>

    <div class="scripts-wizard-nav form-actions">
      <button type="button" class="btn-secondary" id="wizard-back" hidden><?= te('audio_dicts.scripts.wizard.back') ?></button>
      <button type="button" class="btn-primary" id="wizard-next"><?= te('audio_dicts.scripts.wizard.next') ?></button>
      <button type="submit" class="btn-primary" id="wizard-save" hidden><?= te('core.common.save') ?></button>
      <a href="<?= e($path) ?>"><?= te('core.common.cancel') ?></a>
    </div>
  </form>
</div>
