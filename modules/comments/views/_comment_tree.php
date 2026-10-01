<?php
declare(strict_types=1);

/**
 * Render comment forest.
 * @param list<array> $tree
 * @param array<int,int> $commentLikes
 * @param array<int,bool> $commentLiked
 */
function render_comment_tree(
    array $tree,
    array $commentLikes,
    array $commentLiked,
    int $uid,
    int $newsId,
    int $depth = 0
): void {
    foreach ($tree as $node) {
        render_comment_node($node, $commentLikes, $commentLiked, $uid, $newsId, $depth);
    }
}

function render_comment_node(
    array $node,
    array $commentLikes,
    array $commentLiked,
    int $uid,
    int $newsId,
    int $depth = 0
): void {
    $cid = (int)$node['ID'];
    $hidden = (int)$node['DISABLED'] === 1;
    $color = (int)$node['COLOR'];
    $canMod = CommentsRepository::canModerate($node, $uid);
    $likeCount = (int)($commentLikes[$cid] ?? 0);
    $liked = !empty($commentLiked[$cid]);
    $isOwn = (int)$node['ID_USER'] === $uid;
    $bodyClass = 'comment-body';
    if ($color === 1) {
        $bodyClass .= ' tone-green';
    }
    if ($color === 2) {
        $bodyClass .= ' tone-red';
    }
    $replyTo = UsersRepository::replyAddress((int)$node['ID_USER']);
    $rawText = (string)$node['TEXT'];
    $statusParts = !empty($node['STATUS_UPDATE']) ? status_comment_parts($rawText) : ['summary' => '', 'body' => $rawText];
    $displayBody = $statusParts['body'];
    $statusSummary = $statusParts['summary'];
    ?>
<article class="comment<?= !empty($node['STATUS_UPDATE']) ? ' is-status' : '' ?><?= $hidden ? ' is-hidden' : '' ?>" id="c<?= $cid ?>" style="--depth:<?= (int)$depth ?>">
  <div class="comment-meta">
    <?= UsersRepository::badgeHtml((int)$node['ID_USER']) ?>
    <span class="muted"><?= e(I18n::formatDate((int)$node['DT'], 'datetime')) ?></span>
    <?php if ($statusSummary !== ''): ?>
      <span class="status-summary" title="<?= te('comments.changes_title') ?>"><?= e($statusSummary) ?></span>
    <?php elseif (!empty($node['STATUS_UPDATE'])): ?>
      <span class="status-summary"><?= te('comments.changes') ?></span>
    <?php endif; ?>
    <?php if ($hidden): ?>
      <span class="muted"><?= te('comments.hidden') ?></span>
    <?php endif; ?>
    <?php if ((int)($node['PRICE_OFFER'] ?? 0) > 0): ?>
      <span class="pill tone-type"><?= (int)$node['PRICE_OFFER'] ?> ₽</span>
      <?php if ((int)($node['DT_DEADLINE'] ?? 0) > 0): ?>
        <span class="muted"><?= te('comments.until') ?> <?= e(I18n::formatDate((int)$node['DT_DEADLINE'], 'date')) ?></span>
      <?php endif; ?>
    <?php endif; ?>
  </div>
  <?php if (trim($displayBody) !== ''): ?>
    <div class="<?= e($bodyClass) ?>"><?= comment_html($displayBody) ?></div>
  <?php endif; ?>
  <div class="comment-actions">
    <?php if (!$isOwn): ?>
      <form method="post" class="inline-form">
        <input type="hidden" name="action" value="like">
        <input type="hidden" name="like_type" value="base_comments">
        <input type="hidden" name="like_id" value="<?= $cid ?>">
        <button type="submit" class="btn-ghost" title="<?= te('comments.act.like') ?>"><?= $liked ? '♥' : '♡' ?><?= $likeCount ? ' ' . $likeCount : '' ?></button>
      </form>
    <?php elseif ($likeCount > 0): ?>
      <span class="muted">♥ <?= $likeCount ?></span>
    <?php endif; ?>

    <button type="button" class="btn-ghost js-reply" data-pid="<?= $cid ?>" data-name="<?= e($replyTo) ?>" title="<?= te('comments.act.reply') ?>">↵</button>

    <?php if ($canMod): ?>
      <?php if ($hidden): ?>
        <form method="post" class="inline-form">
          <input type="hidden" name="action" value="show_comment">
          <input type="hidden" name="comment_id" value="<?= $cid ?>">
          <button type="submit" class="btn-ghost" title="<?= te('comments.act.show') ?>"><?= te('comments.act.show_short') ?></button>
        </form>
      <?php else: ?>
        <form method="post" class="inline-form">
          <input type="hidden" name="action" value="hide_comment">
          <input type="hidden" name="comment_id" value="<?= $cid ?>">
          <button type="submit" class="btn-ghost" title="<?= te('comments.act.hide') ?>"><?= te('comments.act.hide_short') ?></button>
        </form>
      <?php endif; ?>
      <form method="post" class="inline-form">
        <input type="hidden" name="action" value="color_comment">
        <input type="hidden" name="comment_id" value="<?= $cid ?>">
        <button type="submit" class="btn-ghost" title="<?= te('comments.act.color') ?>"><?= te('comments.act.color_short') ?></button>
      </form>
      <details class="comment-edit">
        <summary class="btn-ghost"><?= te('comments.act.edit_short') ?></summary>
        <form method="post" class="comment-edit-form">
          <input type="hidden" name="action" value="edit_comment">
          <input type="hidden" name="comment_id" value="<?= $cid ?>">
          <textarea name="text" rows="3"><?= e((string)$node['TEXT']) ?></textarea>
          <button class="btn-secondary" type="submit"><?= te('core.common.save') ?></button>
        </form>
      </details>
      <?php if ($isOwn && empty($node['STATUS_UPDATE'])): ?>
        <form method="post" class="inline-form" onsubmit="return confirm(<?= tj('comments.confirm_delete') ?>);">
          <input type="hidden" name="action" value="delete_comment">
          <input type="hidden" name="comment_id" value="<?= $cid ?>">
          <button type="submit" class="btn-ghost" title="<?= te('comments.act.delete') ?>"><?= te('comments.act.delete_short') ?></button>
        </form>
      <?php endif; ?>
    <?php endif; ?>
    <?php
      $ext = EventBus::emit('comments.render_actions', [
          'html' => '',
          'comment' => $node,
          'news_id' => $newsId,
          'uid' => $uid,
      ]);
      if (is_array($ext) && !empty($ext['html'])) {
          echo $ext['html'];
      }
    ?>
  </div>

  <?php if (!empty($node['_children'])): ?>
    <div class="comment-children">
      <?php render_comment_tree($node['_children'], $commentLikes, $commentLiked, $uid, $newsId, $depth + 1); ?>
    </div>
  <?php endif; ?>
</article>
    <?php
}
