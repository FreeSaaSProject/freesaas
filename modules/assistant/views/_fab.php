<?php
declare(strict_types=1);
/** @var array<string,mixed> $page_context */
/** @var list<array{role:string,content:string}> $history */
/** @var array<string,string> $menu_labels */
/** @var array<string,mixed> $i18n */
/** @var string $admin_base */
$page_context = is_array($page_context ?? null) ? $page_context : [];
$history = is_array($history ?? null) ? $history : [];
$menu_labels = is_array($menu_labels ?? null) ? $menu_labels : [];
$i18n = is_array($i18n ?? null) ? $i18n : [];
$admin_base = isset($admin_base) ? (string)$admin_base : '';
$name = AssistantConfig::displayName();
$letter = AssistantConfig::initialLetter();
$historyJson = json_encode($history, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($historyJson === false) {
    $historyJson = '[]';
}
$menuLabelsJson = json_encode($menu_labels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($menuLabelsJson === false) {
    $menuLabelsJson = '{}';
}
$ctxJson = json_encode($page_context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($ctxJson === false) {
    $ctxJson = '{}';
}
$i18nJson = json_encode($i18n, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($i18nJson === false) {
    $i18nJson = '{}';
}
$path = (string)($page_context['path'] ?? '/');
?>
<div class="assistant-fab" id="assistant-fab"
     data-hide-url="/assistant/fab/hide"
     data-prefs-url="/assistant/prefs"
     data-path="<?= e($path) ?>">
  <button type="button"
          class="assistant-fab__btn"
          id="assistant-fab-btn"
          title="<?= e($name) ?>"
          aria-label="<?= e($name) ?>"
          aria-expanded="false"
          aria-controls="assistant-fab-panel">
    <span class="assistant-fab__letter" aria-hidden="true"><?= e($letter) ?></span>
  </button>

  <div class="assistant-fab__panel" id="assistant-fab-panel" hidden role="dialog" aria-modal="true"
       aria-label="<?= e($name) ?>">
    <header class="assistant-fab__head">
      <div class="assistant-fab__titles">
        <strong class="assistant-fab__name"><?= e($name) ?></strong>
        <span class="assistant-fab__sub muted"><?= te('assistant.fab.page_mode') ?></span>
      </div>
      <div class="assistant-fab__actions">
        <button type="button" class="assistant-fab__icon-btn" id="assistant-fab-clear"
                title="<?= te('assistant.fab.clear_title') ?>"
                aria-label="<?= te('assistant.fab.clear_title') ?>">
          <svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path fill="currentColor" d="M6 19a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/>
          </svg>
        </button>
        <button type="button" class="btn-secondary assistant-fab__hide" id="assistant-fab-hide"
                title="<?= te('assistant.fab.hide_page') ?>"><?= te('assistant.fab.hide_page') ?></button>
        <a class="btn-secondary" href="/assistant/prefs" title="<?= te('assistant.prefs.title') ?>"><?= te('assistant.fab.prefs') ?></a>
        <button type="button" class="assistant-fab__close" id="assistant-fab-close"
                aria-label="<?= te('assistant.fab.close') ?>">×</button>
      </div>
    </header>

    <div class="assistant-chat assistant-chat--fab" id="assistant-fab-chat"
         data-mode="page"
         data-chat-url="/assistant/chat"
         data-confirm-url="/assistant/confirm"
         data-clear-url="/assistant/clear"
         data-feedback-url="/assistant/feedback"
         data-admin-base="<?= e($admin_base) ?>"
         data-history="<?= e($historyJson) ?>"
         data-menu-labels="<?= e($menuLabelsJson) ?>"
         data-page-context="<?= e($ctxJson) ?>"
         data-i18n="<?= e($i18nJson) ?>">
      <div class="assistant-chat__log" id="assistant-fab-chat-log" aria-live="polite"></div>
      <form class="assistant-chat__form" id="assistant-fab-chat-form">
        <label class="sr-only" for="assistant-fab-chat-input"><?= te('assistant.chat.placeholder') ?></label>
        <textarea id="assistant-fab-chat-input" name="message" rows="1"
                  placeholder="<?= te('assistant.chat.placeholder') ?>" required></textarea>
        <button type="submit" class="btn-primary" id="assistant-fab-chat-send"><?= te('assistant.chat.send') ?></button>
      </form>
    </div>
  </div>
</div>
