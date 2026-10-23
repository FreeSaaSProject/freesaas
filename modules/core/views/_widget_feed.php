<?php
declare(strict_types=1);
/** @var int $type */
/** @var string $label */
/** @var string|null $add */
/** @var int|null $addType */
/** @var list<array> $items */
/** @var array $statuses */
/** @var array<int,int> $commentCounts */
/** @var array<int,int> $viewerCounts */
/** @var array<int,array{done:int,total:int,pct:int}> $checklistProgress */
/** @var array<int,string> $feedChips */
$type = (int)($type ?? 0);
$label = (string)($label ?? '');
$add = $add ?? null;
$addType = isset($addType) ? (int)$addType : null;
$items = $items ?? [];
$statuses = $statuses ?? [];
$commentCounts = $commentCounts ?? [];
$viewerCounts = $viewerCounts ?? [];
$checklistProgress = $checklistProgress ?? [];
$feedChips = $feedChips ?? [];
?>
<div class="widget-feed-head">
  <div class="widget-feed-actions">
    <a href="/news?type=<?= $type ?>"><?= te('core.home.read') ?></a>
    <?php if ($add !== null && $add !== '' && $addType): ?>
      <a href="/news/new?type=<?= $addType ?>"><?= e((string)$add) ?></a>
    <?php endif; ?>
  </div>
</div>
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
  <?php if (!$items): ?>
    <div class="muted"><?= te('core.common.empty') ?></div>
  <?php endif; ?>
</div>
