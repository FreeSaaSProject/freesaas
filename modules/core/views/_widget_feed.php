<?php
declare(strict_types=1);
/** @var int $type */
/** @var string $label */
/** @var string $listHref */
/** @var string|null $add */
/** @var int|null $addType */
/** @var list<array> $items */
/** @var list<array> $folders */
/** @var array $statuses */
/** @var array<int,int> $commentCounts */
/** @var array<int,int> $viewerCounts */
/** @var array<int,array{done:int,total:int,pct:int}> $checklistProgress */
/** @var array<int,string> $feedChips */
$type = (int)($type ?? 0);
$label = (string)($label ?? '');
$listHref = (string)($listHref ?? ('/news?type=' . $type));
$add = $add ?? null;
$addType = isset($addType) ? (int)$addType : null;
$items = $items ?? [];
$folders = is_array($folders ?? null) ? $folders : [];
$statuses = $statuses ?? [];
$commentCounts = $commentCounts ?? [];
$viewerCounts = $viewerCounts ?? [];
$checklistProgress = $checklistProgress ?? [];
$feedChips = $feedChips ?? [];
?>
<div class="widget-feed-head">
  <h2 class="widget-feed-title">
    <a href="<?= e($listHref) ?>"><?= e($label) ?></a>
  </h2>
  <?php if ($add !== null && $add !== '' && $addType): ?>
    <div class="widget-feed-actions">
      <a href="/news/new?type=<?= $addType ?>"><?= e((string)$add) ?></a>
    </div>
  <?php endif; ?>
</div>
<?php if ($folders): ?>
  <div class="folder-list compact">
    <?php foreach ($folders as $f): ?>
      <?php
        $fid = (int)$f['ID'];
        $isArchive = !empty($f['IGNORE_FOLDER']);
      ?>
      <div class="folder-row<?= $isArchive ? ' is-archive' : '' ?>">
        <a class="folder-open" href="/news?type=<?= (int)$f['TYPE'] ?>&amp;pid=<?= $fid ?>">
          <span class="folder-icon" aria-hidden="true">▸</span>
          <span class="folder-name"><?= e((string)$f['NAME']) ?></span>
          <?php if ($isArchive): ?>
            <span class="folder-archive-badge"><?= te('core.news.folder_archive_badge') ?></span>
          <?php endif; ?>
        </a>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<div class="feed-list compact">
  <?php foreach ($items as $n): ?>
    <?php
      $st = ((int)$n['TYPE'] === 2) ? ($statuses[(int)$n['STATUS']] ?? null) : null;
      $nid = (int)$n['ID'];
      $cc = (int)($commentCounts[$nid] ?? 0);
      $vc = (int)($viewerCounts[$nid] ?? 0);
      $cp = $checklistProgress[$nid] ?? null;
    ?>
    <article class="feed-item">
      <div class="feed-meta">
        <?= UsersRepository::badgeHtml((int)$n['ID_USER'], false) ?>
        <?php if ($st): ?><?= status_pill($st) ?><?php endif; ?>
        <?php if ((int)$n['TYPE'] === 2 && (int)$n['DT_DEADLINE'] > 0): ?>
          <?= deadline_chip((int)$n['DT_DEADLINE'], true) ?>
        <?php endif; ?>
        <span class="muted"><?= e(I18n::formatDate((int)$n['DT'], 'daymonth')) ?></span>
        <?php if ($cc > 0): ?><span class="muted" title="<?= te('core.feed.comments') ?>">💬 <?= $cc ?></span><?php endif; ?>
        <?php if ($vc > 0): ?><span class="muted" title="<?= te('core.feed.viewers') ?>">👁 <?= $vc ?></span><?php endif; ?>
        <?php if (!empty($feedChips[$nid])): ?><?= $feedChips[$nid] ?><?php endif; ?>
      </div>
      <a class="feed-title" href="/news/<?= $nid ?>"><?= e((string)$n['NAME']) ?></a>
      <?php if (is_array($cp) && (int)($cp['total'] ?? 0) > 0): ?>
        <div class="feed-checklist">
          <div class="checklist-progress" role="progressbar"
               aria-valuenow="<?= (int)$cp['pct'] ?>" aria-valuemin="0" aria-valuemax="100"
               aria-label="<?= te('core.checklist.progress', [
                   'done' => (int)$cp['done'],
                   'total' => (int)$cp['total'],
                   'pct' => (int)$cp['pct'],
               ]) ?>">
            <div class="checklist-progress-bar" style="width:<?= (int)$cp['pct'] ?>%"></div>
          </div>
          <span class="muted checklist-progress-label"><?= te('core.checklist.progress', [
              'done' => (int)$cp['done'],
              'total' => (int)$cp['total'],
              'pct' => (int)$cp['pct'],
          ]) ?></span>
        </div>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>
  <?php if (!$items && !$folders): ?>
    <div class="muted"><?= te('core.common.empty') ?></div>
  <?php endif; ?>
</div>
