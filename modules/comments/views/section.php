<?php
declare(strict_types=1);
/** @var array $row */
/** @var array $tree */
/** @var int $commentCount */
/** @var array $commentLikes */
/** @var array $commentLiked */
/** @var int $uid */
/** @var bool $canManageTicket */
/** @var array $statuses */
/** @var ?array $status */
/** @var array $respIds */
/** @var array $staff */
/** @var bool $contractLocked */
/** @var bool $needsSign */

Assets::need('comments');

require_once __DIR__ . '/_comment_tree.php';

$nid = (int)$row['ID'];
$isTicket = (int)$row['TYPE'] === 2;
$hasDeadline = (int)$row['DT_DEADLINE'] > 0;
$offerDefaultDl = date('Y-m-d', time() + 4 * 86400);
$locked = !empty($row['COMMENTS_LOCKED']);
$needsSign = !empty($needsSign);
?>
<section class="comments" id="comments">
  <div class="section-head">
    <h2><?= te('comments.title', ['n' => (int)$commentCount]) ?></h2>
  </div>
  <?php if ((int)($row['COMMENT_PURGE_LIMIT'] ?? 0) > 0): ?>
    <p class="muted"><?= e(t('comments.purge_hint', ['n' => (string)(int)$row['COMMENT_PURGE_LIMIT']])) ?></p>
  <?php endif; ?>

  <div class="comment-list">
    <?php if ($tree): ?>
      <?php render_comment_tree($tree, $commentLikes, $commentLiked, $uid, $nid, 0, $isTicket); ?>
    <?php else: ?>
      <div class="muted"><?= te('comments.empty') ?></div>
    <?php endif; ?>
  </div>

  <dialog id="comment-edit-dialog" class="comment-edit-dialog">
    <form method="post" class="comment-edit-dialog-form" id="comment-edit-form">
      <div class="comment-edit-dialog-bar">
        <strong id="comment-edit-title" data-tpl="<?= te('comments.edit.title', ['id' => '__ID__']) ?>"><?= te('comments.edit.title', ['id' => '…']) ?></strong>
        <button type="button" class="btn-secondary btn-sm" id="comment-edit-cancel"><?= te('comments.edit.cancel') ?></button>
      </div>
      <input type="hidden" name="action" value="edit_comment">
      <input type="hidden" name="comment_id" id="comment-edit-id" value="0">
      <label><?= te('comments.text') ?>
        <textarea name="text" id="comment-edit-text" rows="10" required></textarea>
      </label>
      <div id="comment-edit-extra" class="comment-edit-extra" hidden></div>
      <div class="page-actions">
        <button class="btn-primary" type="submit"><?= te('comments.edit.save') ?></button>
        <button type="button" class="btn-secondary" id="comment-edit-cancel-2"><?= te('comments.edit.cancel') ?></button>
      </div>
    </form>
  </dialog>
  <?php
    $editExtra = EventBus::emit('comments.form.edit_fields', [
        'html' => '',
        'row' => $row,
        'is_ticket' => $isTicket,
        'is_superadmin' => Auth::isSuperAdmin(),
        'uid' => $uid,
    ]);
    $editExtraHtml = (is_array($editExtra) && !empty($editExtra['html'])) ? (string)$editExtra['html'] : '';
  ?>
  <template id="comment-edit-extra-tpl"><?= $editExtraHtml ?></template>

  <?php if (!$locked): ?>
  <form method="post" class="comment-form widget" style="margin-top:1rem" id="comment-form">
    <input type="hidden" name="action" value="comment">
    <input type="hidden" name="reply_pid" id="reply_pid" value="0">
    <h3><?= te('comments.leave') ?></h3>
    <p class="muted reply-hint" id="reply-hint" hidden></p>

    <?php
      $ticketFields = EventBus::emit('comments.form.ticket_controls', [
          'html' => '',
          'row' => $row,
          'is_ticket' => $isTicket,
          'can_manage' => !empty($canManageTicket),
          'statuses' => $statuses ?? [],
          'status' => $status ?? null,
          'resp_ids' => $respIds ?? [],
          'staff' => $staff ?? [],
          'contract_locked' => !empty($contractLocked),
          'has_deadline' => $hasDeadline,
          'uid' => $uid,
      ]);
      if (is_array($ticketFields) && !empty($ticketFields['html'])) {
          echo $ticketFields['html'];
      }
    ?>

    <label><?= te('comments.text') ?>
      <textarea name="text" id="comment-field" rows="4" placeholder="<?= te('comments.placeholder') ?>"></textarea>
    </label>

    <?php if ($needsSign): ?>
    <label class="check">
      <input type="checkbox" name="new_sign" value="1" checked>
      <?= te('comments.sign_ack') ?>
    </label>
    <p class="muted"><?= te('comments.sign_ack_help') ?></p>
    <?php endif; ?>

    <?php
      $offerFields = EventBus::emit('comments.form.offer_fields', [
          'html' => '',
          'row' => $row,
          'is_ticket' => $isTicket,
          'offer_default_dl' => $offerDefaultDl,
          'uid' => $uid,
      ]);
      if (is_array($offerFields) && !empty($offerFields['html'])) {
          echo $offerFields['html'];
      }
    ?>

    <div class="page-actions">
      <button class="btn-primary" type="submit"><?= te('comments.send') ?></button>
      <button class="btn-secondary" type="button" id="reply-clear"><?= te('comments.clear_reply') ?></button>
      <a class="btn-secondary to-top" href="#top">↑ <?= te('comments.to_top') ?></a>
    </div>
  </form>
  <?php else: ?>
    <div class="flash flash-warn"><?= te('comments.locked') ?></div>
  <?php endif; ?>
</section>
