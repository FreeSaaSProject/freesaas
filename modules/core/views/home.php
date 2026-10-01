<?php
/** @var string $brand */
/** @var string $instance */
/** @var string $userName */
/** @var array $widgets */
/** @var array $commands */
/** @var array $columns */
/** @var array $statuses */
/** @var array $commentCounts */
/** @var array $viewerCounts */
$commentCounts = $commentCounts ?? [];
$viewerCounts = $viewerCounts ?? [];
?>
<section class="hero hero-compact">
  <div class="command">
    <label class="sr-only" for="cmd"><?= te('core.home.command') ?></label>
    <input id="cmd" type="search" placeholder="<?= te('core.home.command_placeholder') ?>" data-commands='<?= e(json_encode($commands, JSON_UNESCAPED_UNICODE)) ?>' autocomplete="off">
    <ul id="cmd-list" class="command-list" hidden></ul>
  </div>
</section>

<section class="widgets" aria-label="<?= te('core.home.widgets_aria') ?>">
  <?php foreach ($widgets as $w): ?>
    <article class="widget tone-<?= e((string)($w['tone'] ?? 'neutral')) ?>">
      <h2><?= e((string)($w['title'] ?? t('core.home.widget_default'))) ?></h2>
      <p><?= e((string)($w['body'] ?? '')) ?></p>
      <?php if (!empty($w['href'])): ?>
        <a class="widget-link" href="<?= e((string)$w['href']) ?>"><?= te('core.home.open') ?></a>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>
</section>

<?php if ($columns): ?>
<section class="home-news-board" aria-label="<?= te('core.home.feeds_aria') ?>">
  <div class="home-news-tabs" role="tablist" aria-label="<?= te('core.home.feed_types_aria') ?>">
    <?php foreach ($columns as $i => $col): ?>
      <button type="button" class="home-news-tab<?= $i === 0 ? ' is-active' : '' ?>" data-slide="<?= (int)$i ?>">
        <?= e($col['label']) ?>
      </button>
    <?php endforeach; ?>
  </div>
  <div class="home-news-cols" id="home-news-cols" style="--cols: <?= count($columns) ?>">
    <?php foreach ($columns as $i => $col): ?>
      <article class="home-news-card" id="home-news-slide-<?= (int)$i ?>" data-slide="<?= (int)$i ?>">
        <div class="home-news-card-head">
          <h2><?= e($col['label']) ?></h2>
          <div class="home-news-card-actions">
            <a href="/news?type=<?= (int)$col['type'] ?>"><?= te('core.home.read') ?></a>
            <?php if (!empty($col['add']) && !empty($col['addType'])): ?>
              <a href="/news/new?type=<?= (int)$col['addType'] ?>"><?= e((string)$col['add']) ?></a>
            <?php endif; ?>
          </div>
        </div>
        <div class="feed-list compact">
          <?php foreach ($col['items'] as $n): ?>
            <?php
              $st = ((int)$n['TYPE'] === 2) ? ($statuses[(int)$n['STATUS']] ?? null) : null;
              $nid = (int)$n['ID'];
              $cc = (int)($commentCounts[$nid] ?? 0);
              $vc = (int)($viewerCounts[$nid] ?? 0);
            ?>
            <article class="feed-item">
              <div class="feed-meta">
                <?= UsersRepository::badgeHtml((int)$n['ID_USER'], false) ?>
                <?php if ($st): ?><?= status_pill($st) ?><?php endif; ?>
                <?php if ((int)$n['TYPE'] === 2 && (int)$n['DT_DEADLINE'] > 0): ?>
                  <?= deadline_chip((int)$n['DT_DEADLINE'], true) ?>
                <?php endif; ?>
                <span class="muted"><?= e(I18n::formatDate((int)$n['DT'], 'date')) ?></span>
                <?php if ($cc > 0): ?><span class="muted" title="<?= te('core.feed.comments') ?>">💬 <?= $cc ?></span><?php endif; ?>
                <?php if ($vc > 0): ?><span class="muted" title="<?= te('core.feed.viewers') ?>">👁 <?= $vc ?></span><?php endif; ?>
              </div>
              <a class="feed-title" href="/news/<?= $nid ?>"><?= e((string)$n['NAME']) ?></a>
            </article>
          <?php endforeach; ?>
          <?php if (!$col['items']): ?>
            <div class="muted"><?= te('core.common.empty') ?></div>
          <?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>
