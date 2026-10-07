<?php
/**
 * Summary report body (cards, growth, leaders, day panel).
 *
 * @var array<string,mixed> $vm from SummaryRenderer::build
 * @var array<string,mixed>|null $report
 * @var bool $preview
 */
$vm = is_array($vm ?? null) ? $vm : [];
$preview = !empty($preview) || !empty($vm['preview']);
$profileId = (int)($vm['profile_id'] ?? 0);
$periodMode = (string)($vm['period_mode'] ?? 'month');
$isRange = ($periodMode === 'range');
$year = (int)($vm['year'] ?? date('Y'));
$month = (int)($vm['month'] ?? date('n'));
$dateFrom = (string)($vm['date_from'] ?? '');
$dateTo = (string)($vm['date_to'] ?? '');
$canEdit = !empty($vm['can_edit']);
$profiles = isset($vm['profiles']) && is_array($vm['profiles']) ? $vm['profiles'] : [];
?>
<div id="ar-sum-report" class="ar-sum-report" data-period-mode="<?= e($periodMode) ?>">
<?php if ($preview): ?>
  <div class="ar-sum-preview-banner"><?= te('audio_reports.summaries.preview_banner', ['period' => (string)($vm['period_label'] ?? '')]) ?></div>
<?php else: ?>
  <div class="ar-sum-head">
    <div>
      <h2 class="ar-sum-title">
        <?= e((string)($vm['profile_name'] ?? '')) ?>
        <?php if (!empty($vm['is_default'])): ?>
          <span class="ar-sum-badge"><?= te('audio_reports.summaries.badge_default') ?></span>
        <?php endif; ?>
      </h2>
      <div class="ar-sum-pos muted"><?= te('audio_reports.summaries.position_line', ['name' => (string)($vm['position_name'] ?? '')]) ?></div>
    </div>
    <div class="ar-sum-head-tools">
      <div class="ar-sum-month-pager" data-profile="<?= $profileId ?>" data-year="<?= $year ?>" data-month="<?= $month ?>" data-mode="<?= e($periodMode) ?>"<?= $isRange ? ' style="opacity:0.55"' : '' ?>>
        <a class="ar-sum-month-nav" data-year="<?= (int)$vm['prev_year'] ?>" data-month="<?= (int)$vm['prev_month'] ?>"
           href="/audio/summaries?profile=<?= $profileId ?>&amp;year=<?= (int)$vm['prev_year'] ?>&amp;month=<?= (int)$vm['prev_month'] ?>"
           title="<?= te('audio_reports.summaries.nav_prev') ?>">&larr;</a>
        <span class="ar-sum-month-label"><?= e($isRange ? (string)$vm['period_label'] : (t('audio_reports.calendar.month.' . $month) . ' ' . $year)) ?></span>
        <?php if (!empty($vm['can_next'])): ?>
          <a class="ar-sum-month-nav" data-year="<?= (int)$vm['next_year'] ?>" data-month="<?= (int)$vm['next_month'] ?>"
             href="/audio/summaries?profile=<?= $profileId ?>&amp;year=<?= (int)$vm['next_year'] ?>&amp;month=<?= (int)$vm['next_month'] ?>"
             title="<?= te('audio_reports.summaries.nav_next') ?>">&rarr;</a>
        <?php else: ?>
          <span class="ar-sum-month-nav is-disabled">&rarr;</span>
        <?php endif; ?>
      </div>
      <form method="get" action="/audio/summaries" class="ar-sum-range-form">
        <input type="hidden" name="profile" value="<?= $profileId ?>">
        <span class="muted"><?= te('audio_reports.summaries.range_from') ?></span>
        <input type="date" name="date_from" value="<?= e($dateFrom) ?>">
        <span class="muted"><?= te('audio_reports.summaries.range_to') ?></span>
        <input type="date" name="date_to" value="<?= e($dateTo) ?>">
        <button type="submit"><?= te('audio_reports.summaries.show') ?></button>
        <?php if ($isRange): ?>
          <a href="/audio/summaries?profile=<?= $profileId ?>&amp;year=<?= $year ?>&amp;month=<?= $month ?>"><?= te('audio_reports.summaries.back_month') ?></a>
        <?php endif; ?>
      </form>
      <?php if (count($profiles) > 1 || $canEdit): ?>
        <form method="get" action="/audio/summaries" class="ar-sum-profile-form">
          <?php if ($isRange): ?>
            <input type="hidden" name="date_from" value="<?= e($dateFrom) ?>">
            <input type="hidden" name="date_to" value="<?= e($dateTo) ?>">
          <?php else: ?>
            <input type="hidden" name="year" value="<?= $year ?>">
            <input type="hidden" name="month" value="<?= $month ?>">
          <?php endif; ?>
          <select name="profile" onchange="if(this.value==='__new__'){location.href='/audio/summaries/new';return false;}this.form.submit();">
            <?php foreach ($profiles as $p): ?>
              <option value="<?= (int)$p['ID'] ?>"<?= ((int)$p['ID'] === $profileId) ? ' selected' : '' ?>><?= e((string)$p['NAME']) ?><?= !empty($p['IS_DEFAULT']) ? ' ★' : '' ?></option>
            <?php endforeach; ?>
            <?php if ($canEdit): ?>
              <option value="__new__"><?= te('audio_reports.summaries.construct_new') ?></option>
            <?php endif; ?>
          </select>
        </form>
      <?php endif; ?>
      <?php if ($canEdit && $profileId > 0): ?>
        <a class="btn" href="/audio/summaries/<?= $profileId ?>"><?= te('audio_reports.summaries.edit') ?></a>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

  <div class="ar-sum-banner ar-sum-fade" title="<?= e((string)($vm['banner']['tip'] ?? '')) ?>">
    <div class="ar-sum-banner-title"><?= e((string)($vm['banner']['title'] ?? '')) ?></div>
    <div class="ar-sum-banner-text"><?= e((string)($vm['banner']['text'] ?? '')) ?></div>
  </div>

  <div class="ar-sum-cards">
    <?php foreach (($vm['cards'] ?? []) as $card):
        $kind = (string)($card['kind'] ?? '');
        ?>
      <div class="ar-sum-card ar-sum-fade<?= $kind === 'tracking' ? ' is-track' : '' ?>" title="<?= e((string)($card['tip'] ?? '')) ?>">
        <div class="ar-sum-card-label"><?= e((string)($card['label'] ?? '')) ?></div>
        <?php if ($kind === 'tracking'): ?>
          <?php foreach (($card['items'] ?? []) as $it): ?>
            <div class="ar-sum-track-line">
              <span class="ar-sum-track-code" title="<?= e((string)($it['name'] ?? '')) ?>"><?= (string)($it['code'] ?? '') ?></span>:
              <b class="ar-sum-count" data-rs-key="<?= e((string)($it['count_key'] ?? '')) ?>" data-to="<?= (int)$it['count'] ?>" data-decimals="0"><?= (int)$it['count'] ?></b>
            </div>
          <?php endforeach; ?>
        <?php elseif ($kind === 'computed' && ($card['calc_kind'] ?? '') === 'check'): ?>
          <div class="ar-sum-card-val" style="color:<?= e((string)($card['color'] ?? '#16a34a')) ?>">
            <?= (int)($card['pass_count'] ?? 0) ?>
            <span class="ar-sum-card-unit"><?= te('audio_reports.summaries.card.check_of', ['n' => (string)(int)($card['rows_count'] ?? 0)]) ?></span>
          </div>
        <?php elseif ($kind === 'computed'): ?>
          <div class="ar-sum-card-val" style="color:<?= e((string)($card['color'] ?? '#334155')) ?>">
            <?= e((string)($card['formatted'] ?? '—')) ?>
            <?php if (($card['formatted'] ?? '') !== '—' && ($card['unit'] ?? '') !== ''): ?>
              <span class="ar-sum-card-unit"><?= e((string)$card['unit']) ?></span>
            <?php endif; ?>
          </div>
        <?php elseif (!empty($card['error'])): ?>
          <div class="ar-sum-card-err"><?= e((string)$card['error']) ?></div>
        <?php else: ?>
          <div class="ar-sum-card-val" style="color:<?= e((string)($card['color'] ?? '#1e293b')) ?>">
            <?php if (!empty($card['value_prefix'])): ?><?= e((string)$card['value_prefix']) ?><?php endif; ?>
            <span class="ar-sum-count" data-rs-key="<?= e((string)($card['count_key'] ?? '')) ?>" data-to="<?= e((string)($card['value'] ?? 0)) ?>" data-decimals="<?= (int)($card['decimals'] ?? 0) ?>"><?= e(SummaryRenderer::formatNumber($card['value'] ?? 0, (int)($card['decimals'] ?? 0))) ?></span>
            <?php if (($card['unit'] ?? '') !== ''): ?>
              <span class="ar-sum-card-unit"><?= e((string)$card['unit']) ?></span>
            <?php endif; ?>
          </div>
          <?php if (!empty($card['sub'])): ?>
            <div class="ar-sum-card-sub"><?= e((string)$card['sub']) ?></div>
          <?php endif; ?>
          <?php if (!empty($card['badge'])): ?>
            <div class="ar-sum-card-badge<?= !empty($card['badge_class']) ? ' ' . e((string)$card['badge_class']) : '' ?>"><?= e((string)$card['badge']) ?></div>
          <?php endif; ?>
          <?php if (!empty($card['lines'])): ?>
            <div class="ar-sum-card-lines">
              <?php foreach ($card['lines'] as $ln): ?>
                <div><?= e((string)($ln['name'] ?? '')) ?>: <b><?= e((string)($ln['score'] ?? '')) ?></b></div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <?php $g = $vm['growth'] ?? []; ?>
  <div class="ar-sum-growth ar-sum-fade" title="<?= e((string)($vm['tip_growth'] ?? '')) ?>">
    <h3><?= te('audio_reports.summaries.growth_title') ?></h3>
    <div class="ar-sum-growth-grid">
      <div class="ar-sum-growth-item" style="border-left-color:<?= e((string)($g['score_color'] ?? '#64748b')) ?>">
        <div class="ar-sum-growth-h">1. <?= e((string)($g['score_title'] ?? '')) ?></div>
        <div class="ar-sum-growth-body">
          <?= te('audio_reports.summaries.growth_avg') ?>
          <b style="color:<?= e((string)($g['score_color'] ?? '')) ?>"><span class="ar-sum-count" data-rs-key="growth_avg_score" data-to="<?= e((string)($g['avg_score'] ?? 0)) ?>" data-decimals="2"><?= e(SummaryRenderer::formatNumber($g['avg_score'] ?? 0, 2)) ?></span>®</b>.<br>
          <?= te('audio_reports.summaries.growth_bench') ?>
          <b><span class="ar-sum-count" data-rs-key="benchmark_score" data-to="<?= e((string)($g['benchmark_score'] ?? 0)) ?>" data-decimals="2"><?= e(SummaryRenderer::formatNumber($g['benchmark_score'] ?? 0, 2)) ?></span>®</b>
          (<?= e((string)($g['benchmark_name'] ?? '')) ?>).<br>
          <?= te('audio_reports.summaries.growth_fail_days') ?>
          <b class="is-fail"><span class="ar-sum-count" data-rs-key="growth_fail_days" data-to="<?= (int)($g['fail_days'] ?? 0) ?>" data-decimals="0"><?= (int)($g['fail_days'] ?? 0) ?></span></b>.
        </div>
      </div>
      <div class="ar-sum-growth-item is-amber">
        <div class="ar-sum-growth-h">2. <?= e((string)($g['criteria_title'] ?? '')) ?></div>
        <?php if (!empty($g['criteria_names'])): ?>
          <div class="ar-sum-growth-names">
            <?php foreach ($g['criteria_names'] as $gn): ?>
              <div><?= e((string)$gn) ?></div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <div class="ar-sum-growth-body">
          <?php if (empty($g['fail_lines'])): ?>
            <?= te('audio_reports.summaries.growth_no_fails') ?>
          <?php else: ?>
            <?= te('audio_reports.summaries.growth_fails') ?><br>
            <b><?php foreach ($g['fail_lines'] as $fl): ?>«<?= e((string)$fl) ?>»<br><?php endforeach; ?></b>
          <?php endif; ?>
        </div>
      </div>
      <div class="ar-sum-growth-item is-green">
        <div class="ar-sum-growth-h">3. <?= e((string)($g['scripts_title'] ?? '')) ?></div>
        <div class="ar-sum-growth-body">
          <?php if (empty($g['script_lines'])): ?>
            <?= te('audio_reports.summaries.growth_no_scripts') ?>
          <?php else: ?>
            <?php foreach ($g['script_lines'] as $sl): ?>
              «<?= e((string)$sl['name']) ?>» <b><?= e((string)$sl['conversion']) ?>%</b>
              (<?= (int)$sl['finishes'] ?>/<?= (int)$sl['starts'] ?>)<br>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <?php if (!empty($vm['leaders'])):
      $L = $vm['leaders'];
      $cols = $L['columns'] ?? [];
      ?>
    <div class="ar-sum-leaders ar-sum-fade" title="<?= e((string)($vm['tip_detail'] ?? '')) ?>">
      <div class="ar-sum-leaders-head">
        <h3><?= te('audio_reports.summaries.leaders_title') ?></h3>
        <label class="ar-sum-mode-label"><?= te('audio_reports.summaries.detail_mode') ?>
          <select class="ar-sum-detail-mode">
            <option value="sum" selected><?= te('audio_reports.summaries.mode_sum') ?></option>
            <option value="per_hour"><?= te('audio_reports.summaries.mode_per_hour') ?></option>
          </select>
        </label>
      </div>
      <p class="ar-sum-mode-hint muted"><?= te('audio_reports.summaries.detail_hint') ?></p>
      <div class="ar-sum-table-wrap">
        <table class="ar-sum-detail-table">
          <thead>
            <tr>
              <?php foreach ($cols as $ci => $col): ?>
                <th class="ar-sum-sort<?= !empty($col['abs']) ? ' ar-sum-th-abs' : '' ?><?= !empty($col['class']) ? ' ' . e((string)$col['class']) : '' ?>"
                    data-col="<?= (int)$ci ?>" title="<?= e((string)($col['tip'] ?? '')) ?>">
                  <span class="ar-sum-sort-label"><?php
                    if (!empty($col['label_html'])) {
                        echo (string)$col['label_html'];
                    } else {
                        echo e((string)($col['label'] ?? ''));
                    }
                  ?></span><span class="ar-sum-sort-ind"></span>
                </th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach (($L['rows'] ?? []) as $row):
                $cells = $row['cells'] ?? [];
                $hoursAttrs = ' data-emp-id="' . (int)$row['employee_id'] . '"'
                    . ' data-emp-name="' . e((string)($cells['employee']['name_full'] ?? '')) . '"'
                    . ' data-emp-short="' . e((string)($cells['employee']['name'] ?? '')) . '"'
                    . ' data-days="' . e((string)($row['days_json'] ?? '[]')) . '"'
                    . ' data-threshold="' . e((string)($vm['threshold'] ?? 1)) . '"';
                ?>
              <tr class="ar-sum-detail-row" data-hours="<?= e((string)$row['hours']) ?>">
                <?php foreach ($cols as $col):
                    $key = (string)($col['key'] ?? '');
                    $c = $cells[$key] ?? [];
                    $sort = $c['sort'] ?? '';
                    $absClass = !empty($col['abs']) ? ' ar-sum-abs' : '';
                    if ($key === 'employee'):
                        $colorStyle = '';
                        if (!empty($c['color'])) {
                            $tc = !empty($c['color_text']) ? (string)$c['color_text'] : '#000';
                            $colorStyle = ' style="background-color:' . e((string)$c['color']) . ';color:' . e($tc) . ';padding:2px 6px;border-radius:4px;"';
                        }
                        $nameInner = '<span' . $colorStyle . ' title="' . e((string)($c['name_full'] ?? '')) . '">' . e((string)($c['name'] ?? '')) . '</span>';
                        ?>
                  <td data-sort="<?= e((string)$sort) ?>" class="ar-sum-td-name">
                    <?php if (!empty($row['can_open_days'])): ?>
                      <button type="button" class="ar-sum-hours-btn ar-sum-emp-name-btn"<?= $hoursAttrs ?>><?= $nameInner ?></button>
                    <?php else: ?>
                      <?= $nameInner ?>
                    <?php endif; ?>
                  </td>
                    <?php elseif ($key === 'hours'): ?>
                  <td data-sort="<?= e((string)$sort) ?>" class="ar-sum-td-hours">
                    <?php if (!empty($row['can_open_days'])): ?>
                      <button type="button" class="ar-sum-hours-btn"<?= $hoursAttrs ?>><?= e((string)($c['text'] ?? '')) ?></button>
                    <?php else: ?>
                      <?= e((string)($c['text'] ?? '')) ?>
                    <?php endif; ?>
                    <?php if ((int)($c['important'] ?? 0) > 0): ?>
                      <span class="ar-sum-important" title="<?= te('audio_reports.summaries.important_hint', ['n' => (string)(int)$c['important']]) ?>">!</span>
                    <?php endif; ?>
                  </td>
                    <?php elseif ($key === 'pass'): ?>
                  <td data-sort="<?= e((string)$sort) ?>">
                    <span class="is-pass"><?= (int)($c['pass'] ?? 0) ?></span>
                    /
                    <span class="is-fail"><?= (int)($c['fail'] ?? 0) ?></span>
                  </td>
                    <?php elseif ($key === 'scripts_sum' || (strpos($key, 'script_') === 0 && $key !== 'script_score')): ?>
                  <td data-sort="<?= e((string)$sort) ?>">
                    <?= e((string)($c['conversion'] ?? 0)) ?>%
                    <span class="muted">(<?= (int)($c['finishes'] ?? 0) ?>/<?= (int)($c['starts'] ?? 0) ?>)</span>
                    <?php if (!empty($c['show_score'])): ?>
                      <span class="ar-sum-abs" data-sum="<?= e((string)($c['score'] ?? 0)) ?>" data-decimals="1">=<?= e((string)round((float)($c['score'] ?? 0), 0)) ?></span>
                    <?php endif; ?>
                  </td>
                    <?php elseif ($key === 'score'): ?>
                  <td data-sort="<?= e((string)$sort) ?>" style="font-weight:700;color:<?= e((string)($c['color'] ?? '')) ?>"><?= e((string)($c['text'] ?? '')) ?></td>
                    <?php elseif (!empty($c['check'])): ?>
                  <td data-sort="<?= e((string)$sort) ?>" class="ar-sum-check" style="color:<?= e((string)($c['color'] ?? '')) ?>"><?= e((string)($c['text'] ?? '')) ?></td>
                    <?php elseif (isset($c['sum'])): ?>
                  <td class="<?= trim($absClass) ?>" data-sum="<?= e((string)$c['sum']) ?>" data-sort="<?= e((string)$sort) ?>" data-decimals="<?= (int)($c['decimals'] ?? 0) ?>"<?= !empty($c['color']) ? ' style="color:' . e((string)$c['color']) . '"' : '' ?>><?= e((string)($c['text'] ?? '')) ?></td>
                    <?php else: ?>
                  <td data-sort="<?= e((string)$sort) ?>"<?= !empty($c['color']) ? ' style="font-weight:600;color:' . e((string)$c['color']) . '"' : '' ?>><?= e((string)($c['text'] ?? '')) ?></td>
                    <?php endif; ?>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div id="ar-sum-days-panel" class="ar-sum-days-panel" style="display:none"
           data-cols="<?= e((string)($vm['day_cols_json'] ?? '')) ?>"></div>
    </div>
  <?php endif; ?>
</div>
