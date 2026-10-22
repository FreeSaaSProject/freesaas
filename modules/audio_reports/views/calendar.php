<?php
declare(strict_types=1);
/**
 * Oper-style month grid: days as cells, staff grouped by position inside each day.
 *
 * @var int $year
 * @var int $month
 * @var int $adminFilter
 * @var int $badgeFilter
 * @var int $positionFilter
 * @var array<int,string> $staffOptions
 * @var array<string,string> $badgeOptions
 * @var array<int,string> $positionOptions
 * @var list<array{id:int,name:string,code:string}> $trackingDicts
 * @var list<array{id:int,name:string,short:string}> $calendarScripts
 * @var list<array{month:int,label:string,weeks:list,summaries:list}> $months
 * @var string $prevHref
 * @var string $nextHref
 * @var string $yearHref
 * @var bool $canAssign
 * @var bool $canReparse
 * @var int $inboxCount
 * @var string $inboxPath
 * @var bool $canIngest
 * @var string $openShiftDay
 * @var string $calRet
 * @var string $shiftFlash
 * @var string $reparseFlash
 * @var bool $canReviewAdmin
 * @var bool $canReviewMutate
 * @var int $pendingReviewTotal
 * @var string $reviewFlash
 * @var string $reviewOrphansFlash
 */
$dowKeys = [1, 2, 3, 4, 5, 6, 7];
$badgeFilter = (int)($badgeFilter ?? 0);
$positionFilter = (int)($positionFilter ?? 0);
$badgeOptions = is_array($badgeOptions ?? null) ? $badgeOptions : [];
$positionOptions = is_array($positionOptions ?? null) ? $positionOptions : [];
$calendarScripts = is_array($calendarScripts ?? null) ? $calendarScripts : [];
$canAssign = !empty($canAssign);
$canReparse = !empty($canReparse);
$openShiftDay = (string)($openShiftDay ?? '');
$calRet = (string)($calRet ?? '/audio/calendar');
$shiftFlash = (string)($shiftFlash ?? '');
$reparseFlash = (string)($reparseFlash ?? '');
$canReviewAdmin = !empty($canReviewAdmin);
$canReviewMutate = !empty($canReviewMutate);
$pendingReviewTotal = (int)($pendingReviewTotal ?? 0);
$reviewFlash = (string)($reviewFlash ?? '');
$reviewOrphansFlash = (string)($reviewOrphansFlash ?? '');
?>
<?php if ($shiftFlash !== ''): ?>
  <div class="flash flash-ok"><?= e($shiftFlash) ?></div>
<?php endif; ?>
<?php if ($reparseFlash !== ''): ?>
  <div class="flash <?= !empty($_GET['reparse_ok']) ? 'flash-ok' : 'flash-error' ?>"><?= e($reparseFlash) ?></div>
<?php endif; ?>
<?php if ($reviewFlash !== ''): ?>
  <div class="flash flash-ok"><?= e($reviewFlash) ?></div>
<?php endif; ?>
<?php if ($reviewOrphansFlash !== ''): ?>
  <div class="flash flash-ok"><?= e($reviewOrphansFlash) ?></div>
<?php endif; ?>
<?php
$inboxCount = (int)($inboxCount ?? 0);
$inboxPath = (string)($inboxPath ?? '/audio');
$canIngest = !empty($canIngest);
if ($inboxCount > 0) {
    Assets::need('audio');
    require base_path('modules/audio/views/_inbox_banner.php');
}
?>
<nav class="ar-cal-nav" data-calendar-nav="audio-month"
     data-prev="<?= e($prevHref) ?>"
     data-next="<?= e($nextHref) ?>"
     aria-label="<?= te('audio_reports.calendar.nav_aria') ?>">
  <a class="btn-secondary" href="<?= e($prevHref) ?>" title="<?= te('audio_reports.calendar.prev') ?>" aria-label="<?= te('audio_reports.calendar.prev') ?>">«</a>
  <a class="btn-secondary" href="<?= e($nextHref) ?>" title="<?= te('audio_reports.calendar.next') ?>" aria-label="<?= te('audio_reports.calendar.next') ?>">»</a>
  <a class="btn-secondary" href="<?= e($yearHref) ?>"><?= te('audio_reports.calendar.whole_year') ?></a>
  <span class="muted ar-cal-nav-hint"><?= te('audio_reports.calendar.keyboard') ?></span>
  <form method="get" action="/audio/calendar" data-auto-filter>
    <label><?= te('audio_reports.calendar.year') ?>
      <input type="number" name="year" value="<?= (int)$year ?>" min="2000" max="2100" data-filter-change>
    </label>
    <label><?= te('audio_reports.calendar.month') ?>
      <select name="month">
        <option value="0"<?= $month === 0 ? ' selected' : '' ?>><?= te('audio_reports.calendar.whole_year') ?></option>
        <?php for ($i = 1; $i <= 12; $i++): ?>
          <option value="<?= $i ?>"<?= $month === $i ? ' selected' : '' ?>><?= e(t('audio_reports.calendar.month.' . $i)) ?></option>
        <?php endfor; ?>
      </select>
    </label>
    <label><?= te('audio_reports.calendar.staff') ?>
      <select name="admin">
        <option value="0"><?= te('audio_reports.calendar.staff_all') ?></option>
        <?php foreach ($staffOptions as $sid => $slabel): ?>
          <option value="<?= (int)$sid ?>"<?= $adminFilter === (int)$sid ? ' selected' : '' ?>><?= e($slabel) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= te('audio_reports.calendar.badge') ?>
      <select name="badge">
        <option value="0"><?= te('audio_reports.calendar.badge_all') ?></option>
        <?php foreach ($badgeOptions as $code => $blabel): ?>
          <option value="<?= e((string)$code) ?>"<?= (string)$badgeFilter === (string)$code ? ' selected' : '' ?>><?= e($blabel) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= te('audio_reports.calendar.position') ?>
      <select name="position">
        <option value="0"><?= te('audio_reports.calendar.position_all') ?></option>
        <?php foreach ($positionOptions as $pid => $plabel): ?>
          <option value="<?= (int)$pid ?>"<?= $positionFilter === (int)$pid ? ' selected' : '' ?>><?= e($plabel) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </form>
</nav>

<?php if ($canAssign): ?>
  <div class="ar-cal-shift-actions">
    <button type="button" class="btn-primary" data-shift-open="all"
            title="<?= te('workweeks.nazop.title_all') ?>"><?= te('workweeks.nazop.open_all') ?></button>
    <span class="muted"><?= te('workweeks.nazop.open_all_hint') ?></span>
  </div>
<?php endif; ?>

<div class="ar-cal-months">
  <?php foreach ($months as $block): ?>
    <section class="ar-cal-month records-calendar-wrap">
      <table class="records-calendar" aria-label="<?= e((string)$block['label']) ?>">
        <thead>
          <tr>
            <th colspan="7">
              <div class="ar-cal-month-head">
                <span><?= e((string)$block['label']) ?></span>
                <?php if ($canAssign): ?>
                  <button type="button" class="btn-secondary btn-sm" data-shift-open="all"
                          title="<?= te('workweeks.nazop.title_all') ?>"><?= te('workweeks.nazop.open_all') ?></button>
                <?php endif; ?>
              </div>
            </th>
          </tr>
          <tr class="days">
            <?php foreach ($dowKeys as $dowN): ?>
              <th><?= te('audio_reports.calendar.dow.' . $dowN) ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($block['weeks'] as $week): ?>
            <tr class="week">
              <?php for ($i = 0; $i < 7; $i++):
                  $cell = $week[$i] ?? ['empty' => true, 'dow' => $i];
                  $isEmptyPad = !empty($cell['empty']) && empty($cell['day']);
                  $hasData = !empty($cell['has_data']);
                  $cls = 'cal-day-cell';
                  if ($isEmptyPad || !$hasData) {
                      $cls .= ' cal-day-cell--empty';
                  }
                  if (!empty($cell['is_today'])) {
                      $cls .= ' cal-day-cell--today';
                  }
                  $dow = (int)($cell['dow'] ?? $i);
                  $weekend = $dow >= 5;
                  ?>
                <td class="<?= e($cls) ?>">
                  <div class="cal-day-inner">
                    <?php if ($isEmptyPad): ?>
                      <span class="cal-day-num">
                        <span class="cal-day-dw<?= $weekend ? ' cal-day-dw--weekend' : '' ?>"><?= te('audio_reports.calendar.dow.' . ($dow + 1)) ?></span>
                      </span>
                    <?php else: ?>
                      <div class="cal-day-num">
                        <a href="<?= e((string)$cell['href']) ?>"><?= (int)$cell['day'] ?></a>
                        <span class="cal-day-dw<?= $weekend ? ' cal-day-dw--weekend' : '' ?>"><?= te('audio_reports.calendar.dow.' . ($dow + 1)) ?></span>
                        <?php if ($canAssign): ?>
                          <button type="button"
                                  class="ar-cal-nazop"
                                  data-shift-open="<?= e((string)$cell['date']) ?>"
                                  title="<?= te('workweeks.nazop.open_day') ?>"><?= te('workweeks.nazop.mark') ?></button>
                        <?php endif; ?>
                        <?php if (!empty($canReparse) && !empty($cell['has_records'])): ?>
                          <a class="ar-cal-reparse"
                             href="<?= e('/audio/calendar/reparse?date=' . rawurlencode((string)$cell['date']) . '&ret=' . rawurlencode($calRet)) ?>"
                             target="_blank"
                             rel="noopener"
                             title="<?= te('audio_reports.calendar.reparse_hint') ?>"
                             onclick="return confirm(<?= tj('audio_reports.calendar.reparse_confirm') ?>);"><?= te('audio_reports.calendar.reparse') ?></a>
                        <?php endif; ?>
                      </div>
                      <?php if (!empty($cell['extras_html'])): ?>
                        <div class="ar-cal-day-extras"><?= $cell['extras_html'] ?></div>
                      <?php endif; ?>
                      <?php if (!empty($cell['staff_groups'])): ?>
                        <div class="cal-day-body">
                          <?php foreach ($cell['staff_groups'] as $group): ?>
                            <?php if (count($cell['staff_groups']) > 1): ?>
                              <div class="ar-cal-pos-label"><?= e((string)$group['position_name']) ?></div>
                            <?php endif; ?>
                            <?php foreach ($group['lines'] as $li => $line):
                                $score = $line['avg_score'];
                                $scoreCls = 'ar-cal-rate';
                                if ($score !== null && $score > 4) {
                                    $scoreCls .= ' ar-cal-rate--ok';
                                } elseif ($score !== null && $score < 2) {
                                    $scoreCls .= ' ar-cal-rate--low';
                                }
                                $lineCls = 'ar-cal-staff-line';
                                if ($li === 0 && count($cell['staff_groups']) > 1) {
                                    $lineCls .= ' ar-cal-staff-line--after-pos';
                                }
                                ?>
                              <div class="<?= e($lineCls) ?>">
                                <?= $line['badge_html'] ?>
                                <?php if (!empty($line['missing'])):
                                    $miss = $line['missing'];
                                    $missCls = $miss['kind'] === 'waiting' ? 'ar-cal-waiting' : 'ar-cal-norec';
                                    if ($miss['kind'] !== 'waiting' && $canAssign): ?>
                                  <button type="button" class="<?= e($missCls) ?>"
                                          data-shift-open="<?= e((string)$cell['date']) ?>"
                                          title="<?= e((string)$miss['title']) ?>"><?= e((string)$miss['label']) ?></button>
                                    <?php else: ?>
                                  <span class="<?= e($missCls) ?>" title="<?= e((string)$miss['title']) ?>"><?= e((string)$miss['label']) ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                <a class="ar-cal-time" href="<?= e((string)$line['href']) ?>"><?= e((string)$line['playtime_label']) ?></a>
                                <?php if ($canReviewAdmin && !empty($line['review_pending']) && !empty($line['review_pending_href'])): ?>
                                  <a class="ar-cal-review-pending" href="<?= e((string)$line['review_pending_href']) ?>"
                                     target="_blank" rel="noopener"
                                     title="<?= te('audio_reports.calendar.review_pending_hint') ?>"><?= te('audio_reports.calendar.review_pending_link') ?></a>
                                <?php endif; ?>
                                <?php if ($score !== null):
                                    $scoreHref = (string)($line['report_href'] ?? $line['href'] ?? '');
                                    ?>
                                  <a class="<?= e($scoreCls) ?>" href="<?= e($scoreHref) ?>"
                                     title="<?= te('audio_reports.calendar.score_hint') ?>"><?= '®' . e((string)$score) ?></a>
                                <?php endif; ?>
                                <?php if ($line['keys_pct'] !== null): ?>
                                  <span class="ar-cal-keys<?= $line['keys_pct'] >= 24 ? ' is-ok' : ($line['keys_pct'] >= 14 ? ' is-mid' : ' is-low') ?>"
                                        title="<?= te('audio_reports.calendar.keys_hint') ?>"><?= (int)$line['keys_pct'] ?>%</span>
                                <?php endif; ?>
                                <?php foreach ($line['tracking'] as $tr): ?>
                                  <span class="ar-cal-track" title="<?= e((string)$tr['name']) ?>"><?= e((string)$tr['code']) ?>:<?= (int)$tr['count'] ?></span>
                                <?php endforeach; ?>
                                <?php foreach ($line['scripts'] as $scr):
                                    $scrHref = (string)($scr['href'] ?? '');
                                    $scrTitle = (string)$scr['name'] . ' — ' . t('audio_reports.calendar.script_hint');
                                    if ($scrHref !== ''): ?>
                                  <a class="ar-cal-script" href="<?= e($scrHref) ?>" title="<?= e($scrTitle) ?>"><?= e((string)$scr['label']) ?></a>
                                    <?php else: ?>
                                  <span class="ar-cal-script" title="<?= e($scrTitle) ?>"><?= e((string)$scr['label']) ?></span>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <?php if ((int)$line['agg']['outside'] > 0): ?>
                                  <span class="ar-cal-outside" title="<?= te('audio_reports.calendar.outside_hint') ?>">↗<?= e(CalendarAggregates::formatPlaytime((int)$line['agg']['outside'])) ?></span>
                                <?php endif; ?>
                                <?php if ((int)$line['agg']['errors'] > 0): ?>
                                  <span class="ar-cal-err">✕<?= (int)$line['agg']['errors'] ?></span>
                                <?php endif; ?>
                                <?php if (!empty($line['extra_html'])): ?>
                                  <span class="ar-cal-hook"><?= $line['extra_html'] ?></span>
                                <?php endif; ?>
                                <?php endif; ?>
                              </div>
                            <?php endforeach; ?>
                          <?php endforeach; ?>
                        </div>
                      <?php endif; ?>
                    <?php endif; ?>
                  </div>
                </td>
              <?php endfor; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <?php if (!empty($block['summaries'])): ?>
        <div class="ar-cal-summary">
          <h3 class="ar-cal-summary-title"><?= te('audio_reports.calendar.summary_title', ['month' => (string)$block['label']]) ?></h3>
          <?php foreach ($block['summaries'] as $sum): ?>
            <h4 class="ar-cal-summary-pos"><?= e((string)$sum['position_name']) ?></h4>
            <div class="ar-cal-summary-scroll">
              <table class="ar-cal-summary-table">
                <thead>
                  <tr>
                    <th><?= te('audio_reports.calendar.col_staff') ?></th>
                    <th><?= te('audio_reports.calendar.col_hours') ?></th>
                    <th><?= te('audio_reports.calendar.col_records') ?></th>
                    <th><?= te('audio_reports.calendar.col_score') ?></th>
                    <th><?= te('audio_reports.calendar.col_keys') ?></th>
                    <?php foreach ($trackingDicts as $td): ?>
                      <th class="ar-cal-track-th" title="<?= e($td['name']) ?>"><?= e($td['code']) ?></th>
                    <?php endforeach; ?>
                    <?php foreach ($calendarScripts as $sc): ?>
                      <th class="ar-cal-script-th" title="<?= e($sc['name']) ?>"><?= e($sc['short']) ?></th>
                    <?php endforeach; ?>
                    <?php if ($calendarScripts !== []): ?>
                      <th class="ar-cal-script-th" title="<?= te('audio_reports.calendar.col_script_score') ?>"><?= te('audio_reports.calendar.col_script_score_short') ?></th>
                    <?php endif; ?>
                    <?php foreach (($block['extra_columns'] ?? []) as $ecol): ?>
                      <th class="ar-cal-extra-th" title="<?= e((string)($ecol['title'] ?? $ecol['label'] ?? '')) ?>"><?= e((string)($ecol['label'] ?? '')) ?></th>
                    <?php endforeach; ?>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($sum['rows'] as $row):
                      $sc = $row['avg_score'];
                      $scCls = '';
                      if ($sc !== null && $sc > 3.9) {
                          $scCls = ' ar-cal-rate--ok';
                      }
                      ?>
                    <tr>
                      <td><?= $row['badge_html'] ?></td>
                      <td><a href="<?= e((string)$row['href']) ?>"><?= e((string)$row['hours']) ?></a></td>
                      <td><?= (int)$row['count'] ?></td>
                      <td><?php if ($sc !== null): ?><span class="ar-cal-rate<?= e($scCls) ?>">®<?= e((string)$sc) ?></span><?php else: ?>—<?php endif; ?></td>
                      <td><?php if ($row['keys_pct'] !== null): ?><span class="ar-cal-keys<?= $row['keys_pct'] >= 24 ? ' is-ok' : ($row['keys_pct'] >= 14 ? ' is-mid' : ' is-low') ?>"><?= (int)$row['keys_pct'] ?>%</span><?php else: ?>—<?php endif; ?></td>
                      <?php foreach ($trackingDicts as $td): ?>
                        <td class="ar-cal-track"><?= (int)($row['tracking'][$td['id']] ?? 0) ?: '' ?></td>
                      <?php endforeach; ?>
                      <?php foreach ($calendarScripts as $scDef):
                          $scell = $row['scripts'][$scDef['id']] ?? null;
                          $label = $scell ? CalendarAggregates::formatScriptSummary($scell) : '';
                          $scrHref = (string)(($row['script_hrefs'][$scDef['id']] ?? '') ?: '');
                          ?>
                        <td class="ar-cal-script"><?php if ($label !== '' && $scrHref !== ''): ?><a class="ar-cal-script" href="<?= e($scrHref) ?>" title="<?= e($scDef['name']) ?>"><?= e($label) ?></a><?php else: ?><?= e($label) ?><?php endif; ?></td>
                      <?php endforeach; ?>
                      <?php if ($calendarScripts !== []):
                          $scoreVal = (int)($row['script_score'] ?? 0);
                          $scoreHref = (string)($row['script_score_href'] ?? '');
                          ?>
                        <td class="ar-cal-script"><?php if ($scoreVal > 0 && $scoreHref !== ''): ?><a class="ar-cal-script" href="<?= e($scoreHref) ?>" title="<?= te('audio_reports.calendar.col_script_score') ?>"><?= $scoreVal ?></a><?php else: ?><?= $scoreVal ?: '' ?><?php endif; ?></td>
                      <?php endif; ?>
                      <?php
                        $extraCells = is_array($row['extra_cells'] ?? null) ? $row['extra_cells'] : [];
                        foreach (($block['extra_columns'] ?? []) as $ecol):
                            $cid = (string)($ecol['id'] ?? '');
                            ?>
                        <td class="ar-cal-extra"><?= e((string)($extraCells[$cid] ?? '')) ?></td>
                      <?php endforeach; ?>
                    </tr>
                  <?php endforeach; ?>
                  <?php
                    $t = $sum['totals'];
                    $tsc = $t['avg_score'];
                    $totExtra = is_array($t['extra_cells'] ?? null) ? $t['extra_cells'] : [];
                  ?>
                  <tr class="ar-cal-summary-total">
                    <td><?= te('audio_reports.calendar.col_total') ?></td>
                    <td><?= e((string)$t['hours']) ?></td>
                    <td><?= (int)$t['count'] ?></td>
                    <td><?= $tsc !== null ? ('®' . e((string)$tsc)) : '—' ?></td>
                    <td><?= $t['keys_pct'] !== null ? ((int)$t['keys_pct'] . '%') : '—' ?></td>
                    <?php foreach ($trackingDicts as $td): ?>
                      <td><?= (int)($t['tracking'][$td['id']] ?? 0) ?: '' ?></td>
                    <?php endforeach; ?>
                    <?php foreach ($calendarScripts as $scDef):
                        $scell = $t['scripts'][$scDef['id']] ?? null;
                        $label = $scell ? CalendarAggregates::formatScriptSummary($scell) : '';
                        $scrHref = (string)(($t['script_hrefs'][$scDef['id']] ?? '') ?: '');
                        ?>
                      <td><?php if ($label !== '' && $scrHref !== ''): ?><a class="ar-cal-script" href="<?= e($scrHref) ?>" title="<?= e($scDef['name']) ?>"><?= e($label) ?></a><?php else: ?><?= e($label) ?><?php endif; ?></td>
                    <?php endforeach; ?>
                    <?php if ($calendarScripts !== []):
                        $scoreVal = (int)($t['script_score'] ?? 0);
                        $scoreHref = (string)($t['script_score_href'] ?? '');
                        ?>
                      <td><?php if ($scoreVal > 0 && $scoreHref !== ''): ?><a class="ar-cal-script" href="<?= e($scoreHref) ?>" title="<?= te('audio_reports.calendar.col_script_score') ?>"><?= $scoreVal ?></a><?php else: ?><?= $scoreVal ?: '' ?><?php endif; ?></td>
                    <?php endif; ?>
                    <?php foreach (($block['extra_columns'] ?? []) as $ecol):
                        $cid = (string)($ecol['id'] ?? '');
                        ?>
                      <td><?= e((string)($totExtra[$cid] ?? '')) ?></td>
                    <?php endforeach; ?>
                  </tr>
                </tbody>
              </table>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>
</div>

<?php if ($canReviewAdmin && $pendingReviewTotal > 0): ?>
  <section class="ar-cal-review-admin">
    <h3><?= te('audio_reports.calendar.review_admin_title') ?></h3>
    <p><?= te('audio_reports.calendar.review_admin_count', ['n' => $pendingReviewTotal]) ?></p>
    <p class="ar-cal-review-admin-actions">
      <a class="btn-secondary" href="<?= e(NavContext::withReturn('/audio?review=pending&year=0&month=0&day=0')) ?>"
         target="_blank" rel="noopener"><?= te('audio_reports.calendar.review_admin_open') ?></a>
      <?php if ($canReviewMutate): ?>
      <form method="post" action="/audio/calendar" class="inline-form"
            onsubmit="return confirm(<?= tj('audio_reports.calendar.review_all_confirm', ['n' => $pendingReviewTotal]) ?>);">
        <?= NavContext::hiddenField() ?>
        <input type="hidden" name="review_action" value="mark_all">
        <input type="hidden" name="year" value="<?= (int)$year ?>">
        <input type="hidden" name="month" value="<?= (int)$month ?>">
        <?php if ($adminFilter > 0): ?><input type="hidden" name="admin" value="<?= (int)$adminFilter ?>"><?php endif; ?>
        <?php if ($badgeFilter > 0): ?><input type="hidden" name="badge" value="<?= (int)$badgeFilter ?>"><?php endif; ?>
        <?php if ($positionFilter > 0): ?><input type="hidden" name="position" value="<?= (int)$positionFilter ?>"><?php endif; ?>
        <button type="submit" class="btn-secondary"><?= te('audio_reports.calendar.review_all_btn', ['n' => $pendingReviewTotal]) ?></button>
      </form>
      <form method="post" action="/audio/calendar" class="inline-form">
        <?= NavContext::hiddenField() ?>
        <input type="hidden" name="review_action" value="clean_orphans">
        <input type="hidden" name="year" value="<?= (int)$year ?>">
        <input type="hidden" name="month" value="<?= (int)$month ?>">
        <?php if ($adminFilter > 0): ?><input type="hidden" name="admin" value="<?= (int)$adminFilter ?>"><?php endif; ?>
        <?php if ($badgeFilter > 0): ?><input type="hidden" name="badge" value="<?= (int)$badgeFilter ?>"><?php endif; ?>
        <?php if ($positionFilter > 0): ?><input type="hidden" name="position" value="<?= (int)$positionFilter ?>"><?php endif; ?>
        <button type="submit" class="btn-secondary"><?= te('audio_reports.calendar.review_clean_orphans') ?></button>
      </form>
      <?php endif; ?>
    </p>
  </section>
<?php endif; ?>

<?php if ($canReparse):
    $bulkFrom = sprintf('%04d-%02d-01', (int)$year, max(1, (int)$month ?: (int)date('n')));
    $bulkTo = date('Y-m-d', mktime(0, 0, 0, max(1, (int)$month ?: (int)date('n')), (int)date('t', mktime(0, 0, 0, max(1, (int)$month ?: (int)date('n')), 1, (int)$year)), (int)$year) ?: time());
    if ((int)$month < 1) {
        $bulkFrom = sprintf('%04d-01-01', (int)$year);
        $bulkTo = sprintf('%04d-12-31', (int)$year);
    }
?>
  <section class="ar-cal-bulk-reparse"
           id="ar-bulk-reparse"
           data-endpoint="/audio/calendar/reparse"
           data-i18n="<?= e(json_encode([
               'warn' => t('audio_reports.calendar.bulk_reparse_warn'),
               'confirm' => t('audio_reports.calendar.bulk_reparse_confirm'),
               'busy' => t('audio_reports.calendar.bulk_reparse_busy'),
               'badRange' => t('audio_reports.calendar.bulk_reparse_bad_range'),
               'empty' => t('audio_reports.calendar.bulk_reparse_empty'),
               'running' => t('audio_reports.calendar.bulk_reparse_running'),
               'done' => t('audio_reports.calendar.bulk_reparse_done'),
               'dayOk' => t('audio_reports.calendar.bulk_reparse_day_ok'),
               'dayErr' => t('audio_reports.calendar.bulk_reparse_day_err'),
               'dayStem' => t('audio_reports.calendar.bulk_reparse_day_stem'),
               'dayStart' => t('audio_reports.calendar.bulk_reparse_day_start'),
           ], JSON_UNESCAPED_UNICODE) ?: '{}') ?>">
    <h3><?= te('audio_reports.calendar.bulk_reparse_title') ?></h3>
    <p class="ar-cal-bulk-reparse-warn"><?= te('audio_reports.calendar.bulk_reparse_warn') ?></p>
    <div class="ar-cal-bulk-reparse-row">
      <label><?= te('audio_reports.calendar.bulk_reparse_from') ?>
        <input type="date" id="ar-bulk-from" value="<?= e($bulkFrom) ?>">
      </label>
      <label><?= te('audio_reports.calendar.bulk_reparse_to') ?>
        <input type="date" id="ar-bulk-to" value="<?= e($bulkTo) ?>">
      </label>
      <button type="button" class="btn-primary" id="ar-bulk-start"><?= te('audio_reports.calendar.bulk_reparse_start') ?></button>
    </div>
    <div class="ar-cal-bulk-reparse-progress" id="ar-bulk-progress" hidden>
      <div class="ar-cal-bulk-reparse-meta">
        <b><?= te('audio_reports.calendar.bulk_reparse_progress') ?></b>
        <span id="ar-bulk-current">—</span>
        (<span>(<span id="ar-bulk-done">0</span> / <span id="ar-bulk-total">0</span>)</span>
      </div>
      <div class="ar-cal-bulk-reparse-bar-wrap">
        <div class="ar-cal-bulk-reparse-bar" id="ar-bulk-bar" style="width:0%"></div>
      </div>
      <div class="ar-cal-bulk-reparse-log" id="ar-bulk-log"></div>
      <div class="ar-cal-bulk-reparse-status" id="ar-bulk-status"></div>
    </div>
  </section>
<?php endif; ?>

<script>
(function () {
  var nav = document.querySelector('[data-calendar-nav="audio-month"]');
  if (!nav) return;
  function typingTarget(el) {
    if (!el || !el.tagName) return false;
    var tag = el.tagName.toLowerCase();
    if (tag === 'input' || tag === 'textarea' || tag === 'select') return true;
    if (el.isContentEditable) return true;
    return !!(el.closest && el.closest('[contenteditable="true"]'));
  }
  document.addEventListener('keydown', function (e) {
    if (e.defaultPrevented || e.altKey || e.ctrlKey || e.metaKey) return;
    if (typingTarget(e.target)) return;
    var href = null;
    if (e.key === 'ArrowLeft') href = nav.getAttribute('data-prev');
    if (e.key === 'ArrowRight') href = nav.getAttribute('data-next');
    if (!href) return;
    e.preventDefault();
    if (window.FreesaasNav) {
      window.FreesaasNav.start();
      requestAnimationFrame(function () { location.href = href; });
    } else {
      location.href = href;
    }
  });
})();
</script>

<?php if ($canAssign):
    $retUrl = $calRet;
    $dialogId = 'audio-shift-dialog';
    require base_path('modules/workweeks/views/_nazop_dialog.php');
endif; ?>
