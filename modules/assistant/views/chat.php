<?php
declare(strict_types=1);
/** @var list<array{id:string,side_effect:string,description:string}> $tools */
/** @var array<string,string> $i18n */
/** @var list<array{role:string,content:string}> $history */
/** @var string $admin_base */
/** @var string $seed_q */
/** @var array<string,string> $menu_labels */
$tools = is_array($tools ?? null) ? $tools : [];
$i18n = is_array($i18n ?? null) ? $i18n : [];
$history = is_array($history ?? null) ? $history : [];
$admin_base = isset($admin_base) ? (string)$admin_base : '';
$seed_q = isset($seed_q) ? (string)$seed_q : '';
$menu_labels = is_array($menu_labels ?? null) ? $menu_labels : [];
$historyJson = json_encode($history, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($historyJson === false) {
    $historyJson = '[]';
}
$menuLabelsJson = json_encode($menu_labels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($menuLabelsJson === false) {
    $menuLabelsJson = '{}';
}
?>
<div class="assistant-shell">
  <header class="assistant-shell__head">
    <div class="assistant-shell__titles">
      <h1 class="assistant-shell__title"><?= e(AssistantConfig::t('assistant.chat.title')) ?></h1>
      <p class="assistant-shell__lede"><?= e(AssistantConfig::t('assistant.chat.lede')) ?></p>
    </div>
    <button type="button" class="btn-secondary assistant-shell__clear" id="assistant-chat-clear"><?= te('assistant.chat.clear') ?></button>
  </header>

  <div class="assistant-chat assistant-chat--full" id="assistant-chat"
       data-chat-url="/assistant/chat"
       data-confirm-url="/assistant/confirm"
       data-clear-url="/assistant/clear"
       data-feedback-url="/assistant/feedback"
       data-admin-base="<?= e($admin_base) ?>"
       data-seed-q="<?= e($seed_q) ?>"
       data-history="<?= e($historyJson) ?>"
       data-menu-labels="<?= e($menuLabelsJson) ?>"
       data-i18n="<?= e(json_encode($i18n, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}') ?>">
    <div class="assistant-chat__log" id="assistant-chat-log" aria-live="polite"></div>
    <form class="assistant-chat__form" id="assistant-chat-form">
      <label class="sr-only" for="assistant-chat-input"><?= te('assistant.chat.placeholder') ?></label>
      <textarea id="assistant-chat-input" name="message" rows="1"
                placeholder="<?= te('assistant.chat.placeholder') ?>" required></textarea>
      <button type="submit" class="btn-primary" id="assistant-chat-send"><?= te('assistant.chat.send') ?></button>
    </form>
  </div>

  <div class="assistant-fb-modal" id="assistant-fb-modal" hidden>
    <div class="assistant-fb-modal__backdrop" data-fb-close></div>
    <div class="assistant-fb-modal__panel" role="dialog" aria-modal="true" aria-labelledby="assistant-fb-title">
      <div class="assistant-fb-modal__head">
        <h2 id="assistant-fb-title"><?= te('assistant.feedback.share_title') ?></h2>
        <button type="button" class="assistant-fb-modal__x" data-fb-close aria-label="<?= te('assistant.feedback.close') ?>">×</button>
      </div>
      <div class="assistant-fb-modal__chips" id="assistant-fb-chips"></div>
      <label class="sr-only" for="assistant-fb-comment"><?= te('assistant.feedback.details_ph') ?></label>
      <textarea id="assistant-fb-comment" class="assistant-fb-modal__comment" rows="4"
                placeholder="<?= te('assistant.feedback.details_ph') ?>"></textarea>
      <div class="assistant-fb-modal__actions">
        <button type="button" class="btn-secondary" data-fb-close><?= te('assistant.feedback.cancel') ?></button>
        <button type="button" class="btn-primary" id="assistant-fb-submit"><?= te('assistant.feedback.submit') ?></button>
      </div>
    </div>
  </div>

  <?php if ($tools !== []): ?>
  <details class="assistant-tools muted">
    <summary><?= e((string)count($tools)) ?> tools</summary>
    <ul>
      <?php foreach ($tools as $op): ?>
        <li>
          <code><?= e((string)$op['id']) ?></code>
          <span class="pill"><?= e((string)$op['side_effect']) ?></span>
          — <?= e((string)$op['description']) ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </details>
  <?php else: ?>
    <p class="muted assistant-tools-empty"><?= te('assistant.err.tools') ?></p>
  <?php endif; ?>
</div>
