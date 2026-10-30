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
/** @var array<int,list<int>> $dictPositionMap */
/** @var array<int,string> $positionOptions */
/** @var array<string,string> $roleOptions */
/** @var array<string,string> $roleHints */
/** @var string $partsUrl */
$partsJson = json_encode(array_values($parts), JSON_UNESCAPED_UNICODE);
$dictJson = json_encode($dictOptions, JSON_UNESCAPED_UNICODE);
$dictPosJson = json_encode($dictPositionMap ?? [], JSON_UNESCAPED_UNICODE);
$roleJson = json_encode($roleOptions, JSON_UNESCAPED_UNICODE);
$hintJson = json_encode($roleHints, JSON_UNESCAPED_UNICODE);
$i18n = [
    'main' => t('audio_dicts.edit.sec_main'),
    'role' => t('audio_dicts.scripts.field.role'),
    'dicts' => t('audio_dicts.scripts.field.dicts'),
    'activator' => t('audio_dicts.scripts.sec.activator'),
    'activatorField' => t('audio_dicts.scripts.field.activator'),
    'activatorHint' => t('audio_dicts.scripts.field.activator_hint'),
    'thresholds' => t('audio_dicts.scripts.sec.thresholds'),
    'dangerous' => t('audio_dicts.scripts.sec.dangerous'),
    'name' => t('audio_dicts.col.name'),
    'score' => t('audio_dicts.col.score_value'),
    'scoreHint' => t('audio_dicts.scripts.field.score_hint'),
    'scoreRate' => t('audio_dicts.scripts.field.score_rate'),
    'scoreRateHint' => t('audio_dicts.scripts.field.score_rate_hint'),
    'kpi' => t('audio_dicts.scripts.field.kpi'),
    'kpiHint' => t('audio_dicts.scripts.field.kpi_hint'),
    'condType' => t('audio_dicts.scripts.field.cond_type'),
    'condTypeHint' => t('audio_dicts.scripts.field.cond_type_hint'),
    'condValue' => t('audio_dicts.scripts.field.cond_value'),
    'startGate' => t('audio_dicts.scripts.field.start_gate'),
    'startGateHint' => t('audio_dicts.scripts.field.start_gate_hint'),
    'startGate0' => t('audio_dicts.scripts.start_gate.0'),
    'startGate1' => t('audio_dicts.scripts.start_gate.1'),
    'startGate2' => t('audio_dicts.scripts.start_gate.2'),
    'extendTimeout' => t('audio_dicts.scripts.field.extend_timeout'),
    'extendTimeoutHint' => t('audio_dicts.scripts.field.extend_timeout_hint'),
    'condNone' => t('audio_dicts.scripts.cond.none'),
    'condIntersect' => t('audio_dicts.scripts.cond.intersect'),
    'condNoIntersect' => t('audio_dicts.scripts.cond.no_intersect'),
    'condVolumeGt' => t('audio_dicts.scripts.cond.volume_gt'),
    'condVolumeLt' => t('audio_dicts.scripts.cond.volume_lt'),
    'condStringsGt' => t('audio_dicts.scripts.cond.strings_gt'),
    'condStringsLt' => t('audio_dicts.scripts.cond.strings_lt'),
    'condTermScanUnique' => t('audio_dicts.scripts.cond.term_scan_unique'),
    'condTermScanConversionHint' => t('audio_dicts.scripts.cond.term_scan_unique_hint'),
    'condProcCreated' => t('audio_dicts.scripts.cond.proc_created'),
    'condProcCreatedHint' => t('audio_dicts.scripts.cond.proc_created_hint'),
    'procTypes' => t('audio_dicts.scripts.field.proc_types'),
    'procTypesHint' => t('audio_dicts.scripts.field.proc_types_hint'),
    'procWindow' => t('audio_dicts.scripts.field.proc_window'),
    'procWindowHint' => t('audio_dicts.scripts.field.proc_window_hint'),
    'needProcTypes' => t('audio_dicts.scripts.err.need_proc_types'),
    'scanWindow' => t('audio_dicts.scripts.field.scan_window'),
    'scanWindowHint' => t('audio_dicts.scripts.field.scan_window_hint'),
    'gateRedMaxHintScan' => t('audio_dicts.scripts.field.gate_red_max_hint_scan'),
    'timeout' => t('audio_dicts.scripts.field.timeout'),
    'timeoutHint' => t('audio_dicts.scripts.field.timeout_hint'),
    'breakGap' => t('audio_dicts.scripts.field.break_gap'),
    'breakGapHint' => t('audio_dicts.scripts.field.break_gap_hint'),
    'timeoutFlow' => t('audio_dicts.scripts.flow.timeout'),
    'timeoutNoExtend' => t('audio_dicts.scripts.flow.timeout_no_extend'),
    'kpiFlow' => t('audio_dicts.scripts.flow.kpi'),
    'gateRedFlow' => t('audio_dicts.scripts.flow.gate_red'),
    'dictShared' => t('audio_dicts.scripts.dict_shared'),
    'timeoutsSec' => t('audio_dicts.scripts.sec.timeouts'),
    'resultsSec' => t('audio_dicts.scripts.sec.results'),
    'diversity' => t('audio_dicts.scripts.field.diversity'),
    'diversityHint' => t('audio_dicts.scripts.field.diversity_hint'),
    'instMin' => t('audio_dicts.scripts.field.inst_min'),
    'instMinHint' => t('audio_dicts.scripts.field.inst_min_hint'),
    'gateRedMax' => t('audio_dicts.scripts.field.gate_red_max'),
    'gateRedMaxHint' => t('audio_dicts.scripts.field.gate_red_max_hint'),
    'gateRedMaxScan' => t('audio_dicts.scripts.field.gate_red_max_scan'),
    'gateYellowMax' => t('audio_dicts.scripts.field.gate_yellow_max'),
    'gateYellowMaxHint' => t('audio_dicts.scripts.field.gate_yellow_max_hint'),
    'gateYellowMaxScan' => t('audio_dicts.scripts.field.gate_yellow_max_scan'),
    'gateYellowMaxHintScan' => t('audio_dicts.scripts.field.gate_yellow_max_hint_scan'),
    'hideFromReports' => t('audio_dicts.scripts.field.hide_from_reports'),
    'hideFromReportsHint' => t('audio_dicts.scripts.field.hide_from_reports_hint'),
    'qualitySec' => t('audio_dicts.scripts.sec.quality_part'),
    'killer' => t('audio_dicts.scripts.field.killer'),
    'killerHint' => t('audio_dicts.scripts.field.killer_hint'),
    'ratingSec' => t('audio_dicts.scripts.sec.rating_part'),
    'addBlock' => t('audio_dicts.scripts.btn_add_block'),
    'removeBlock' => t('audio_dicts.scripts.btn_remove_block'),
    'savePart' => t('core.common.save'),
    'selectBlock' => t('audio_dicts.scripts.canvas.select_block'),
    'empty' => t('audio_dicts.scripts.canvas.empty'),
    'confirmDelete' => t('audio_dicts.scripts.confirm_delete_part'),
    'moveUp' => t('audio_dicts.scripts.btn_up'),
    'moveDown' => t('audio_dicts.scripts.btn_down'),
    'moveLeft' => t('audio_dicts.scripts.btn_left'),
    'moveRight' => t('audio_dicts.scripts.btn_right'),
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
<?php
$importWarnings = is_array($importWarnings ?? null) ? $importWarnings : [];
if ($importWarnings !== []): ?>
  <ul class="flash warn">
    <?php foreach ($importWarnings as $w): ?>
      <li><?= e((string)$w) ?></li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
<?php if ($error !== ''): ?><p class="flash error"><?= e($error) ?></p><?php endif; ?>

<div class="scripts-canvas<?= $canEdit ? '' : ' is-readonly' ?>"
     id="scripts-canvas"
     data-parts="<?= e($partsJson ?: '[]') ?>"
     data-dicts="<?= e($dictJson ?: '{}') ?>"
     data-dict-positions="<?= e($dictPosJson ?: '{}') ?>"
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
        <div class="scripts-canvas-meta-grid">
          <div class="scripts-canvas-meta-main">
            <label><?= te('audio_dicts.col.name') ?>
              <input name="name" required maxlength="40" value="<?= e((string)$root['name']) ?>"<?= $canEdit ? '' : ' readonly' ?>>
            </label>
            <label class="logo-remove"><input type="checkbox" name="disabled" value="1"<?= !empty($root['disabled']) ? ' checked' : '' ?><?= $canEdit ? '' : ' disabled' ?>> <?= te('audio_dicts.col.disabled') ?></label>
            <fieldset class="scripts-checkgrid">
              <legend><?= te('audio_dicts.scripts.field.positions') ?></legend>
              <p class="muted small"><?= te('audio_dicts.scripts.field.positions_hint') ?></p>
              <?php foreach ($positionOptions as $sid => $sname):
                  $checked = in_array((int)$sid, array_map('intval', $selectedPos), true);
              ?>
              <label class="logo-remove"><input type="checkbox" name="positions[]" value="<?= (int)$sid ?>"<?= $checked ? ' checked' : '' ?><?= $canEdit ? '' : ' disabled' ?>> <?= e((string)$sname) ?></label>
              <?php endforeach; ?>
            </fieldset>
          </div>
          <fieldset class="scripts-rating-flags">
            <legend><?= te('audio_dicts.scripts.sec.rating') ?></legend>
            <label class="logo-remove"><input type="checkbox" name="rating_enabled" value="1"<?= !empty($root['rating_enabled']) ? ' checked' : '' ?><?= $canEdit ? '' : ' disabled' ?>> <?= te('audio_dicts.scripts.field.rating_enabled') ?></label>
            <p class="muted small"><?= te('audio_dicts.scripts.field.rating_enabled_hint') ?></p>
            <label class="logo-remove"><input type="checkbox" name="is_hidden" value="1"<?= !empty($root['is_hidden']) ? ' checked' : '' ?><?= $canEdit ? '' : ' disabled' ?>> <?= te('audio_dicts.col.hidden') ?></label>
            <p class="muted small"><?= te('audio_dicts.scripts.field.hidden_hint') ?></p>
          </fieldset>
          <fieldset class="scripts-rating-flags">
            <legend><?= te('audio_dicts.scripts.sec.day_quality') ?></legend>
            <label class="logo-remove"><input type="checkbox" name="day_bonus" id="script-day-bonus" value="1"<?= !empty($root['day_bonus']) ? ' checked' : '' ?><?= $canEdit ? '' : ' disabled' ?>> <?= te('audio_dicts.scripts.field.day_bonus') ?></label>
            <p class="muted small"><?= te('audio_dicts.scripts.field.day_bonus_hint') ?></p>
            <div id="script-day-bonus-fields"<?= empty($root['day_bonus']) ? ' hidden' : '' ?>>
              <label><?= te('audio_dicts.scripts.field.day_bonus_sum') ?>
                <input type="number" min="0" name="day_bonus_sum" value="<?= (int)($root['day_bonus_sum'] ?? 0) ?>"<?= $canEdit ? '' : ' readonly' ?>>
              </label>
              <p class="muted small"><?= te('audio_dicts.scripts.field.day_bonus_sum_hint') ?></p>
              <div class="scripts-day-gates" id="script-day-gates"
                   data-can-edit="<?= $canEdit ? '1' : '0' ?>">
                <p class="muted small"><?= te('audio_dicts.scripts.field.day_gates_hint') ?></p>
                <table class="scripts-day-gates-table">
                  <thead>
                    <tr>
                      <th><?= te('audio_dicts.scripts.field.day_gate_metric') ?></th>
                      <th><?= te('audio_dicts.scripts.field.day_gate_op') ?></th>
                      <th><?= te('audio_dicts.scripts.field.day_gate_threshold') ?></th>
                      <th><?= te('audio_dicts.scripts.field.day_gate_label') ?></th>
                      <?php if ($canEdit): ?><th></th><?php endif; ?>
                    </tr>
                  </thead>
                  <tbody id="script-day-gates-body">
                  <?php
                  $gates = is_array($root['day_gates'] ?? null) ? $root['day_gates'] : [];
                  $metricOpts = is_array($dayMetricCatalog ?? null) ? $dayMetricCatalog : [];
                  if ($gates === []) {
                      $gates = [['metric' => '', 'op' => 'gte', 'threshold' => 0, 'label' => '']];
                  }
                  foreach ($gates as $gi => $gate):
                      $gMetric = (string)($gate['metric'] ?? '');
                      $gOp = (string)($gate['op'] ?? 'gte');
                      $gThr = $gate['threshold'] ?? 0;
                      $gLabel = (string)($gate['label'] ?? '');
                  ?>
                    <tr class="scripts-day-gate-row">
                      <td>
                        <select name="day_gates[<?= (int)$gi ?>][metric]"<?= $canEdit ? '' : ' disabled' ?>>
                          <option value=""><?= te('audio_dicts.scripts.field.day_gate_metric_none') ?></option>
                          <?php foreach ($metricOpts as $m): ?>
                            <?php $mid = (string)($m['id'] ?? ''); if ($mid === '') { continue; } ?>
                            <option value="<?= e($mid) ?>"<?= $gMetric === $mid ? ' selected' : '' ?>>
                              <?= e((string)($m['label'] ?? $mid)) ?><?= !empty($m['unit']) ? ' (' . e((string)$m['unit']) . ')' : '' ?>
                            </option>
                          <?php endforeach; ?>
                          <?php if ($gMetric !== '' && !in_array($gMetric, array_column($metricOpts, 'id'), true)): ?>
                            <option value="<?= e($gMetric) ?>" selected><?= e($gMetric) ?></option>
                          <?php endif; ?>
                        </select>
                      </td>
                      <td>
                        <select name="day_gates[<?= (int)$gi ?>][op]"<?= $canEdit ? '' : ' disabled' ?>>
                          <?php
                          $gateOps = class_exists('DayQualityMetrics', false)
                              ? DayQualityMetrics::ops()
                              : ['gt', 'gte', 'lt', 'lte'];
                          foreach ($gateOps as $op):
                              $opLabel = class_exists('DayQualityMetrics', false)
                                  ? DayQualityMetrics::opSymbol($op)
                                  : $op;
                          ?>
                            <option value="<?= e($op) ?>"<?= $gOp === $op ? ' selected' : '' ?>><?= e($opLabel) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </td>
                      <td>
                        <input type="number" step="any" name="day_gates[<?= (int)$gi ?>][threshold]"
                               value="<?= e((string)$gThr) ?>"<?= $canEdit ? '' : ' readonly' ?>>
                      </td>
                      <td>
                        <input type="text" name="day_gates[<?= (int)$gi ?>][label]" maxlength="80"
                               value="<?= e($gLabel) ?>"<?= $canEdit ? '' : ' readonly' ?>>
                      </td>
                      <?php if ($canEdit): ?>
                      <td><button type="button" class="btn-secondary btn-sm scripts-day-gate-remove" title="<?= te('core.bulk.delete') ?>">×</button></td>
                      <?php endif; ?>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
                <?php if ($canEdit): ?>
                <button type="button" class="btn-secondary btn-sm" id="script-day-gates-add"><?= te('audio_dicts.scripts.btn_add_day_gate') ?></button>
                <?php endif; ?>
              </div>
            </div>
          </fieldset>
        </div>
      </aside>

      <section class="scripts-canvas-flow-wrap">
        <div class="scripts-canvas-flow-head">
          <h2><?= te('audio_dicts.scripts.sec.flow') ?></h2>
          <?php if ($canEdit): ?>
          <button type="button" class="btn-secondary btn-sm" id="canvas-add-block"><?= te('audio_dicts.scripts.btn_add_block') ?></button>
          <?php endif; ?>
        </div>
        <div id="canvas-flow" class="scripts-flow scripts-flow--horizontal" aria-live="polite"></div>
      </section>

      <aside class="scripts-canvas-panel" id="canvas-side-panel">
        <h2><?= te('audio_dicts.scripts.sec.block') ?></h2>
        <p class="muted" id="canvas-panel-empty"><?= te('audio_dicts.scripts.canvas.select_block') ?></p>
        <div id="canvas-panel-body" class="scripts-canvas-panel-body" hidden></div>
      </aside>

      <div class="scripts-canvas-footer form-actions">
        <?php if ($canEdit): ?>
        <button type="submit" class="btn-primary" name="action" value="save"><?= te('core.common.save') ?></button>
        <?php endif; ?>
        <span class="scripts-download-wrap">
          <a class="btn-secondary" href="<?= e($path) ?>/<?= (int)$id ?>/download"
             data-scripts-download="0"
             data-confirm="<?= e(t('audio_dicts.scripts.pack.confirm_download')) ?>"><?= te('audio_dicts.scripts.btn_download') ?></a>
          <a class="btn-secondary" href="<?= e($path) ?>/<?= (int)$id ?>/download?keys=1"
             data-scripts-download="1"
             data-confirm="<?= e(t('audio_dicts.scripts.pack.confirm_download_keys')) ?>"><?= te('audio_dicts.scripts.btn_download_keys') ?></a>
        </span>
        <a href="<?= e($path) ?>"><?= te('core.common.cancel') ?></a>
      </div>
    </div>
  </form>
  <?= EntityDelete::form('audio_scripts', (int)$id) ?>
</div>
