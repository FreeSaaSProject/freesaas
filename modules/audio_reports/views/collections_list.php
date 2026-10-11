<?php
declare(strict_types=1);
/**
 * @var list<array<string,mixed>> $items
 * @var int $total
 * @var array{duration:int,score:int,score_intersect:int} $totals
 * @var bool $canEdit
 * @var bool $tablesReady
 * @var string $sort
 * @var string $dir
 * @var int $page
 * @var int $perPage
 * @var bool $showAll
 * @var array $filters
 * @var array|null $bulkFlash
 * @var string $path
 * @var string $listId
 * @var int $year
 * @var int $month
 * @var int $day
 * @var int $admin
 * @var int $script
 * @var array<int,string> $scriptOptions
 * @var array<int,string> $adminOptions
 * @var array|null $dayNav
 * @var array<int, array{html:string,was_intersect:bool}> $transcripts
 */
$showAll = !empty($showAll);
$tablesReady = !empty($tablesReady);
$transcripts = is_array($transcripts ?? null) ? $transcripts : [];
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, $showAll);
$colSpan = 6 + BulkActions::colSpanExtra($listId);
$actionUrl = ListQuery::url($path, $filters, []);
$dayNav = is_array($dayNav ?? null) ? $dayNav : null;
$scriptOptions = is_array($scriptOptions ?? null) ? $scriptOptions : [];
$adminOptions = is_array($adminOptions ?? null) ? $adminOptions : [];
$totals = is_array($totals ?? null) ? $totals : ['duration' => 0, 'score' => 0, 'score_intersect' => 0];

$fmtDur = static function (int $sec): string {
    $sec = max(0, $sec);
    return sprintf('%02d:%02d:%02d', intdiv($sec, 3600), intdiv($sec % 3600, 60), $sec % 60);
};
?>
<?php if ($dayNav): ?>
<nav class="pager ar-col-day-nav" aria-label="<?= te('audio_reports.collections.nav_aria') ?>"
     data-pager-prev="<?= e((string)$dayNav['prev']) ?>"
     data-pager-next="<?= e((string)$dayNav['next']) ?>">
  <a class="btn-secondary" href="<?= e((string)$dayNav['prev']) ?>" title="<?= te('audio_reports.collections.prev') ?>">‹</a>
  <strong><?= e((string)$dayNav['label']) ?></strong>
  <a class="btn-secondary" href="<?= e((string)$dayNav['next']) ?>" title="<?= te('audio_reports.collections.next') ?>">›</a>
  <a class="btn-secondary" href="<?= e((string)$dayNav['yesterday']) ?>"><?= te('audio_reports.collections.yesterday') ?></a>
  <?php if (empty($dayNav['is_today'])): ?>
    <a class="btn-secondary" href="<?= e((string)$dayNav['today']) ?>"><?= te('audio_reports.collections.today') ?></a>
  <?php else: ?>
    <span class="pill"><?= te('audio_reports.collections.today') ?></span>
  <?php endif; ?>
  <a class="btn-secondary" href="<?= e((string)$dayNav['month']) ?>"><?= te('audio_reports.collections.whole_month') ?></a>
</nav>
<?php endif; ?>

<nav class="ar-cal-nav ar-col-filters" aria-label="<?= te('audio_reports.collections.filters_aria') ?>">
  <form method="get" action="<?= e($path) ?>" data-auto-filter>
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
    <label><?= te('audio_reports.collections.day') ?>
      <select name="day">
        <option value="0"<?= $day < 1 ? ' selected' : '' ?>><?= te('audio_reports.collections.day_all') ?></option>
        <?php for ($i = 1; $i <= 31; $i++): ?>
          <option value="<?= $i ?>"<?= $day === $i ? ' selected' : '' ?>"><?= $i ?></option>
        <?php endfor; ?>
      </select>
    </label>
    <label><?= te('audio_reports.calendar.staff') ?>
      <select name="admin">
        <option value="0"><?= te('audio_reports.calendar.staff_all') ?></option>
        <?php foreach ($adminOptions as $sid => $slabel): ?>
          <option value="<?= (int)$sid ?>"<?= $admin === (int)$sid ? ' selected' : '' ?>><?= e($slabel) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= te('audio_reports.collections.script') ?>
      <select name="script">
        <option value="0"><?= te('audio_reports.collections.script_all') ?></option>
        <?php foreach ($scriptOptions as $sid => $slabel): ?>
          <option value="<?= (int)$sid ?>"<?= $script === (int)$sid ? ' selected' : '' ?>><?= e($slabel) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php if (($filters['sort'] ?? '') !== ''): ?>
      <input type="hidden" name="sort" value="<?= e((string)$filters['sort']) ?>">
    <?php endif; ?>
    <?php if (($filters['dir'] ?? '') !== ''): ?>
      <input type="hidden" name="dir" value="<?= e((string)$filters['dir']) ?>">
    <?php endif; ?>
  </form>
</nav>

<?php if (!$tablesReady): ?>
  <p class="flash error"><?= te('audio_reports.collections.tables_missing') ?></p>
<?php endif; ?>
<?php if (!empty($bulkFlash)): ?><?= BulkActions::flashHtml($bulkFlash) ?><?php endif; ?>

<?= $pager ?>
<?= BulkActions::beginForm($listId, $actionUrl) ?>
<?= BulkActions::bar($listId) ?>
<div class="table-wrap">
<table class="data ar-col-table">
<thead><tr>
  <?= BulkActions::th($listId) ?>
  <?= ListQuery::sortTh(t('audio_reports.collections.col.when'), 'dt', $sort, $dir, $path, $filters) ?>
  <th><?= te('audio_reports.collections.col.transcript') ?></th>
  <th><?= te('audio_reports.collections.col.keys') ?></th>
  <th><?= te('audio_reports.collections.col.parts') ?></th>
</tr></thead>
<tbody>
<?php foreach ($items as $c):
    $cid = (int)$c['id'];
    $retQs = array_diff_key($filters, ['page' => true]);
    $href = $path . '/' . $cid;
    if ($retQs !== []) {
        $href .= '?' . http_build_query($retQs);
    }
    $href = e($href);
    $txHtml = (string)($transcripts[$cid]['html'] ?? '');
?>
<tr class="ar-col-row<?= !empty($c['finished']) ? ' is-finished' : '' ?><?= !empty($c['has_intersect']) ? ' is-intersect' : '' ?>">
  <?= BulkActions::td($listId, $cid) ?>
  <td class="ar-col-when">
    <a href="<?= $href ?>"><?= e((string)$c['script_name']) ?></a>
    <div class="ar-col-admin"><?= e((string)$c['admin_name']) ?></div>
    <div><strong><?= e(date('d-m-Y', (int)$c['dt_start'])) ?></strong></div>
    <div class="muted">
      <?= e(date('H:i:s', (int)$c['dt_start'])) ?>
      – <?= e(date('H:i:s', (int)$c['dt_end'])) ?>
      <span class="ar-col-dur">(<?= e($fmtDur((int)$c['duration'])) ?>)</span>
    </div>
    <div class="ar-col-score">
      <a href="<?= $href ?>">#<?= $cid ?></a>
      · <strong><?= te('audio_reports.collections.col.score') ?>: <?= (int)$c['score'] ?></strong>
    </div>
    <?php if (!empty($c['has_intersect'])): ?>
      <div class="ar-col-warn"><?= te('audio_reports.collections.intersect_warn') ?></div>
    <?php endif; ?>
  </td>
  <td class="ar-col-transcript-cell">
    <?php if ($txHtml !== ''): ?>
      <?= $txHtml ?>
    <?php else: ?>
      <span class="muted"><?= te('audio_reports.collections.transcript_unavailable') ?></span>
    <?php endif; ?>
  </td>
  <td class="ar-col-keys">
    <?php if (empty($c['keys'])): ?>
      <span class="muted">—</span>
    <?php else: ?>
      <?php foreach ($c['keys'] as $k): ?>
        <span class="ar-col-key"
              style="<?= $k['color'] !== '' ? 'color:' . e((string)$k['color']) . ';' : '' ?><?= $k['bg'] !== '' ? 'background-color:' . e((string)$k['bg']) . ';' : '' ?>">
          <?= e((string)$k['name']) ?>
          <?php if ($k['dict_name'] !== ''): ?>
            <span class="ar-col-key-dict"><?= e((string)$k['dict_name']) ?></span>
          <?php endif; ?>
        </span>
      <?php endforeach; ?>
    <?php endif; ?>
  </td>
  <td class="ar-col-parts">
    <?php if (empty($c['parts'])): ?>
      <span class="muted">—</span>
    <?php else: ?>
      <?php foreach ($c['parts'] as $p): ?>
        <span class="ar-col-part<?= !empty($p['finish']) ? ' is-finish' : '' ?>"><?= e((string)$p['name']) ?></span>
      <?php endforeach; ?>
    <?php endif; ?>
    <?php if (!empty($c['finished'])): ?>
      <span class="ar-col-finish"><?= te('audio_reports.collections.finish') ?></span>
    <?php endif; ?>
  </td>
</tr>
<?php endforeach; ?>
<?php if (!$items): ?>
  <tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('audio_reports.collections.empty') ?></td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
<?= BulkActions::endForm($listId) ?>
<?= $pager ?>

<?php if ($items): ?>
  <p class="ar-col-totals muted">
    <?= te('audio_reports.collections.totals', [
        'duration' => $fmtDur((int)$totals['duration']),
        'score' => (int)$totals['score'],
        'kill' => (int)$totals['score_intersect'],
    ]) ?>
  </p>
<?php endif; ?>
