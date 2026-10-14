<?php
declare(strict_types=1);
/**
 * @var array<string,mixed> $report
 * @var int $adminId
 * @var string $date
 */
$report = is_array($report ?? null) ? $report : [];
$header = is_array($report['header'] ?? null) ? $report['header'] : [];
$rows = is_array($report['rows'] ?? null) ? $report['rows'] : [];
$sessions = is_array($report['sessions'] ?? null) ? $report['sessions'] : [];
$sessSummary = is_array($report['sessions_summary'] ?? null) ? $report['sessions_summary'] : [];
$procedures = is_array($report['procedures'] ?? null) ? $report['procedures'] : null;
$quality = is_array($report['quality'] ?? null) ? $report['quality'] : null;
$gated = is_array($report['gated'] ?? null) ? $report['gated'] : [];
$summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
$qualityEmoji = static function (string $color): string {
    if ($color === 'red') {
        return '🔴';
    }
    if ($color === 'yellow') {
        return '🟡';
    }
    if ($color === 'green') {
        return '🟢';
    }
    return '○';
};
$fmt = static function (int $sec): string {
    $sec = max(0, $sec);
    return sprintf('%02d:%02d:%02d', intdiv($sec, 3600), intdiv($sec % 3600, 60), $sec % 60);
};
$fmtShort = static function (int $sec): string {
    $sec = max(0, $sec);
    if ($sec >= 3600) {
        return sprintf('%d:%02d', intdiv($sec, 3600), intdiv($sec % 3600, 60));
    }
    return sprintf('%d:%02d', intdiv($sec, 60), $sec % 60);
};
$y = (int)substr($date, 0, 4);
$m = (int)substr($date, 5, 2);
$d = (int)substr($date, 8, 2);
$collectionsHref = '/audio/collections?' . http_build_query([
    'year' => $y, 'month' => $m, 'day' => $d, 'admin' => $adminId,
]);
$calHref = '/audio/calendar?' . http_build_query(['year' => $y, 'month' => $m]);
$canOpenCollection = Auth::can('audio_reports.collections') && Auth::capability('SHOW_ADMINISTRATOR_DATA');
$levelIcon = static function (string $level): string {
    if ($level === 'above') {
        return '▲';
    }
    if ($level === 'below') {
        return '▼';
    }
    if ($level === 'unknown') {
        return '?';
    }
    return '●';
};
?>
<section class="page-head">
  <div class="page-head-row">
    <div></div>
    <div class="page-actions">
      <a class="btn-secondary" href="<?= e($calHref) ?>"><?= te('audio_reports.menu.calendar') ?></a>
      <a class="btn-secondary" href="<?= e($collectionsHref) ?>"><?= te('audio_reports.day_report.link_collections') ?></a>
    </div>
  </div>
</section>

<?php if (empty($report['ok'])): ?>
  <p class="muted"><?= te('audio_reports.day_report.empty') ?></p>
<?php else: ?>

<section class="ar-day-stats" aria-label="<?= te('audio_reports.day_report.header.score') ?>">
  <div class="ar-day-stat ar-day-stat--score">
    <span class="ar-day-stat-icon" aria-hidden="true">®</span>
    <div>
      <div class="ar-day-stat-label"><?= te('audio_reports.day_report.header.score') ?></div>
      <div class="ar-day-stat-value"><?= $header['score'] !== null ? e((string)$header['score']) : '—' ?></div>
    </div>
  </div>
  <div class="ar-day-stat">
    <span class="ar-day-stat-icon" aria-hidden="true">▤</span>
    <div>
      <div class="ar-day-stat-label"><?= te('audio_reports.day_report.header.records') ?></div>
      <div class="ar-day-stat-value"><?= (int)($header['records'] ?? 0) ?></div>
    </div>
  </div>
  <div class="ar-day-stat">
    <span class="ar-day-stat-icon" aria-hidden="true">◷</span>
    <div>
      <div class="ar-day-stat-label"><?= te('audio_reports.day_report.header.playtime') ?></div>
      <div class="ar-day-stat-value"><?= e($fmt((int)($header['playtime'] ?? 0))) ?></div>
    </div>
  </div>
  <div class="ar-day-stat">
    <span class="ar-day-stat-icon" aria-hidden="true">▶</span>
    <div>
      <div class="ar-day-stat-label"><?= te('audio_reports.day_report.header.work') ?></div>
      <div class="ar-day-stat-value"><?= e($fmt((int)($header['work_sec'] ?? 0))) ?></div>
    </div>
  </div>
  <div class="ar-day-stat">
    <span class="ar-day-stat-icon" aria-hidden="true">○</span>
    <div>
      <div class="ar-day-stat-label"><?= te('audio_reports.day_report.header.idle') ?></div>
      <div class="ar-day-stat-value"><?= e($fmt((int)($header['idle_sec'] ?? 0))) ?></div>
    </div>
  </div>
  <div class="ar-day-stat">
    <span class="ar-day-stat-icon" aria-hidden="true">⏸</span>
    <div>
      <div class="ar-day-stat-label"><?= te('audio_reports.day_report.header.breaks') ?></div>
      <div class="ar-day-stat-value"><?= e($fmt((int)($header['breaks_sec'] ?? 0))) ?></div>
    </div>
  </div>
  <div class="ar-day-stat">
    <span class="ar-day-stat-icon" aria-hidden="true">⊘</span>
    <div>
      <div class="ar-day-stat-label"><?= te('audio_reports.day_report.header.disabled') ?></div>
      <div class="ar-day-stat-value"><?= e($fmt((int)($header['disabled_sec'] ?? 0))) ?></div>
    </div>
  </div>
  <?php if (!empty($header['active_from']) && !empty($header['active_to'])): ?>
  <div class="ar-day-stat">
    <span class="ar-day-stat-icon" aria-hidden="true">⇔</span>
    <div>
      <div class="ar-day-stat-label"><?= te('audio_reports.day_report.header.active') ?></div>
      <div class="ar-day-stat-value"><?= e(date('H:i', (int)$header['active_from'])) ?>–<?= e(date('H:i', (int)$header['active_to'])) ?></div>
    </div>
  </div>
  <?php endif; ?>
</section>

<?php if ($summary !== []): ?>
<section class="ar-day-summary">
  <h2><?= te('audio_reports.day_report.summary') ?></h2>
  <ul>
    <?php foreach ($summary as $line): ?>
      <li><?= e((string)$line) ?></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<section class="ar-day-scripts">
  <h2><?= te('audio_reports.day_report.scripts_title') ?></h2>
  <div class="table-wrap">
  <table class="data ar-day-report-table">
  <thead><tr>
    <th><?= te('audio_reports.day_report.col.param') ?></th>
    <th><?= te('audio_reports.day_report.col.sessions') ?></th>
    <th><?= te('audio_reports.day_report.col.duration') ?></th>
    <th><?= te('audio_reports.day_report.col.contrib') ?></th>
    <th><?= te('audio_reports.day_report.col.value') ?></th>
    <th><?= te('audio_reports.day_report.col.team') ?></th>
    <th><?= te('audio_reports.day_report.col.level') ?></th>
  </tr></thead>
  <tbody>
  <?php if ($rows === []): ?>
    <tr><td colspan="7" class="muted"><?= te('audio_reports.day_report.empty') ?></td></tr>
  <?php else: ?>
    <?php foreach ($rows as $r): ?>
      <?php $lvl = (string)($r['level'] ?? 'ok'); ?>
      <tr>
        <td>
          <a href="<?= e((string)$r['collections_href']) ?>"><?= e((string)$r['name']) ?></a>
          <?php if (!empty($r['score_rate'])): ?>
            <span class="muted small">(<?= (int)$r['score_rate'] ?>)</span>
          <?php endif; ?>
        </td>
        <td><?= (int)($r['count'] ?? 0) ?></td>
        <td><?= e($fmt((int)($r['duration'] ?? 0))) ?></td>
        <td><?= e((string)$r['contrib']) ?></td>
        <td><?= e((string)$r['value_pct']) ?>%</td>
        <td><?= $r['team_avg'] !== null ? e((string)$r['team_avg']) : '—' ?></td>
        <td>
          <span class="ar-day-level ar-day-level--<?= e($lvl) ?>">
            <span aria-hidden="true"><?= e($levelIcon($lvl)) ?></span>
            <?= te('audio_reports.day_report.level.' . $lvl) ?>
          </span>
        </td>
      </tr>
    <?php endforeach; ?>
  <?php endif; ?>
  </tbody></table>
  </div>
</section>

<section class="ar-day-sessions">
  <h2><?= te('audio_reports.day_report.sessions_title') ?></h2>
  <p class="ar-day-sessions-meta muted">
    <?= te('audio_reports.day_report.sessions_meta', [
        'finished' => (string)(int)($sessSummary['finished'] ?? 0),
        'interrupted' => (string)(int)($sessSummary['interrupted'] ?? 0),
    ]) ?>
  </p>
  <?php if ($sessions === []): ?>
    <p class="muted"><?= te('audio_reports.day_report.sessions_empty') ?></p>
  <?php else: ?>
    <ul class="ar-day-session-list">
      <?php foreach ($sessions as $sess): ?>
        <?php
          $finished = !empty($sess['finished']);
          $dur = max(0, (int)$sess['dt_end'] - (int)$sess['dt_start']);
        ?>
        <li class="ar-day-session<?= $finished ? '' : ' ar-day-session--interrupted' ?>">
          <div class="ar-day-session-head">
            <?php if ($canOpenCollection): ?>
            <a class="ar-day-session-time" href="<?= e((string)$sess['href']) ?>">
              <?= e(date('H:i', (int)$sess['dt_start'])) ?>–<?= e(date('H:i', (int)$sess['dt_end'])) ?>
            </a>
            <?php else: ?>
            <span class="ar-day-session-time">
              <?= e(date('H:i', (int)$sess['dt_start'])) ?>–<?= e(date('H:i', (int)$sess['dt_end'])) ?>
            </span>
            <?php endif; ?>
            <span class="ar-day-session-name"><?= e((string)$sess['name']) ?></span>
            <span class="ar-day-session-rate">+<?= (int)$sess['score_rate'] ?></span>
            <span class="ar-day-session-dur muted"><?= e($fmtShort($dur)) ?></span>
            <span class="ar-day-session-badge ar-day-session-badge--<?= $finished ? 'ok' : 'warn' ?>">
              <?= $finished
                  ? te('audio_reports.day_report.session_finished')
                  : te('audio_reports.day_report.session_interrupted') ?>
            </span>
          </div>
          <div class="ar-day-chain" role="list">
            <?php foreach (($sess['chain'] ?? []) as $chip): ?>
              <?php $hit = !empty($chip['hit']); ?>
              <span class="ar-day-chip<?= $hit ? ' ar-day-chip--hit' : ' ar-day-chip--miss' ?><?= !empty($chip['finish']) ? ' ar-day-chip--finish' : '' ?>"
                    role="listitem"
                    title="<?= e((string)$chip['name']) ?>">
                <?php if ($hit): ?>
                  <span class="ar-day-chip-name"><?= e((string)$chip['name']) ?></span>
                  <span class="ar-day-chip-pts">+<?= (int)$chip['score_rate'] ?></span>
                <?php else: ?>
                  <span class="ar-day-chip-name"><?= e((string)$chip['name']) ?></span>
                  <span class="ar-day-chip-pts">—</span>
                <?php endif; ?>
              </span>
            <?php endforeach; ?>
          </div>
          <?php
            $proc = is_array($sess['procedure'] ?? null) ? $sess['procedure'] : null;
          ?>
          <?php if ($proc !== null): ?>
            <div class="ar-day-session-proc">
              <span class="ar-day-session-proc-label"><?= te('audio_reports.day_report.session_procedure') ?></span>
              <span class="ar-day-session-proc-id">#<?= (int)$proc['id'] ?></span>
              <span class="ar-day-session-proc-name"><?= e((string)$proc['name']) ?></span>
              <span class="ar-day-session-proc-time">
                <?= e(date('H:i', (int)$proc['dt_start'])) ?>–<?= e(date('H:i', (int)$proc['dt_end'])) ?>
              </span>
              <span class="ar-day-session-proc-summ">
                <?= e(number_format((float)$proc['summ'], 0, '.', ' ')) ?>
              </span>
            </div>
          <?php elseif ($procedures !== null): ?>
            <div class="ar-day-session-proc ar-day-session-proc--none muted">
              <?= te('audio_reports.day_report.session_procedure_none') ?>
            </div>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<?php if (!empty($quality['enabled'])): ?>
<section class="ar-day-quality">
  <h2><?= te('audio_reports.day_report.quality_title') ?></h2>
  <p class="ar-day-quality-meta muted">
    <?= te('audio_reports.day_report.quality_meta', [
        'sessions' => (string)(int)($quality['session_count'] ?? 0),
        'procs' => (string)(int)($quality['procedure_count'] ?? 0),
    ]) ?>
    ·
    <?php if (!empty($quality['bonus_eligible'])): ?>
      <span class="ar-day-quality-bonus ar-day-quality-bonus--ok">
        <?= te('audio_reports.day_report.quality_bonus_ok', ['sum' => (string)(int)($quality['bonus_sum'] ?? 0)]) ?>
      </span>
    <?php else: ?>
      <span class="ar-day-quality-bonus ar-day-quality-bonus--no">
        <?= te('audio_reports.day_report.quality_bonus_no', ['sum' => (string)(int)($quality['bonus_sum_potential'] ?? $quality['bonus_sum'] ?? 0)]) ?>
      </span>
    <?php endif; ?>
    <?php if ((int)($quality['bonus_sum_missed'] ?? 0) > 0 && !empty($quality['bonus_eligible'])): ?>
      <span class="ar-day-quality-bonus ar-day-quality-bonus--no">
        <?= te('audio_reports.day_report.quality_bonus_missed', ['sum' => (string)(int)$quality['bonus_sum_missed']]) ?>
      </span>
    <?php endif; ?>
  </p>
  <?php if (empty($quality['scripts'])): ?>
    <p class="muted"><?= te('audio_reports.day_report.quality_empty') ?></p>
  <?php else: ?>
    <ul class="ar-day-quality-list">
      <?php foreach ($quality['scripts'] as $qs): ?>
        <li class="ar-day-quality-script<?= !empty($qs['has_red']) ? ' ar-day-quality-script--red' : '' ?>">
          <div class="ar-day-quality-script-head">
            <strong><?= e((string)$qs['name']) ?></strong>
            <?php if ((int)($qs['day_bonus_sum'] ?? 0) > 0): ?>
              <?php if (!empty($qs['bonus_eligible'])): ?>
                <span class="ar-day-quality-bonus ar-day-quality-bonus--ok">
                  <?= te('audio_reports.day_report.quality_script_bonus_ok', ['sum' => (string)(int)$qs['day_bonus_sum']]) ?>
                </span>
              <?php else: ?>
                <span class="ar-day-quality-bonus ar-day-quality-bonus--no">
                  <?= te('audio_reports.day_report.quality_script_bonus_no', ['sum' => (string)(int)$qs['day_bonus_sum']]) ?>
                </span>
              <?php endif; ?>
            <?php endif; ?>
            <span class="muted"><?= (int)($qs['session_count'] ?? 0) ?> <?= te('audio_reports.day_report.quality_sessions') ?></span>
            <?php if ((int)($qs['proc_ratio_min'] ?? 0) > 0 && $qs['proc_ratio_pct'] !== null): ?>
              <span class="ar-day-quality-ratio ar-day-quality-ratio--<?= e((string)$qs['proc_ratio_color']) ?>">
                <?= te('audio_reports.day_report.quality_proc_ratio', [
                    'pct' => (string)$qs['proc_ratio_pct'],
                    'min' => (string)(int)$qs['proc_ratio_min'],
                ]) ?>
                <?= e($qualityEmoji((string)$qs['proc_ratio_color'])) ?>
              </span>
            <?php endif; ?>
          </div>
          <ul class="ar-day-quality-parts">
            <?php foreach (($qs['parts'] ?? []) as $qp): ?>
              <li class="ar-day-quality-part ar-day-quality-part--<?= e((string)$qp['color']) ?>">
                <span><?= e((string)$qp['name']) ?></span>
                <span class="muted"><?= (int)$qp['numerator'] ?>/<?= (int)$qp['denominator'] ?> (<?= e((string)$qp['percent']) ?>%)</span>
                <?php if (!empty($qp['is_scoring'])): ?>
                  <span><?= e($qualityEmoji((string)$qp['color'])) ?></span>
                <?php else: ?>
                  <span class="muted"><?= te('audio_reports.day_report.quality_ref') ?></span>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($procedures !== null): ?>
<section class="ar-day-procedures">
  <h2><?= te('audio_reports.day_report.procedures_title') ?></h2>
  <p class="ar-day-procedures-meta muted">
    <?= te('audio_reports.day_report.procedures_meta', [
        'procs' => (string)(int)($procedures['procedure_count'] ?? 0),
        'sessions' => (string)(int)($procedures['session_count'] ?? 0),
        'matched' => (string)(int)($procedures['matched_slots'] ?? 0),
        'orphan' => (string)(int)($procedures['orphan_slots'] ?? 0),
        'min' => (string)(int)($procedures['min_procedure_sec'] ?? 300),
    ]) ?>
  </p>
  <?php if (empty($procedures['slots'])): ?>
    <p class="muted"><?= te('audio_reports.day_report.procedures_empty') ?></p>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data ar-day-proc-table">
      <thead>
        <tr>
          <th><?= te('audio_reports.day_report.col.proc_id') ?></th>
          <th><?= te('audio_reports.day_report.col.proc_name') ?></th>
          <th><?= te('audio_reports.day_report.col.proc_time') ?></th>
          <th><?= te('audio_reports.day_report.col.proc_summ') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($procedures['slots'] as $slot): ?>
          <tr<?= !empty($slot['orphan']) ? ' class="ar-day-proc-row--orphan"' : '' ?>>
            <td><?= (int)$slot['id'] ?></td>
            <td><?= e((string)($slot['name'] ?? ('#' . (int)$slot['id']))) ?></td>
            <td class="ar-day-proc-time">
              <?= e(date('H:i', (int)$slot['dt_start'])) ?>–<?= e(date('H:i', (int)$slot['dt_end'])) ?>
            </td>
            <td class="ar-day-proc-summ"><?= e(number_format((float)$slot['summ'], 0, '.', ' ')) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($gated !== []): ?>
<section class="ar-day-gated">
  <h2><?= te('audio_reports.day_report.gated') ?></h2>
  <ul class="ar-day-gated-list">
    <?php foreach ($gated as $g): ?>
      <li>
        <?php if ($canOpenCollection): ?>
        <a href="/audio/collections/<?= (int)$g['id'] ?>"><?= e((string)$g['script_name']) ?> #<?= (int)$g['id'] ?></a>
        <?php else: ?>
        <span><?= e((string)$g['script_name']) ?> #<?= (int)$g['id'] ?></span>
        <?php endif; ?>
        <span class="ar-day-gated-reason"><?= e((string)$g['gate_reason']) ?></span>
        <span class="muted"><?= e(date('H:i:s', (int)$g['dt_start'])) ?></span>
      </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<?php if (!empty($report['sections_html'])): ?>
  <section class="ar-day-report-sections"><?= $report['sections_html'] ?></section>
<?php endif; ?>
<?php endif; ?>
