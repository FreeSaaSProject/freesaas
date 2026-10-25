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
    int $depth = 0,
    bool $isTicket = false
): void {
    foreach ($tree as $node) {
        render_comment_node($node, $commentLikes, $commentLiked, $uid, $newsId, $depth, $isTicket);
    }
}

function render_comment_node(
    array $node,
    array $commentLikes,
    array $commentLiked,
    int $uid,
    int $newsId,
    int $depth = 0,
    bool $isTicket = false
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
    $ext = EventBus::emit('comments.render_actions', [
        'html' => '',
        'meta_html' => '',
        'comment' => $node,
        'news_id' => $newsId,
        'uid' => $uid,
    ]);
    $extHtml = is_array($ext) ? (string)($ext['html'] ?? '') : '';
    $extMeta = is_array($ext) ? (string)($ext['meta_html'] ?? '') : '';
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
      <?php if ($extMeta !== ''): ?>
        <?= $extMeta /* trusted HTML from contracts module */ ?>
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
      <button type="button" class="btn-ghost js-comment-edit"
              title="<?= te('comments.act.edit') ?>"
              data-cid="<?= $cid ?>"
              data-text="<?= e((string)$node['TEXT']) ?>"
              data-price="<?= (int)($node['PRICE_OFFER'] ?? 0) ?>"
              data-deadline="<?= ((int)($node['DT_DEADLINE'] ?? 0) > 0) ? e(date('Y-m-d', (int)$node['DT_DEADLINE'])) : '' ?>"
              data-is-ticket="<?= ($isTicket || (int)($node['PRICE_OFFER'] ?? 0) > 0) ? '1' : '0' ?>"
      ><?= te('comments.act.edit_short') ?></button>
      <?php
        $canDelete = Auth::isSuperAdmin()
          || ($isOwn && empty($node['STATUS_UPDATE']));
        if ($canDelete) {
            $delGate = EventBus::emit('comments.can_delete', [
                'comment' => $node,
                'user_id' => $uid,
                'allowed' => true,
                'error' => null,
            ]);
            if (is_array($delGate) && array_key_exists('allowed', $delGate) && empty($delGate['allowed'])) {
                $canDelete = false;
            }
        }
      ?>
      <?php if ($canDelete): ?>
        <form method="post" class="inline-form" onsubmit="return confirm(<?= tj('comments.confirm_delete') ?>);">
          <input type="hidden" name="action" value="delete_comment">
          <input type="hidden" name="comment_id" value="<?= $cid ?>">
          <button type="submit" class="btn-ghost" title="<?= te('comments.act.delete') ?>"><?= te('comments.act.delete_short') ?></button>
        </form>
      <?php endif; ?>
    <?php endif; ?>
    <?php if ($extHtml !== ''): ?>
      <?= $extHtml /* trusted HTML from modules */ ?>
    <?php endif; ?>
  </div>

  <?php if (!empty($node['_children'])): ?>
    <div class="comment-children">
      <?php render_comment_tree($node['_children'], $commentLikes, $commentLiked, $uid, $newsId, $depth + 1, $isTicket); ?>
    </div>
  <?php endif; ?>
</article>
    <?php
}
