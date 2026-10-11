<?php
declare(strict_types=1);
/**
 * One external source editor row.
 *
 * @var int $index
 * @var array<string,mixed>|null $src
 */
$index = (int)($index ?? 0);
$src = is_array($src ?? null) ? $src : [
    'key' => 'ext_' . $index,
    'title' => '',
    'table' => '',
    'emp_field' => 'ID_ADMIN',
    'date_field' => 'DTSTART',
    'value_field' => 'SUMM',
    'agg' => 'sum',
    'where' => 'ENABLED>0',
    'show_card' => 1,
    'show_detail' => 1,
    'unit' => '',
    'decimals' => 0,
    'tip' => '',
];
$p = 'external_sources[' . $index . ']';
$agg = (string)($src['agg'] ?? 'sum');
?>
<div class="ar-sum-ext-item" data-index="<?= $index ?>">
  <div class="ar-sum-ext-head">
    <div class="ar-sum-ext-num"><?= te('audio_reports.summaries.ext_num', ['n' => (string)($index + 1)]) ?></div>
    <button type="button" class="ar-sum-ext-remove btn-secondary"><?= te('core.common.delete') ?></button>
  </div>
  <input type="hidden" name="<?= e($p) ?>[key]" value="<?= e((string)($src['key'] ?? ('ext_' . $index))) ?>">
  <div class="ar-sum-ext-grid">
    <label class="ar-sum-span2"><?= te('audio_reports.summaries.ext_title') ?>
      <input type="text" name="<?= e($p) ?>[title]" value="<?= e((string)($src['title'] ?? '')) ?>" placeholder="<?= te('audio_reports.summaries.ext_title_ph') ?>">
    </label>
    <label><?= te('audio_reports.summaries.ext_table') ?>
      <input type="text" name="<?= e($p) ?>[table]" value="<?= e((string)($src['table'] ?? '')) ?>" class="mono" placeholder="base_procedures">
    </label>
    <label><?= te('audio_reports.summaries.ext_emp') ?>
      <input type="text" name="<?= e($p) ?>[emp_field]" value="<?= e((string)(($src['emp_field'] ?? '') !== '' ? $src['emp_field'] : 'ID_ADMIN')) ?>" class="mono">
    </label>
    <label><?= te('audio_reports.summaries.ext_date') ?>
      <input type="text" name="<?= e($p) ?>[date_field]" value="<?= e((string)(($src['date_field'] ?? '') !== '' ? $src['date_field'] : 'DTSTART')) ?>" class="mono">
    </label>
    <label><?= te('audio_reports.summaries.ext_value') ?>
      <input type="text" name="<?= e($p) ?>[value_field]" value="<?= e((string)($src['value_field'] ?? '')) ?>" class="mono">
    </label>
    <label><?= te('audio_reports.summaries.ext_agg') ?>
      <select name="<?= e($p) ?>[agg]">
        <option value="sum"<?= $agg === 'sum' ? ' selected' : '' ?>><?= te('audio_reports.summaries.ext_agg_sum') ?></option>
        <option value="count"<?= $agg === 'count' ? ' selected' : '' ?>><?= te('audio_reports.summaries.ext_agg_count') ?></option>
        <option value="avg"<?= $agg === 'avg' ? ' selected' : '' ?>><?= te('audio_reports.summaries.ext_agg_avg') ?></option>
      </select>
    </label>
    <label class="ar-sum-span2"><?= te('audio_reports.summaries.ext_where') ?>
      <input type="text" name="<?= e($p) ?>[where]" value="<?= e((string)($src['where'] ?? '')) ?>" class="mono" placeholder="ENABLED&gt;0">
    </label>
    <label><?= te('audio_reports.summaries.ext_unit') ?>
      <input type="text" name="<?= e($p) ?>[unit]" value="<?= e((string)($src['unit'] ?? '')) ?>">
    </label>
    <label><?= te('audio_reports.summaries.ext_decimals') ?>
      <input type="number" min="0" max="4" name="<?= e($p) ?>[decimals]" value="<?= (int)($src['decimals'] ?? 0) ?>">
    </label>
    <label class="ar-sum-span2"><?= te('audio_reports.summaries.ext_tip') ?>
      <input type="text" name="<?= e($p) ?>[tip]" value="<?= e((string)($src['tip'] ?? '')) ?>">
    </label>
  </div>
  <div class="ar-sum-ext-flags">
    <label><input type="checkbox" name="<?= e($p) ?>[show_card]" value="1"<?= !empty($src['show_card']) ? ' checked' : '' ?>> <?= te('audio_reports.summaries.show_card') ?></label>
    <label><input type="checkbox" name="<?= e($p) ?>[show_detail]" value="1"<?= !empty($src['show_detail']) ? ' checked' : '' ?>> <?= te('audio_reports.summaries.show_detail') ?></label>
    <p class="muted"><?= te('audio_reports.summaries.ext_hidden_hint') ?></p>
  </div>
</div>
