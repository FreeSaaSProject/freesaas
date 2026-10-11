<?php
declare(strict_types=1);
/**
 * One computed metric editor row.
 *
 * @var int $index
 * @var array<string,mixed>|null $src
 * @var array<string,string> $fieldOptions
 */
$index = (int)($index ?? 0);
$fieldOptions = is_array($fieldOptions ?? null) ? $fieldOptions : [];
$src = is_array($src ?? null) ? $src : [
    'key' => 'calc_' . $index,
    'title' => '',
    'kind' => 'percent',
    'check_base' => 'percent',
    'a' => '',
    'b' => '',
    'formula' => '',
    'unit' => '%',
    'decimals' => 1,
    'tip' => '',
    'has_target' => 0,
    'target' => '',
    'compare' => 'gte',
    'show_card' => 0,
    'show_detail' => 1,
];
$p = 'computed_metrics[' . $index . ']';
$kind = (string)($src['kind'] ?? 'percent');
$checkBase = (string)($src['check_base'] ?? 'percent');
$aRaw = (string)($src['a'] ?? '');
$bRaw = (string)($src['b'] ?? '');
$compare = (string)($src['compare'] ?? 'gte');
$hasTarget = !empty($src['has_target']);
$target = $hasTarget ? (string)($src['target'] ?? '') : '';
?>
<div class="ar-sum-calc-item" data-index="<?= $index ?>" data-kind="<?= e($kind) ?>">
  <div class="ar-sum-ext-head">
    <div class="ar-sum-ext-num"><?= te('audio_reports.summaries.calc_num', ['n' => (string)($index + 1)]) ?></div>
    <button type="button" class="ar-sum-calc-remove btn-secondary"><?= te('core.common.delete') ?></button>
  </div>
  <input type="hidden" name="<?= e($p) ?>[key]" value="<?= e((string)($src['key'] ?? ('calc_' . $index))) ?>">
  <div class="ar-sum-ext-grid">
    <label class="ar-sum-span2"><?= te('audio_reports.summaries.calc_title') ?>
      <input type="text" name="<?= e($p) ?>[title]" value="<?= e((string)($src['title'] ?? '')) ?>">
    </label>
    <label><?= te('audio_reports.summaries.calc_kind') ?>
      <select name="<?= e($p) ?>[kind]" class="ar-sum-calc-kind">
        <option value="percent"<?= $kind === 'percent' ? ' selected' : '' ?>><?= te('audio_reports.summaries.calc_kind_percent') ?></option>
        <option value="check"<?= $kind === 'check' ? ' selected' : '' ?>><?= te('audio_reports.summaries.calc_kind_check') ?></option>
        <option value="result"<?= $kind === 'result' ? ' selected' : '' ?>><?= te('audio_reports.summaries.calc_kind_result') ?></option>
      </select>
    </label>
    <label class="ar-sum-calc-pane-check"><?= te('audio_reports.summaries.calc_check_base') ?>
      <select name="<?= e($p) ?>[check_base]" class="ar-sum-calc-check-base">
        <option value="percent"<?= $checkBase === 'percent' ? ' selected' : '' ?>><?= te('audio_reports.summaries.calc_base_percent') ?></option>
        <option value="value"<?= $checkBase === 'value' ? ' selected' : '' ?>><?= te('audio_reports.summaries.calc_base_value') ?></option>
      </select>
    </label>
    <label class="ar-sum-calc-pane-ab"><?= te('audio_reports.summaries.calc_a') ?>
      <select name="<?= e($p) ?>[a]">
        <option value=""><?= te('audio_reports.summaries.select_empty') ?></option>
        <?php foreach ($fieldOptions as $ref => $lab): ?>
          <option value="<?= e((string)$ref) ?>"<?= $aRaw === (string)$ref ? ' selected' : '' ?>><?= e((string)$lab) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="ar-sum-calc-pane-b"><?= te('audio_reports.summaries.calc_b') ?>
      <select name="<?= e($p) ?>[b]">
        <option value=""><?= te('audio_reports.summaries.select_empty') ?></option>
        <?php foreach ($fieldOptions as $ref => $lab): ?>
          <option value="<?= e((string)$ref) ?>"<?= $bRaw === (string)$ref ? ' selected' : '' ?>><?= e((string)$lab) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="ar-sum-span2 ar-sum-calc-pane-formula"><?= te('audio_reports.summaries.calc_formula') ?>
      <textarea name="<?= e($p) ?>[formula]" class="ar-sum-calc-formula mono" rows="3"><?= e((string)($src['formula'] ?? '')) ?></textarea>
      <div class="ar-sum-calc-insert-row">
        <select class="ar-sum-calc-insert">
          <option value=""><?= te('audio_reports.summaries.calc_insert') ?></option>
          <?php foreach ($fieldOptions as $ref => $lab): ?>
            <option value="{<?= e((string)$ref) ?>}"><?= e((string)$lab) ?> — {<?= e((string)$ref) ?>}</option>
          <?php endforeach; ?>
        </select>
        <span class="muted"><?= te('audio_reports.summaries.calc_formula_hint') ?></span>
      </div>
    </label>
    <label class="ar-sum-calc-pane-unit"><?= te('audio_reports.summaries.ext_unit') ?>
      <input type="text" name="<?= e($p) ?>[unit]" value="<?= e((string)($src['unit'] ?? '')) ?>">
    </label>
    <label><?= te('audio_reports.summaries.ext_decimals') ?>
      <input type="number" min="0" max="4" name="<?= e($p) ?>[decimals]" value="<?= (int)($src['decimals'] ?? 1) ?>">
    </label>
    <div class="ar-sum-span2 ar-sum-calc-pane-target ar-sum-ext-grid">
      <label><?= te('audio_reports.summaries.calc_target') ?>
        <input type="text" name="<?= e($p) ?>[target]" value="<?= e($target) ?>" placeholder="<?= te('audio_reports.summaries.calc_target_ph') ?>">
      </label>
      <label><?= te('audio_reports.summaries.calc_compare') ?>
        <select name="<?= e($p) ?>[compare]">
          <option value="gte"<?= $compare === 'gte' ? ' selected' : '' ?>><?= te('audio_reports.summaries.calc_gte') ?></option>
          <option value="lte"<?= $compare === 'lte' ? ' selected' : '' ?>><?= te('audio_reports.summaries.calc_lte') ?></option>
        </select>
      </label>
      <p class="muted"><?= te('audio_reports.summaries.calc_target_hint') ?></p>
    </div>
    <label class="ar-sum-span2"><?= te('audio_reports.summaries.ext_tip') ?>
      <input type="text" name="<?= e($p) ?>[tip]" value="<?= e((string)($src['tip'] ?? '')) ?>">
    </label>
  </div>
  <div class="ar-sum-ext-flags">
    <label><input type="checkbox" name="<?= e($p) ?>[show_card]" value="1"<?= !empty($src['show_card']) ? ' checked' : '' ?>> <?= te('audio_reports.summaries.show_card') ?></label>
    <label><input type="checkbox" name="<?= e($p) ?>[show_detail]" value="1"<?= !empty($src['show_detail']) ? ' checked' : '' ?>> <?= te('audio_reports.summaries.show_detail') ?></label>
  </div>
</div>
