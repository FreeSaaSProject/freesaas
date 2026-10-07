<?php
/**
 * Summary profile constructor.
 *
 * @var bool $isNew
 * @var ?array $profile
 * @var array<string,mixed> $config
 * @var array<string,string> $labels
 * @var string $configJson
 * @var string $labelsJson
 * @var ?array $catalog
 * @var list<array<string,mixed>> $positions
 * @var array<string,string> $fieldOptions
 * @var int $year
 * @var int $month
 * @var bool $canEdit
 * @var string $flash
 * @var string $error
 */
$actionUrl = $isNew ? '/audio/summaries/new' : ('/audio/summaries/' . (int)($profile['ID'] ?? 0));
$profileId = (int)($profile['ID'] ?? 0);
$config = is_array($config ?? null) ? $config : SummariesRepository::defaultConfig();
$labels = is_array($labels ?? null) ? $labels : SummariesRepository::defaultLabels();
$fieldOptions = is_array($fieldOptions ?? null) ? $fieldOptions : [];
$positions = is_array($positions ?? null) ? $positions : [];
$catalog = is_array($catalog ?? null) ? $catalog : null;
$cardOpts = [
    'volume' => t('audio_reports.summaries.cardopt.volume'),
    'score' => t('audio_reports.summaries.cardopt.score'),
    'criteria' => t('audio_reports.summaries.cardopt.criteria'),
    'scripts' => t('audio_reports.summaries.cardopt.scripts'),
    'script_score' => t('audio_reports.summaries.cardopt.script_score'),
    'tracking' => t('audio_reports.summaries.cardopt.tracking'),
    'leaders' => t('audio_reports.summaries.cardopt.leaders'),
];
$labelCaptions = [
    'banner_title' => t('audio_reports.summaries.labcap.banner_title'),
    'banner' => t('audio_reports.summaries.labcap.banner'),
    'card_volume' => t('audio_reports.summaries.labcap.card_volume'),
    'card_score' => t('audio_reports.summaries.labcap.card_score'),
    'card_criteria' => t('audio_reports.summaries.labcap.card_criteria'),
    'card_scripts' => t('audio_reports.summaries.labcap.card_scripts'),
    'card_script_score' => t('audio_reports.summaries.labcap.card_script_score'),
    'card_tracking' => t('audio_reports.summaries.labcap.card_tracking'),
    'growth_score' => t('audio_reports.summaries.labcap.growth_score'),
    'growth_criteria' => t('audio_reports.summaries.labcap.growth_criteria'),
    'growth_scripts' => t('audio_reports.summaries.labcap.growth_scripts'),
];
$tipCaptions = [
    'tip_banner' => t('audio_reports.summaries.tipcap.banner'),
    'tip_card_volume' => t('audio_reports.summaries.tipcap.card_volume'),
    'tip_card_score' => t('audio_reports.summaries.tipcap.card_score'),
    'tip_card_criteria' => t('audio_reports.summaries.tipcap.card_criteria'),
    'tip_card_scripts' => t('audio_reports.summaries.tipcap.card_scripts'),
    'tip_card_script_score' => t('audio_reports.summaries.tipcap.card_script_score'),
    'tip_card_tracking' => t('audio_reports.summaries.tipcap.card_tracking'),
    'tip_growth' => t('audio_reports.summaries.tipcap.growth'),
    'tip_detail' => t('audio_reports.summaries.tipcap.detail'),
    'tip_col_employee' => t('audio_reports.summaries.tipcap.col_employee'),
    'tip_col_days' => t('audio_reports.summaries.tipcap.col_days'),
    'tip_col_hours' => t('audio_reports.summaries.tipcap.col_hours'),
    'tip_col_score' => t('audio_reports.summaries.tipcap.col_score'),
    'tip_col_pass' => t('audio_reports.summaries.tipcap.col_pass'),
    'tip_col_scripts_sum' => t('audio_reports.summaries.tipcap.col_scripts_sum'),
    'tip_col_script' => t('audio_reports.summaries.tipcap.col_script'),
    'tip_col_script_score' => t('audio_reports.summaries.tipcap.col_script_score'),
    'tip_col_criteria' => t('audio_reports.summaries.tipcap.col_criteria'),
    'tip_col_tracking' => t('audio_reports.summaries.tipcap.col_tracking'),
    'tip_col_keys' => t('audio_reports.summaries.tipcap.col_keys'),
    'tip_col_external' => t('audio_reports.summaries.tipcap.col_external'),
];

$renderChecks = static function (string $name, array $items, array $selectedIds, string $extraKey = '') : string {
    $html = '<div class="ar-sum-check-list" id="box_' . e($name) . '">';
    if ($items === []) {
        $html .= '<div class="muted">' . te('audio_reports.summaries.catalog_empty') . '</div>';
    } else {
        foreach ($items as $it) {
            $iid = (int)($it['ID'] ?? 0);
            $checked = in_array($iid, $selectedIds, true) ? ' checked' : '';
            $extra = '';
            if ($extraKey !== '' && !empty($it[$extraKey])) {
                if ($extraKey === 'TRACKING_CODE') {
                    $extra = ' <span class="muted">[' . (string)$it[$extraKey] . ']</span>';
                } else {
                    $extra = ' <span class="muted">[' . e((string)$it[$extraKey]) . ']</span>';
                }
            }
            $hint = '';
            if (array_key_exists('is_scoring', $it)) {
                $hint = !empty($it['is_scoring'])
                    ? ' <span class="ar-sum-hint-ok">' . te('audio_reports.summaries.scoring') . '</span>'
                    : ' <span class="muted">' . te('audio_reports.summaries.ref_only') . '</span>';
            }
            $html .= '<label><input type="checkbox" name="' . e($name) . '[]" value="' . $iid . '"' . $checked . '> '
                . e((string)($it['NAME'] ?? '')) . $extra . $hint . '</label>';
        }
    }
    $html .= '</div>';
    return $html;
};

$selCriteria = array_map('intval', $config['criteria_type_ids'] ?? []);
$selScripts = array_map('intval', $config['script_ids'] ?? []);
$selDicts = array_map('intval', $config['tracking_dict_ids'] ?? []);
$selDetailCrit = array_map('intval', $config['detail_criteria_ids'] ?? []);
$selDetailTrack = array_map('intval', $config['detail_tracking_dict_ids'] ?? []);
$cards = is_array($config['cards'] ?? null) ? $config['cards'] : [];
$detailMode = (string)($config['detail_scripts_mode'] ?? 'sum');
$showScore = !isset($config['detail_show_score']) || !empty($config['detail_show_score']);
$showPass = !isset($config['detail_show_pass']) || !empty($config['detail_show_pass']);
$showKeys = !isset($config['detail_show_keys']) || !empty($config['detail_show_keys']);
$showScriptScore = !isset($config['detail_show_script_score']) || !empty($config['detail_show_script_score']);
$extSources = is_array($config['external_sources'] ?? null) ? $config['external_sources'] : [];
$calcMetrics = is_array($config['computed_metrics'] ?? null) ? $config['computed_metrics'] : [];
?>
<?php if ($flash !== ''): ?>
  <p class="flash-ok"><?= e($flash) ?></p>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <p class="flash-err"><?= e($error) ?></p>
<?php endif; ?>

<?php if (!$canEdit): ?>
  <p class="muted"><?= e((string)($profile['NAME'] ?? '')) ?> · #<?= (int)($profile['ID_POSITION'] ?? 0) ?></p>
  <pre class="ar-sum-json-view"><?= e($configJson) ?></pre>
<?php else: ?>

<div id="ar-sum-progress" class="ar-sum-progress" style="display:none">
  <div class="ar-sum-progress-title"><?= te('audio_reports.summaries.progress_title') ?></div>
  <div id="ar-sum-progress-log" class="ar-sum-progress-log"></div>
  <iframe id="ar-sum-work-frame" name="ar_sum_work_frame" class="ar-sum-work-frame" title="<?= te('audio_reports.summaries.progress_title') ?>"></iframe>
</div>

<form class="ar-sum-form" method="post" action="<?= e($actionUrl) ?>" id="ar-sum-form">
  <?= NavContext::hiddenField() ?>
  <input type="hidden" name="id_records_summary" id="ar-sum-profile-id" value="<?= $profileId ?>">

  <section class="ar-sum-section">
    <div class="ar-sum-grid-2">
      <label><?= te('audio_reports.summaries.field_name') ?>
        <input name="name" id="ar-sum-name" required value="<?= e((string)($profile['NAME'] ?? '')) ?>">
      </label>
      <label><?= te('audio_reports.summaries.field_position') ?>
        <select name="id_position" id="ar-sum-position" required>
          <option value="0"><?= te('audio_reports.summaries.select_position') ?></option>
          <?php foreach ($positions as $pos): ?>
            <option value="<?= (int)$pos['ID'] ?>"<?= ((int)($profile['ID_POSITION'] ?? 0) === (int)$pos['ID']) ? ' selected' : '' ?>><?= e((string)$pos['NAME']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><?= te('audio_reports.summaries.preview_period') ?>
        <select name="month" id="ar-sum-month">
          <?php for ($m = 1; $m <= 12; $m++): ?>
            <option value="<?= $m ?>"<?= $month === $m ? ' selected' : '' ?>><?= e(t('audio_reports.calendar.month.' . $m)) ?></option>
          <?php endfor; ?>
        </select>
        <select name="year" id="ar-sum-year">
          <?php $cy = (int)date('Y'); for ($y = $cy; $y >= $cy - 3; $y--): ?>
            <option value="<?= $y ?>"<?= $year === $y ? ' selected' : '' ?>><?= $y ?></option>
          <?php endfor; ?>
        </select>
      </label>
      <div class="ar-sum-inline-flags">
        <label><input type="checkbox" name="is_default" value="1"<?= !empty($profile['IS_DEFAULT']) ? ' checked' : '' ?>> <?= te('audio_reports.summaries.field_default') ?></label>
        <label><input type="checkbox" name="enabled" value="1"<?= ($isNew || !empty($profile['ENABLED'])) ? ' checked' : '' ?>> <?= te('audio_reports.summaries.field_enabled') ?></label>
        <button type="button" class="btn" id="ar-sum-btn-propose"><?= te('audio_reports.summaries.btn_propose') ?></button>
      </div>
    </div>
    <div id="ar-sum-catalog-info" class="muted ar-sum-catalog-info">
      <?php if ($catalog): ?>
        <?= te('audio_reports.summaries.catalog_info', [
            'name' => (string)$catalog['position_name'],
            'types' => (string)count($catalog['types']),
            'scripts' => (string)count($catalog['scripts']),
            'dicts' => (string)count($catalog['dicts']),
        ]) ?>
      <?php endif; ?>
    </div>
  </section>

  <div id="ar-sum-metrics-block"<?= $catalog ? '' : ' style="display:none"' ?>>
    <section class="ar-sum-section">
      <h3><?= te('audio_reports.summaries.sec_metrics') ?></h3>
      <div class="ar-sum-card-opts">
        <div class="ar-sum-sec-label"><?= te('audio_reports.summaries.sec_cards') ?></div>
        <?php foreach ($cardOpts as $ck => $clabel): ?>
          <label><input type="checkbox" name="cards[]" value="<?= e($ck) ?>"<?= in_array($ck, $cards, true) ? ' checked' : '' ?>> <?= e($clabel) ?></label>
        <?php endforeach; ?>
      </div>
      <div class="ar-sum-grid-3">
        <div>
          <div class="ar-sum-sec-label"><?= te('audio_reports.summaries.sec_criteria') ?></div>
          <div id="ar-sum-wrap-criteria"><?= $renderChecks('criteria_type_ids', $catalog['types'] ?? [], $selCriteria) ?></div>
        </div>
        <div>
          <div class="ar-sum-sec-label"><?= te('audio_reports.summaries.sec_scripts') ?></div>
          <div id="ar-sum-wrap-scripts"><?= $renderChecks('script_ids', $catalog['scripts'] ?? [], $selScripts) ?></div>
        </div>
        <div>
          <div class="ar-sum-sec-label"><?= te('audio_reports.summaries.sec_tracking') ?></div>
          <div id="ar-sum-wrap-dicts"><?= $renderChecks('tracking_dict_ids', $catalog['dicts'] ?? [], $selDicts, 'TRACKING_CODE') ?></div>
        </div>
      </div>
      <div class="ar-sum-grid-3" style="margin-top:0.75rem">
        <label><?= te('audio_reports.summaries.field_threshold') ?>
          <input type="number" step="0.01" name="score_pass_threshold" value="<?= e((string)($config['score_pass_threshold'] ?? 1)) ?>">
        </label>
        <label><?= te('audio_reports.summaries.field_benchmark_days') ?>
          <input type="number" name="benchmark_min_days" value="<?= (int)($config['benchmark_min_days'] ?? 5) ?>">
        </label>
        <label><?= te('audio_reports.summaries.field_cooldown') ?>
          <input type="number" min="0" name="tracking_cooldown_sec" value="<?= (int)($config['tracking_cooldown_sec'] ?? 0) ?>">
          <span class="muted"><?= te('audio_reports.summaries.field_cooldown_hint') ?></span>
        </label>
      </div>
    </section>

    <section class="ar-sum-section">
      <h3><?= te('audio_reports.summaries.sec_detail') ?></h3>
      <p class="muted"><?= te('audio_reports.summaries.sec_detail_hint') ?></p>
      <div class="ar-sum-card-opts">
        <div class="ar-sum-sec-label"><?= te('audio_reports.summaries.sec_base_cols') ?></div>
        <label><input type="checkbox" name="detail_show_score" value="1"<?= $showScore ? ' checked' : '' ?>> <?= te('audio_reports.summaries.col_score_mark') ?></label>
        <label><input type="checkbox" name="detail_show_pass" value="1"<?= $showPass ? ' checked' : '' ?>> <?= te('audio_reports.summaries.col_pass_short') ?></label>
        <label><input type="checkbox" name="detail_show_keys" value="1"<?= $showKeys ? ' checked' : '' ?>> <?= te('audio_reports.summaries.col_keys') ?></label>
        <label><input type="checkbox" name="detail_show_script_score" value="1"<?= $showScriptScore ? ' checked' : '' ?>> <?= te('audio_reports.summaries.col_rub') ?></label>
      </div>
      <div class="ar-sum-sec-label"><?= te('audio_reports.summaries.sec_scripts_mode') ?></div>
      <?php foreach (['sum' => 'scripts_mode_sum', 'separate' => 'scripts_mode_sep', 'both' => 'scripts_mode_both'] as $mk => $ml): ?>
        <label class="ar-sum-block-label"><input type="radio" name="detail_scripts_mode" value="<?= e($mk) ?>"<?= $detailMode === $mk ? ' checked' : '' ?>> <?= te('audio_reports.summaries.' . $ml) ?></label>
      <?php endforeach; ?>
      <div class="ar-sum-grid-2" style="margin-top:0.75rem">
        <div>
          <div class="ar-sum-sec-label"><?= te('audio_reports.summaries.sec_detail_criteria') ?></div>
          <div id="ar-sum-wrap-detail-criteria"><?= $renderChecks('detail_criteria_ids', $catalog['types'] ?? [], $selDetailCrit) ?></div>
        </div>
        <div>
          <div class="ar-sum-sec-label"><?= te('audio_reports.summaries.sec_detail_tracking') ?></div>
          <div id="ar-sum-wrap-detail-tracking"><?= $renderChecks('detail_tracking_dict_ids', $catalog['dicts'] ?? [], $selDetailTrack, 'TRACKING_CODE') ?></div>
        </div>
      </div>
    </section>

    <section class="ar-sum-section">
      <h3><?= te('audio_reports.summaries.sec_external') ?></h3>
      <p class="muted"><?= te('audio_reports.summaries.sec_external_hint') ?></p>
      <div id="ar-sum-ext-list">
        <?php foreach ($extSources as $ei => $es): ?>
          <?php
            $index = (int)$ei;
            $src = is_array($es) ? $es : null;
            require __DIR__ . '/_summaries_ext_editor.php';
          ?>
        <?php endforeach; ?>
      </div>
      <button type="button" class="btn-secondary" id="ar-sum-ext-add"><?= te('audio_reports.summaries.btn_add_ext') ?></button>
    </section>

    <section class="ar-sum-section">
      <h3><?= te('audio_reports.summaries.sec_computed') ?></h3>
      <p class="muted"><?= te('audio_reports.summaries.sec_computed_hint') ?></p>
      <div id="ar-sum-calc-list">
        <?php foreach ($calcMetrics as $ci => $cm): ?>
          <?php
            $index = (int)$ci;
            $src = is_array($cm) ? $cm : null;
            require __DIR__ . '/_summaries_calc_editor.php';
          ?>
        <?php endforeach; ?>
      </div>
      <button type="button" class="btn-secondary" id="ar-sum-calc-add"><?= te('audio_reports.summaries.btn_add_calc') ?></button>
    </section>

    <section class="ar-sum-section">
      <h3><?= te('audio_reports.summaries.sec_labels') ?></h3>
      <?php foreach ($labels as $lk => $lv):
          if (strpos($lk, 'tip_') === 0) {
              continue;
          }
          if (!isset($labelCaptions[$lk]) && !in_array($lk, ['banner_title', 'banner', 'card_volume', 'card_score', 'card_criteria', 'card_scripts', 'card_script_score', 'card_tracking', 'growth_score', 'growth_criteria', 'growth_scripts'], true)) {
              continue;
          }
          $caption = $labelCaptions[$lk] ?? $lk;
          $rows = ($lk === 'banner_title') ? 1 : 2;
          ?>
        <label><?= e($caption) ?>
          <textarea name="label_<?= e($lk) ?>" rows="<?= $rows ?>"><?= e((string)$lv) ?></textarea>
        </label>
      <?php endforeach; ?>
    </section>

    <section class="ar-sum-section">
      <h3><?= te('audio_reports.summaries.sec_tips') ?></h3>
      <p class="muted"><?= te('audio_reports.summaries.sec_tips_hint') ?></p>
      <?php foreach ($labels as $lk => $lv):
          if (strpos($lk, 'tip_') !== 0) {
              continue;
          }
          $caption = $tipCaptions[$lk] ?? $lk;
          ?>
        <label><?= e($caption) ?>
          <textarea name="label_<?= e($lk) ?>" rows="2"><?= e((string)$lv) ?></textarea>
        </label>
      <?php endforeach; ?>
    </section>
  </div>

  <details class="ar-sum-section">
    <summary><?= te('audio_reports.summaries.advanced_json') ?></summary>
    <p class="muted"><?= te('audio_reports.summaries.config_hint') ?></p>
    <label><?= te('audio_reports.summaries.field_config') ?>
      <textarea name="config_json" rows="6"><?= e($configJson) ?></textarea>
    </label>
    <label><?= te('audio_reports.summaries.field_labels') ?>
      <textarea name="labels_json" rows="4"><?= e($labelsJson) ?></textarea>
    </label>
  </details>

  <div class="ar-sum-actions">
    <button type="button" class="btn-secondary" id="ar-sum-btn-preview"><?= te('audio_reports.summaries.btn_preview') ?></button>
    <button type="submit" name="action" value="save" class="btn"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/audio/summaries"><?= te('core.common.cancel') ?></a>
    <?php if (!$isNew): ?>
      <button class="btn-secondary" type="submit" name="action" value="delete"
              onclick="return confirm(<?= tj('audio_reports.summaries.confirm_delete') ?>)"><?= te('core.common.delete') ?></button>
    <?php endif; ?>
  </div>
</form>

<div id="ar-sum-preview-wrap" class="ar-sum-preview-wrap" style="display:none">
  <h2><?= te('audio_reports.summaries.preview_heading') ?></h2>
  <div id="ar-sum-preview"></div>
</div>

<script type="text/template" id="ar-sum-ext-tpl"><?php
  $index = 999;
  $src = null;
  ob_start();
  require __DIR__ . '/_summaries_ext_editor.php';
  $tpl = (string)ob_get_clean();
  echo str_replace(
      ['external_sources[999]', 'data-index="999"', 'value="ext_999"'],
      ['external_sources[__IDX__]', 'data-index="__IDX__"', 'value="ext___IDX__"'],
      $tpl
  );
?></script>
<script type="text/template" id="ar-sum-calc-tpl"><?php
  $index = 999;
  $src = null;
  ob_start();
  require __DIR__ . '/_summaries_calc_editor.php';
  $tpl = (string)ob_get_clean();
  echo str_replace(
      ['computed_metrics[999]', 'data-index="999"', 'value="calc_999"'],
      ['computed_metrics[__IDX__]', 'data-index="__IDX__"', 'value="calc___IDX__"'],
      $tpl
  );
?></script>

<?php endif; ?>

<script>
window.arSumI18n = {
  day: <?= tj('audio_reports.summaries.js.day') ?>,
  hours: <?= tj('audio_reports.summaries.js.hours') ?>,
  needPosition: <?= tj('audio_reports.summaries.js.need_position') ?>
};
</script>
