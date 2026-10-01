<?php
/** @var array $row */
/** @var ?array $status */
/** @var array $statuses */
/** @var bool $canEdit */
/** @var bool $canManageTicket */
/** @var string $message */
/** @var string $error */
/** @var int $newsLikeCount */
/** @var bool $newsLiked */
/** @var array $respIds */
/** @var bool $isFavorite */
/** @var array $staff */
/** @var list<array{label:string, href?:?string, current?:bool}> $breadcrumbs */
/** @var int $uid */
/** @var bool $isFolder */
/** @var array $viewers */
/** @var bool $contractLocked */
/** @var string $metaHtml */
/** @var string $metaActionsHtml */
/** @var array $commentsMeta */

$nid = (int)$row['ID'];
$viewerCount = count($viewers);
$isTicket = (int)$row['TYPE'] === 2;
$hasDeadline = (int)$row['DT_DEADLINE'] > 0;
$metaHtml = $metaHtml ?? '';
$metaActionsHtml = $metaActionsHtml ?? '';
$contractLocked = !empty($contractLocked);
?>
<section class="page-head" id="top">
  <div class="page-head-row">
    <div>
      <h1><?= e((string)$row['NAME']) ?></h1>
      <p class="lede feed-meta-inline">
        <span class="muted"><?= e(I18n::formatDate((int)$row['DT'], 'datetime')) ?></span>
        <?php if (!$isTicket): ?>
          <?= UsersRepository::badgeHtml((int)$row['ID_USER']) ?>
        <?php endif; ?>
        <?= $metaHtml ?>
        <?php if ($viewerCount > 0): ?><span class="muted" title="<?= te('core.feed.viewers') ?>">👁 <?= $viewerCount ?></span><?php endif; ?>
      </p>
    </div>
    <div class="page-actions">
      <?= $metaActionsHtml ?>
      <form method="post" class="inline-form">
        <input type="hidden" name="action" value="favorite">
        <button class="btn-secondary" type="submit"><?= $isFavorite ? '★ ' . te('core.news_item.in_favorites') : '☆ ' . te('core.news_item.add_favorite') ?></button>
      </form>
      <?php if ((int)$row['ID_USER'] !== $uid): ?>
        <form method="post" class="inline-form">
          <input type="hidden" name="action" value="like">
          <input type="hidden" name="like_type" value="base_news">
          <input type="hidden" name="like_id" value="<?= $nid ?>">
          <button class="btn-secondary" type="submit"><?= $newsLiked ? '♥' : '♡' ?> <?= (int)$newsLikeCount ?></button>
        </form>
      <?php elseif ($newsLikeCount > 0): ?>
        <span class="muted">♥ <?= (int)$newsLikeCount ?></span>
      <?php endif; ?>
      <?php if ($canEdit): ?>
        <a class="btn-secondary" href="/news/<?= $nid ?>/edit"><?= te('core.common.edit') ?></a>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<?php if ($viewerCount > 0): ?>
  <p class="viewers-inline muted"><?= te('core.news_item.viewers', ['n' => $viewerCount]) ?>
    <?php foreach ($viewers as $vid): ?>
      <?= UsersRepository::badgeHtml((int)$vid) ?>
    <?php endforeach; ?>
  </p>
<?php endif; ?>

<?php if ($isTicket): ?>
<section class="ticket-panel widget">
  <h2><?= te('core.news_item.service_info') ?></h2>
  <dl class="kv">
    <dt><?= te('core.news_item.status') ?></dt>
    <dd><?= status_pill($status, '#' . (int)$row['STATUS']) ?></dd>
    <?php if ($hasDeadline): ?>
      <dt><?= te('core.news_item.deadline') ?></dt>
      <dd><?= deadline_chip((int)$row['DT_DEADLINE']) ?></dd>
    <?php endif; ?>
    <dt><?= te('core.news_item.curator') ?></dt>
    <dd><?= UsersRepository::badgeHtml((int)$row['ID_CURATOR']) ?></dd>
    <dt><?= te('core.news_item.responsible') ?></dt>
    <dd class="badge-row">
      <?php if ($respIds): ?>
        <?php foreach ($respIds as $rid): ?>
          <?= UsersRepository::badgeHtml($rid) ?>
        <?php endforeach; ?>
      <?php else: ?>
        <span class="muted">—</span>
      <?php endif; ?>
    </dd>
    <dt><?= te('core.news_item.author') ?></dt>
    <dd><?= UsersRepository::badgeHtml((int)$row['ID_USER']) ?></dd>
    <dt><?= te('core.news_item.access') ?></dt>
    <dd class="badge-row"><?= NewsRepository::accessHtml($row) ?></dd>
  </dl>
</section>
<?php else: ?>
  <p class="access-line muted"><?= te('core.news_item.access') ?>: <?= NewsRepository::accessHtml($row) ?></p>
<?php endif; ?>

<article class="prose widget">
  <?php if (!empty($row['SHORT'])): ?>
    <p class="lede"><?= e((string)$row['SHORT']) ?></p>
  <?php endif; ?>
  <div class="body-html">
    <?= safe_html((string)$row['BIG']) ?>
  </div>
</article>

<?php
  $section = EventBus::emit('core.news_item.section', [
      'html' => '',
      'row' => $row,
      'uid' => $uid,
      'is_folder' => $isFolder,
      'can_manage_ticket' => $canManageTicket,
      'statuses' => $statuses,
      'status' => $status,
      'resp_ids' => $respIds,
      'staff' => $staff,
      'contract_locked' => $contractLocked,
      '_comments_meta' => $commentsMeta ?? [],
  ]);
  if (is_array($section) && !empty($section['html'])) {
      echo $section['html'];
  }
?>
