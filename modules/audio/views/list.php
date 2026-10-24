<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var string $q */
/** @var string $search */
/** @var int $admin */
/** @var int $badge */
/** @var int $year */
/** @var int $month */
/** @var int $day */
/** @var int $disabled */
/** @var int $score */
/** @var int $scoreLt */
/** @var int $errorsOnly */
/** @var int $unparsed */
/** @var int $preload */
/** @var int $rangeFrom */
/** @var int $rangeTo */
/** @var array<int,array{name:string,color:string,bg:string}> $dictMeta */
/** @var array<int,array{html:string,lines:int,trash:int,keys:int}> $transcriptById */
/** @var array<int,string> $playUrlById */
/** @var array<int,string> $downloadUrlById */
/** @var array<string,string> $badgeOptions */
/** @var array<int,string> $keyDictOptions */
/** @var array<int,array<int,string>> $keyDictsByAdmin */
/** @var bool $dictsOn */
/** @var int $trashAdded */
/** @var array<string,mixed>|null $dayNav */
/** @var bool $canEdit */
/** @var mixed $bulkFlash */
/** @var array $pageStats */
/** @var string $sort */
/** @var string $dir */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */
/** @var string $review */
/** @var array<int,array{dt_create:int,dt_review:int,dt_select:int,id_reviewer:int}> $reviewById */
/** @var int $currentUserId */
$path = '/audio';
$review = trim((string)($review ?? ''));
$reviewById = is_array($reviewById ?? null) ? $reviewById : [];
$currentUserId = (int)($currentUserId ?? 0);
$listId = 'audio_records';
$showAll = !empty($showAll);
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, $showAll);
$dictMeta = is_array($dictMeta ?? null) ? $dictMeta : [];
$transcriptById = is_array($transcriptById ?? null) ? $transcriptById : [];
$playUrlById = is_array($playUrlById ?? null) ? $playUrlById : [];
$downloadUrlById = is_array($downloadUrlById ?? null) ? $downloadUrlById : [];
$preload = !empty($preload);
$badgeOptions = is_array($badgeOptions ?? null) ? $badgeOptions : [];
$keyDictOptions = is_array($keyDictOptions ?? null) ? $keyDictOptions : [];
$keyDictsByAdmin = is_array($keyDictsByAdmin ?? null) ? $keyDictsByAdmin : [];
$dayNav = is_array($dayNav ?? null) ? $dayNav : null;
$canEdit = !empty($canEdit);
$dictsOn = !empty($dictsOn);
$colSpan = 2 + BulkActions::colSpanExtra($listId);

$rangeClearFilters = $filters;
unset($rangeClearFilters['range_from'], $rangeClearFilters['range_to'], $rangeClearFilters['page']);
$rangeClearUrl = ListQuery::url($path, $rangeClearFilters, ['page' => 1]);
?>
<?php if ($dayNav): ?>
<nav class="pager audio-day-nav" aria-label="<?= te('audio.day_nav.aria') ?>"
     data-pager-prev="<?= e((string)$dayNav['prev']) ?>"
     data-pager-next="<?= e((string)$dayNav['next']) ?>"
     data-audio-day-nav>
  <a class="btn-secondary" href="<?= e((string)$dayNav['prev']) ?>" title="<?= te('audio.day_nav.prev') ?>">‹</a>
  <strong><?= e((string)$dayNav['label']) ?></strong>
  <a class="btn-secondary" href="<?= e((string)$dayNav['next']) ?>" title="<?= te('audio.day_nav.next') ?>">›</a>
  <?php if (empty($dayNav['is_today'])): ?>
    <a class="btn-secondary" href="<?= e((string)$dayNav['today']) ?>"><?= te('audio.day_nav.today') ?></a>
  <?php else: ?>
    <span class="pill"><?= te('audio.day_nav.today') ?></span>
  <?php endif; ?>
  <a class="btn-secondary" href="<?= e((string)$dayNav['month']) ?>"><?= te('audio.day_nav.month') ?></a>
  <span class="muted audio-day-nav-hint"><?= te('audio.day_nav.keyboard') ?></span>
</nav>
<?php endif; ?>

<?php
$inboxCount = (int)($inboxCount ?? 0);
$inboxPath = (string)($inboxPath ?? '/audio');
$canIngest = !empty($canIngest);
require __DIR__ . '/_inbox_banner.php';
?>

<?php if (!empty($trashAdded)): ?>
  <p class="flash"><?= te('audio.trash.auto_added', ['n' => (int)$trashAdded]) ?></p>
<?php endif; ?>
<?php if (!empty($bulkFlash)): ?>
  <?= BulkActions::flashHtml($bulkFlash) ?>
<?php endif; ?>
<?php if ($rangeFrom > 0 || $rangeTo > 0): ?>
  <p class="flash audio-range-flash">
    <?= te('audio.range.active', [
      'from' => $rangeFrom > 0 ? date('H:i:s', $rangeFrom) : '…',
      'to' => $rangeTo > 0 ? date('H:i:s', $rangeTo) : '…',
    ]) ?>
    <a href="<?= e($rangeClearUrl) ?>"><?= te('audio.range.clear') ?></a>
  </p>
<?php endif; ?>

<?php if (!empty($moderationReady) && class_exists('View', false)): ?>
  <?= View::capture('_review_moderation_panel', [
      'canModerate' => !empty($canModerate),
      'year' => (int)$year,
      'month' => (int)$month,
      'day' => (int)$day,
      'admin' => (int)$admin,
  ], 'audio_llm') ?>
<?php endif; ?>

<form class="filters audio-filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <?php if ($rangeFrom > 0): ?><input type="hidden" name="range_from" value="<?= (int)$rangeFrom ?>"><?php endif; ?>
  <?php if ($rangeTo > 0): ?><input type="hidden" name="range_to" value="<?= (int)$rangeTo ?>"><?php endif; ?>
  <?php
    $yearNow = (int)date('Y');
    $yearFrom = $yearNow - 8;
    if ((int)$year > 0) {
        $yearFrom = min($yearFrom, (int)$year);
    }
    $yearTo = max($yearNow + 1, (int)$year > 0 ? (int)$year : $yearNow);
    $daysInMonth = (int)$month >= 1 && (int)$month <= 12
      ? (int)date('t', mktime(0, 0, 0, (int)$month, 1, max(1970, (int)$year > 0 ? (int)$year : $yearNow)))
      : 31;
  ?>
  <label><?= te('audio.filter.year') ?>
    <select name="year">
      <option value="0"<?= (int)$year < 1 ? ' selected' : '' ?>><?= te('audio.filter.year_all') ?></option>
      <?php for ($y = $yearTo; $y >= max(1970, $yearFrom); $y--): ?>
        <option value="<?= $y ?>"<?= (int)$year === $y ? ' selected' : '' ?>><?= $y ?></option>
      <?php endfor; ?>
    </select>
  </label>
  <label><?= te('audio.filter.month') ?>
    <select name="month">
      <option value="0"<?= (int)$month < 1 ? ' selected' : '' ?>><?= te('audio.filter.month_all') ?></option>
      <?php for ($m = 1; $m <= 12; $m++): ?>
        <option value="<?= $m ?>"<?= (int)$month === $m ? ' selected' : '' ?>><?= sprintf('%02d', $m) ?></option>
      <?php endfor; ?>
    </select>
  </label>
  <label><?= te('audio.filter.day') ?>
    <select name="day">
      <option value="0"<?= (int)$day < 1 ? ' selected' : '' ?>><?= te('audio.filter.day_all') ?></option>
      <?php for ($d = 1; $d <= $daysInMonth; $d++): ?>
        <option value="<?= $d ?>"<?= (int)$day === $d ? ' selected' : '' ?>><?= sprintf('%02d', $d) ?></option>
      <?php endfor; ?>
    </select>
  </label>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('audio.search_placeholder') ?>" data-filter-debounce>
  <input type="search" name="search" value="<?= e($search) ?>" placeholder="<?= te('audio.filter.search_transcript') ?>" data-filter-debounce>
  <label><?= te('audio.col.admin') ?>
    <input type="number" name="admin" min="0" value="<?= $admin > 0 ? (int)$admin : '' ?>" placeholder="ID" data-filter-change>
  </label>
  <label><?= te('audio.col.badge') ?>
    <select name="badge">
      <option value="0"><?= te('audio.filter.all_badges') ?></option>
      <?php foreach ($badgeOptions as $code => $label): ?>
        <option value="<?= e((string)$code) ?>"<?= (string)$badge === (string)$code ? ' selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('audio.filter.review') ?>
    <select name="review">
      <option value=""<?= $review === '' ? ' selected' : '' ?>><?= te('audio.filter.review_all') ?></option>
      <option value="pending"<?= $review === 'pending' ? ' selected' : '' ?>><?= te('audio.filter.review_pending') ?></option>
      <option value="marked"<?= $review === 'marked' ? ' selected' : '' ?>><?= te('audio.filter.review_marked') ?></option>
      <option value="favorites"<?= $review === 'favorites' ? ' selected' : '' ?>><?= te('audio.filter.review_favorites') ?></option>
    </select>
  </label>
  <label><?= te('audio.filter.disabled') ?>
    <select name="disabled">
      <option value="0"<?= $disabled === 0 ? ' selected' : '' ?>><?= te('audio.filter.disabled_all') ?></option>
      <option value="1"<?= $disabled === 1 ? ' selected' : '' ?>><?= te('audio.filter.disabled_off') ?></option>
      <option value="2"<?= $disabled === 2 ? ' selected' : '' ?>><?= te('audio.filter.disabled_on') ?></option>
    </select>
  </label>
  <label><?= te('audio.col.score') ?>
    <input type="number" name="score" min="0" value="<?= $score >= 0 ? (int)$score : '' ?>" placeholder="=" data-filter-change>
  </label>
  <label><?= te('audio.filter.score_lt') ?>
    <input type="number" name="score_lt" min="0" value="<?= $scoreLt > 0 ? (int)$scoreLt : '' ?>" placeholder="<" data-filter-change>
  </label>
  <label class="logo-remove">
    <input type="checkbox" name="errors_only" value="1"<?= $errorsOnly ? ' checked' : '' ?> data-filter-change>
    <?= te('audio.filter.errors_only') ?>
  </label>
  <label class="logo-remove">
    <input type="checkbox" name="unparsed" value="1"<?= $unparsed ? ' checked' : '' ?> data-filter-change>
    <?= te('audio.filter.unparsed') ?>
  </label>
  <label class="logo-remove">
    <input type="hidden" name="preload" value="0">
    <input type="checkbox" name="preload" value="1"<?= $preload ? ' checked' : '' ?> data-filter-change>
    <?= te('audio.filter.preload') ?>
  </label>
</form>

<?= $pager ?>

<?= BulkActions::beginForm($listId, ListQuery::url($path, $filters, [])) ?>
<?= BulkActions::bar($listId) ?>

<div class="table-wrap audio-records-wrap">
  <table class="data audio-records-table">
    <thead>
      <tr>
        <?= BulkActions::th($listId) ?>
        <th class="audio-col-meta"><?= te('audio.col.meta') ?></th>
        <?= ListQuery::sortTh(t('audio.col.content') . ' / ' . t('audio.col.dt'), 'dt', $sort, $dir, $path, $filters) ?>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $r): ?>
      <?php
        $rid = (int)$r['id'];
        $dt = (int)($r['dt_recorded'] ?? 0);
        $kt = TranscriptHighlighter::parseKeyDicts(isset($r['key_dicts']) ? (string)$r['key_dicts'] : null);
        $fn = basename((string)($r['filename'] ?? ''));
        $rowClass = [];
        if (!empty($r['disabled'])) {
            $rowClass[] = 'is-muted';
        }
        if (!empty($r['error_trans'])) {
            $rowClass[] = 'is-error-row';
        }
        $markFromUrl = ListQuery::url($path, $filters, ['range_from' => (string)$dt, 'page' => 1]);
        $markToUrl = ListQuery::url($path, $filters, ['range_to' => (string)$dt, 'page' => 1]);
        $playUrl = $playUrlById[$rid] ?? '';
        $dlUrl = $downloadUrlById[$rid] ?? '';
        $tr = $transcriptById[$rid] ?? null;
        $rev = $reviewById[$rid] ?? null;
        $needsReview = $rev !== null && (int)$rev['dt_review'] < 1;
        $isFavorite = $rev !== null
            && (int)$rev['dt_select'] > 0
            && (int)$rev['id_reviewer'] === $currentUserId;
      ?>
      <tr class="audio-record-card<?= $rowClass ? ' ' . e(implode(' ', $rowClass)) : '' ?>"
          data-record-id="<?= $rid ?>"
          data-admin-id="<?= (int)($r['id_admin'] ?? 0) ?>"
          <?= $playUrl !== '' ? ' data-play-url="' . e($playUrl) . '"' : '' ?>>
        <?= BulkActions::td($listId, $rid, $review === 'pending') ?>
        <td class="audio-meta-cell">
          <div class="audio-meta">
            <a class="audio-meta-id" href="<?= e(NavContext::withReturn('/audio/' . $rid)) ?>">#<?= $rid ?></a>
            <div class="audio-meta-staff"><?= UsersRepository::badgeHtml((int)$r['id_admin'], false) ?></div>
            <code class="audio-meta-badge" title="<?= te('audio.col.badge') ?>"><?= e((string)$r['id_dictophone']) ?></code>
            <div class="audio-meta-stats muted">
              <?php if ($needsReview): ?>
                <span class="audio-meta-review" title="<?= te('audio.review.pending_badge') ?>"><?= te('audio.review.pending_short') ?></span>
              <?php endif; ?>
              <?php if ($isFavorite): ?>
                <span class="audio-meta-fav" title="<?= te('audio.review.favorite_badge') ?>">★</span>
              <?php endif; ?>
              <span title="<?= te('audio.col.score') ?>">S<?= (int)$r['score'] ?></span>
              <span title="<?= te('audio.col.playtime') ?>"><?= (int)$r['playtime'] ?>s</span>
              <?php if ($tr && (int)$tr['trash'] > 0): ?>
                <span class="audio-meta-trash" title="<?= te('audio.transcript.trash_count', ['n' => (int)$tr['trash']]) ?>">T<?= (int)$tr['trash'] ?></span>
              <?php else: ?>
                <span class="audio-meta-trash" hidden title="">T0</span>
              <?php endif; ?>
            </div>
            <?php if ($kt): ?>
            <div class="audio-key-chips audio-key-chips--compact">
              <?php foreach ($kt as $chip): ?>
                <?php $meta = $dictMeta[$chip['dict_id']] ?? null; ?>
                <span class="key-dict-chip" style="<?= $meta ? 'background:' . e($meta['bg']) . ';color:' . e($meta['color']) : '' ?>">
                  <?= e($meta['name'] ?? ('#' . $chip['dict_id'])) ?>
                  <?= (int)$chip['diversity'] ?>/<?= (int)$chip['instances'] ?>
                </span>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </div>
        </td>
        <td class="audio-main-cell">
          <div class="audio-main-head">
            <?php if ($rangeFrom < 1): ?>
              <a class="audio-range-mark" href="<?= e($markFromUrl) ?>" title="<?= te('audio.range.set_from') ?>">}</a>
            <?php endif; ?>
            <time datetime="<?= $dt > 0 ? e(date('c', $dt)) : '' ?>"><?= $dt > 0 ? e(date('H:i:s', $dt)) : '—' ?></time>
            <?php if ($rangeTo < 1): ?>
              <a class="audio-range-mark" href="<?= e($markToUrl) ?>" title="<?= te('audio.range.set_to') ?>">{</a>
            <?php endif; ?>
            <strong class="audio-fn-base" title="<?= e((string)$r['filename']) ?>"><?= e($fn !== '' ? $fn : '—') ?></strong>
            <?php if ($dlUrl !== ''): ?>
              <a class="audio-download" href="<?= e($dlUrl) ?>" download><?= te('audio.action.download') ?></a>
            <?php endif; ?>
          </div>
          <?php if ($playUrl !== ''): ?>
            <?php
              $pt = max(0, (int)($r['playtime'] ?? 0));
              $ptLabel = sprintf('%d:%02d', intdiv($pt, 60), $pt % 60);
            ?>
            <button type="button" class="audio-player-shell"
                    data-play-src="<?= e($playUrl) ?>"
                    aria-label="<?= te('audio.action.play') ?>">
              <span class="audio-player-shell-play" aria-hidden="true">▶</span>
              <span class="audio-player-shell-track" aria-hidden="true"><span class="audio-player-shell-fill"></span></span>
              <span class="audio-player-shell-time"><?= e($ptLabel) ?></span>
            </button>
          <?php endif; ?>
          <?php if ($preload && $tr): ?>
            <div class="kolonka transcript transcript--list" data-record="<?= $rid ?>">
              <?= $tr['html'] ?>
            </div>
          <?php else: ?>
            <div class="audio-lazy-slot" data-lazy-record="<?= $rid ?>" data-search="<?= e($search) ?>">
              <p class="muted audio-lazy-status"><?= te('audio.lazy.pending') ?></p>
            </div>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?>
      <tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('audio.list.empty') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?= BulkActions::endForm($listId) ?>
<?= $pager ?>

<?php
/** @var array $pageStats */
$pageStats = $pageStats ?? [
    'page_count' => 0,
    'playtime' => 0,
    'errors' => 0,
    'ok' => 0,
    'strings' => 0,
    'strings_nok' => 0,
    'score_avg' => 0,
    'offers' => 0,
    'by_admin' => [],
    'by_volume' => [],
    'by_score' => [],
];
$pageN = (int)$pageStats['page_count'];
$errPct = $pageN > 0 ? (int)round(((int)$pageStats['errors'] * 100) / $pageN) : 0;
$okPct = $pageN > 0 ? (int)round(((int)$pageStats['ok'] * 100) / $pageN) : 0;
$playHms = RecordsRepository::formatHms((int)$pageStats['playtime']);
?>
<section class="audio-page-stats" aria-label="<?= te('audio.stats.aria') ?>">
  <h2 class="audio-page-stats-title"><?= te('audio.stats.title') ?></h2>
  <p class="muted audio-page-stats-hint"><?= te('audio.stats.hint') ?></p>

  <dl class="audio-page-stats-summary">
    <div>
      <dt><?= te('audio.stats.matched') ?></dt>
      <dd><?= (int)$total ?></dd>
    </div>
    <div>
      <dt><?= te('audio.stats.on_page') ?></dt>
      <dd><?= $pageN ?></dd>
    </div>
    <div>
      <dt title="<?= te('audio.stats.duration_hint') ?>"><?= te('audio.stats.duration') ?></dt>
      <dd><?= e($playHms) ?> <span class="muted">(<?= (int)$pageStats['playtime'] ?>s)</span></dd>
    </div>
    <div>
      <dt title="<?= te('audio.stats.quality_hint') ?>"><?= te('audio.stats.quality') ?></dt>
      <dd>
        <?= te('audio.stats.errors', ['n' => (int)$pageStats['errors'], 'pct' => $errPct]) ?>
        ·
        <?= te('audio.stats.ok', ['n' => (int)$pageStats['ok'], 'pct' => $okPct]) ?>
      </dd>
    </div>
    <div>
      <dt title="<?= te('audio.stats.strings_hint') ?>"><?= te('audio.stats.strings') ?></dt>
      <dd>
        <?= te('audio.stats.strings_total', ['n' => (int)$pageStats['strings']]) ?>
        ·
        <?= te('audio.stats.strings_nok', ['n' => (int)$pageStats['strings_nok']]) ?>
      </dd>
    </div>
    <div>
      <dt title="<?= te('audio.stats.score_hint') ?>"><?= te('audio.stats.score_avg') ?></dt>
      <dd><?= e((string)$pageStats['score_avg']) ?></dd>
    </div>
    <?php if ((int)$pageStats['offers'] > 0): ?>
    <div>
      <dt title="<?= te('audio.stats.offers_hint') ?>"><?= te('audio.stats.offers') ?></dt>
      <dd><?= (int)$pageStats['offers'] ?></dd>
    </div>
    <?php endif; ?>
  </dl>

  <?php if (!empty($pageStats['by_admin'])): ?>
  <div class="audio-page-stats-block">
    <h3><?= te('audio.stats.by_staff') ?></h3>
    <p class="muted"><?= te('audio.stats.by_staff_hint') ?></p>
    <ul class="audio-page-stats-list">
      <?php foreach ($pageStats['by_admin'] as $aid => $row): ?>
        <?php
          $mins = (int)round(((int)$row['playtime']) / 60);
          $range = '';
          if ((int)$row['start'] > 0 && (int)$row['end'] > 0) {
              $range = date('d.m.Y H:i', (int)$row['start']) . ' – ' . date('d.m.Y H:i', (int)$row['end']);
          }
        ?>
        <li>
          <?= UsersRepository::badgeHtml((int)$aid, false) ?>
          <span class="audio-page-stats-metric">
            <?= te('audio.stats.staff_row', [
                'count' => (int)$row['count'],
                'mins' => $mins,
                'hms' => RecordsRepository::formatHms((int)$row['playtime']),
            ]) ?>
          </span>
          <?php if ($range !== ''): ?>
            <span class="muted"><?= e($range) ?></span>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

  <?php if (!empty($pageStats['by_score'])): ?>
  <div class="audio-page-stats-block">
    <h3><?= te('audio.stats.by_score') ?></h3>
    <p class="muted"><?= te('audio.stats.by_score_hint') ?></p>
    <ul class="audio-page-stats-list audio-page-stats-list--compact">
      <?php foreach ($pageStats['by_score'] as $sc => $sec): ?>
        <li>
          <strong><?= te('audio.stats.score_bucket', ['score' => (int)$sc]) ?></strong>
          <?= e(RecordsRepository::formatHms((int)$sec)) ?>
          <span class="muted">(<?= (int)$sec ?>s)</span>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

  <?php if (!empty($pageStats['by_volume'])): ?>
  <div class="audio-page-stats-block">
    <h3><?= te('audio.stats.by_volume') ?></h3>
    <p class="muted"><?= te('audio.stats.by_volume_hint') ?></p>
    <ul class="audio-page-stats-list audio-page-stats-list--compact">
      <?php foreach ($pageStats['by_volume'] as $bucket => $sec): ?>
        <li>
          <strong><?= te('audio.stats.volume_bucket', ['n' => (int)$bucket]) ?></strong>
          <?= e(RecordsRepository::formatHms((int)$sec)) ?>
          <span class="muted">(<?= (int)$sec ?>s)</span>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>
</section>

<?php if ($dictsOn && $canEdit): ?>
<script>
window.AudioListI18n = <?= json_encode([
    'key' => t('audio.popover.key'),
    'dict' => t('audio.popover.dict'),
    'addKey' => t('audio.popover.add_key'),
    'addTrash' => t('audio.popover.add_trash'),
    'cancel' => t('core.common.cancel'),
    'textRequired' => t('audio.api.text_required'),
    'error' => t('audio.api.error'),
    'network' => t('audio.api.network'),
    'done' => t('audio.api.done'),
    'reparsing' => t('audio.api.reparsing'),
    'keyExists' => t('audio.api.key_exists'),
    'keyExistsTitle' => t('audio.api.key_exists_title'),
    'keyExistsHint' => t('audio.api.key_exists_hint'),
    'inDict' => t('audio.api.key_exists_in_dict'),
    'openKey' => t('audio.api.key_exists_open'),
    'disabled' => t('audio.api.key_disabled'),
    'confirmOverlaps' => t('audio.api.confirm_overlaps'),
    'createAnyway' => t('audio.api.create_anyway'),
    'reload' => t('audio.api.reload'),
    'lazyLoading' => t('audio.lazy.loading'),
    'lazyError' => t('audio.lazy.error'),
    'trashTitle' => t('audio.transcript.trash_count', ['n' => '{n}']),
], JSON_UNESCAPED_UNICODE) ?>;
</script>
<script type="application/json" id="audio-key-dicts"><?= json_encode($keyDictOptions, JSON_UNESCAPED_UNICODE) ?></script>
<script type="application/json" id="audio-key-dicts-by-admin"><?= json_encode($keyDictsByAdmin, JSON_UNESCAPED_UNICODE) ?></script>
<?php else: ?>
<script>
window.AudioListI18n = <?= json_encode([
    'lazyLoading' => t('audio.lazy.loading'),
    'lazyError' => t('audio.lazy.error'),
    'trashTitle' => t('audio.transcript.trash_count', ['n' => '{n}']),
], JSON_UNESCAPED_UNICODE) ?>;
</script>
<?php endif; ?>
