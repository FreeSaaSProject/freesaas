<?php
declare(strict_types=1);
/** @var array $items */
/** @var array $folders */
/** @var int $total */
/** @var int $type */
/** @var int $status */
/** @var string $q */
/** @var int $pid */
/** @var bool $unread */
/** @var bool $unsigned */
/** @var string $rootLabel */
/** @var list<array{label:string, href?:?string, current?:bool}> $breadcrumbs */
/** @var array $counts */
/** @var array $tabs */
/** @var array $statuses */
/** @var array $likeCounts */
/** @var array $commentCounts */
/** @var array $viewerCounts */
/** @var array<int,string> $feedChips */
/** @var string $sort */
/** @var string $dir */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */
$commentCounts = $commentCounts ?? [];
$viewerCounts = $viewerCounts ?? [];
$feedChips = $feedChips ?? [];
$unread = !empty($unread);
$unsigned = !empty($unsigned);
$sort = $sort ?? '';
$dir = $dir ?? 'desc';
$page = $page ?? 1;
$perPage = $perPage ?? 40;
$showAll = !empty($showAll);
$filters = $filters ?? [];
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, '/news', $filters, $showAll);

$qsBase = static function (array $extra = []) use ($type, $status, $q, $pid, $unread, $unsigned, $sort, $dir): string {
    $p = array_merge([
        'type' => $type,
        'status' => $status >= 0 ? $status : '',
        'q' => $q,
        'pid' => $pid > 0 ? $pid : '',
        'unread' => $unread ? 1 : '',
        'unsigned' => $unsigned ? 1 : '',
    ], $extra);
    if ($sort !== '') {
        $p['sort'] = $sort;
        $p['dir'] = $dir;
    }
    $p = array_filter($p, static function ($v) {
        return $v !== '' && $v !== null;
    });
    return '/news?' . http_build_query($p);
};

$activeTab = null;
$visibleTabs = [];
foreach ($tabs as $t) {
    $tid = (int)$t['id'];
    $cnt = (int)($counts[$tid] ?? 0);
    // Hide empty types; keep current tab visible so URL stays usable
    if ($cnt < 1 && $tid !== $type) {
        continue;
    }
    $visibleTabs[] = $t;
    if ($tid === $type) {
        $activeTab = $t;
    }
}

$sortLabels = [
    'date' => t('core.news.sort.date'),
    'name' => t('core.news.sort.name'),
    'status' => t('core.news.sort.status'),
    'deadline' => t('core.news.sort.deadline'),
    'id' => t('core.news.sort.id'),
];
?>
<section class="page-head">
  <div class="page-head-row">
    <div>
      <p class="lede"><?= e($rootLabel) ?> · <?= (int)$total ?></p>
    </div>
    <div class="page-actions">
      <?php if ($activeTab && !empty($activeTab['add']) && $activeTab['addType']): ?>
        <a class="btn-primary" href="/news/new?type=<?= (int)$activeTab['addType'] ?><?= $pid > 0 ? '&pid=' . (int)$pid : '' ?>">
          <?= e($activeTab['add']) ?>
        </a>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php if ($visibleTabs): ?>
<nav class="type-tabs" aria-label="<?= te('core.home.feed_types_aria') ?>">
  <?php foreach ($visibleTabs as $t): ?>
    <?php
      $tid = (int)$t['id'];
      // Unread / unsigned modes only apply to types 1–3
      $tabUnread = $unread && $tid >= 1 && $tid <= 3 ? 1 : '';
      $tabUnsigned = $unsigned && $tid >= 1 && $tid <= 3 ? 1 : '';
      $href = $qsBase(['type' => $tid, 'pid' => '', 'unread' => $tabUnread, 'unsigned' => $tabUnsigned]);
      $cnt = (int)($counts[$tid] ?? 0);
    ?>
    <a class="type-tab<?= $type === $tid ? ' is-active' : '' ?>" href="<?= e($href) ?>">
      <?= e($t['label']) ?>
      <?php if ($cnt > 0 && $tid !== 0): ?><span class="type-tab-count"><?= $cnt ?></span><?php endif; ?>
    </a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>

<form class="filters" method="get" action="/news" data-auto-filter>
  <input type="hidden" name="type" value="<?= (int)$type ?>">
  <input type="hidden" name="per_page" value="<?= (int)$perPage ?>">
  <?php if ($pid > 0): ?><input type="hidden" name="pid" value="<?= (int)$pid ?>"><?php endif; ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('core.news.search_placeholder') ?>" data-filter-debounce>
  <?php if ($type >= 1 && $type <= 3): ?>
  <select name="unread" data-auto-submit aria-label="<?= te('core.news.filter_aria') ?>">
    <option value=""<?= !$unread ? ' selected' : '' ?>><?= te('core.news.filter_all') ?></option>
    <option value="1"<?= $unread ? ' selected' : '' ?>><?= te('core.news.filter_unread') ?></option>
  </select>
  <select name="unsigned" data-auto-submit aria-label="<?= te('core.news.filter_unsigned_aria') ?>">
    <option value=""<?= !$unsigned ? ' selected' : '' ?>><?= te('core.news.filter_unsigned_off') ?></option>
    <option value="1"<?= $unsigned ? ' selected' : '' ?>><?= te('core.news.filter_unsigned') ?></option>
  </select>
  <?php endif; ?>
  <?php if ($type === 2): ?>
  <select name="status" data-auto-submit>
    <option value=""<?= $status < 0 ? ' selected' : '' ?>><?= te('core.news.any_status') ?></option>
    <?php foreach ($statuses as $sid => $st): ?>
      <option value="<?= (int)$sid ?>"<?= $status === (int)$sid ? ' selected' : '' ?>><?= e($st['NAME']) ?></option>
    <?php endforeach; ?>
  </select>
  <?php endif; ?>
  <?php if ($sort !== ''): ?>
  <select name="sort" data-auto-submit aria-label="<?= te('core.news.sort_aria') ?>">
    <?php foreach ($sortLabels as $key => $label): ?>
      <option value="<?= e($key) ?>"<?= $sort === $key ? ' selected' : '' ?>><?= te('core.news.sort_prefix') ?> <?= e($label) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="dir" data-auto-submit aria-label="<?= te('core.news.dir_aria') ?>">
    <option value="desc"<?= $dir === 'desc' ? ' selected' : '' ?>>↓ <?= te('core.news.dir_desc') ?></option>
    <option value="asc"<?= $dir === 'asc' ? ' selected' : '' ?>>↑ <?= te('core.news.dir_asc') ?></option>
  </select>
  <?php else: ?>
  <select name="sort" data-auto-submit aria-label="<?= te('core.news.sort_aria') ?>">
    <option value="" selected><?= te('core.news.sort_default') ?></option>
    <?php foreach ($sortLabels as $key => $label): ?>
      <option value="<?= e($key) ?>"><?= e($label) ?></option>
    <?php endforeach; ?>
  </select>
  <?php endif; ?>
</form>

<?= $pager ?>

<?php if ($folders): ?>
  <div class="folder-list">
    <?php foreach ($folders as $f): ?>
      <?php
        $fid = (int)$f['ID'];
        $isArchive = !empty($f['IGNORE_FOLDER']);
      ?>
      <div class="folder-row<?= $isArchive ? ' is-archive' : '' ?>">
        <a class="folder-item<?= $isArchive ? ' is-archive' : '' ?>" href="<?= e($qsBase(['pid' => $fid, 'type' => (int)$f['TYPE'], 'unread' => '', 'unsigned' => ''])) ?>">
          <span class="folder-icon" aria-hidden="true">▸</span>
          <span class="folder-name"><?= e((string)$f['NAME']) ?></span>
          <?php if ($isArchive): ?>
            <span class="folder-archive-badge"><?= te('core.news.folder_archive_badge') ?></span>
          <?php endif; ?>
        </a>
        <a class="folder-edit" href="/news/<?= $fid ?>/edit"><?= te('core.common.edit') ?></a>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="feed-list">
  <?php foreach ($items as $n): ?>
    <?php
      $st = ((int)$n['TYPE'] === 2) ? ($statuses[(int)$n['STATUS']] ?? null) : null;
      $nid = (int)$n['ID'];
      $lc = (int)($likeCounts[$nid] ?? 0);
    ?>
    <article class="feed-item">
      <div class="feed-meta">
        <?php if ($type === 4): ?>
          <span class="pill tone-type"><?= e(NewsRepository::typeLabel((int)$n['TYPE'])) ?></span>
        <?php endif; ?>
        <?php if ($st): ?>
          <?= status_pill($st) ?>
        <?php endif; ?>
        <?= UsersRepository::badgeHtml((int)$n['ID_USER'], false) ?>
        <span class="muted"><?= e(I18n::formatDate((int)$n['DT'], 'datetime')) ?></span>
        <?php if ((int)$n['TYPE'] === 2 && (int)$n['DT_DEADLINE'] > 0): ?>
          <?= deadline_chip((int)$n['DT_DEADLINE'], true) ?>
        <?php endif; ?>
        <?php
          $cc = (int)($commentCounts[$nid] ?? 0);
          $vc = (int)($viewerCounts[$nid] ?? 0);
        ?>
        <?php if ($cc > 0): ?><span class="muted" title="<?= te('core.feed.comments') ?>">💬 <?= $cc ?></span><?php endif; ?>
        <?php if ($vc > 0): ?><span class="muted" title="<?= te('core.feed.viewers') ?>">👁 <?= $vc ?></span><?php endif; ?>
        <?php if ($lc > 0): ?>
          <span class="muted">♥ <?= $lc ?></span>
        <?php endif; ?>
        <?php if (!empty($feedChips[$nid])): ?><?= $feedChips[$nid] ?><?php endif; ?>
      </div>
      <a class="feed-title" href="/news/<?= $nid ?>"><?= e((string)$n['NAME']) ?></a>
      <?php if (!empty($n['SHORT'])): ?>
        <div class="feed-short muted"><?= e((string)$n['SHORT']) ?></div>
      <?php endif; ?>
      <?php if ((int)$n['TYPE'] === 2 && !empty($n['USERS_RESP'])): ?>
        <div class="feed-resp">
          <?php foreach (NewsRepository::parseUserList((string)$n['USERS_RESP']) as $rid): ?>
            <?= UsersRepository::badgeHtml($rid, false) ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <div class="feed-access muted"><?= NewsRepository::accessHtml($n) ?></div>
    </article>
  <?php endforeach; ?>
  <?php if (!$items && !$folders): ?>
    <div class="muted"><?= te('core.common.empty') ?></div>
  <?php endif; ?>
</div>

<?= $pager ?>
