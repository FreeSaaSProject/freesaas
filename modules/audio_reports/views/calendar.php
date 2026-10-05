<?php
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
 * @var list<array{month:int,label:string,weeks:list,summaries:list}> $months
 * @var string $prevHref
 * @var string $nextHref
 * @var string $yearHref
 */
$dowKeys = [1, 2, 3, 4, 5, 6, 7];
$badgeFilter = (int)($badgeFilter ?? 0);
$positionFilter = (int)($positionFilter ?? 0);
$badgeOptions = is_array($badgeOptions ?? null) ? $badgeOptions : [];
$positionOptions = is_array($positionOptions ?? null) ? $positionOptions : [];
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

<div class="ar-cal-months">
  <?php foreach ($months as $block): ?>
    <section class="ar-cal-month records-calendar-wrap">
      <table class="records-calendar" aria-label="<?= e((string)$block['label']) ?>">
        <thead>
          <tr>
            <th colspan="7"><?= e((string)$block['label']) ?></th>
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
                                <a class="ar-cal-time" href="<?= e((string)$line['href']) ?>"><?= e((string)$line['playtime_label']) ?></a>
                                <?php if ($score !== null): ?>
                                  <a class="<?= e($scoreCls) ?>" href="<?= e((string)$line['href']) ?>"
                                     title="<?= te('audio_reports.calendar.score_hint') ?>"><?= '®' . e((string)$score) ?></a>
                                <?php endif; ?>
                                <?php if ($line['keys_pct'] !== null): ?>
                                  <span class="ar-cal-keys<?= $line['keys_pct'] >= 24 ? ' is-ok' : ($line['keys_pct'] >= 14 ? ' is-mid' : ' is-low') ?>"
                                        title="<?= te('audio_reports.calendar.keys_hint') ?>"><?= (int)$line['keys_pct'] ?>%</span>
                                <?php endif; ?>
                                <?php foreach ($line['tracking'] as $tr): ?>
                                  <span class="ar-cal-track" title="<?= e((string)$tr['name']) ?>"><?= e((string)$tr['code']) ?>:<?= (int)$tr['count'] ?></span>
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
                    </tr>
                  <?php endforeach; ?>
                  <?php
                    $t = $sum['totals'];
                    $tsc = $t['avg_score'];
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
