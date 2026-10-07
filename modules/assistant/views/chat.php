<?php
declare(strict_types=1);
/** @var list<array{id:string,side_effect:string,description:string}> $tools */
/** @var array<string,string> $i18n */
$tools = is_array($tools ?? null) ? $tools : [];
$i18n = is_array($i18n ?? null) ? $i18n : [];
?>
<section class="page-head">
  <p class="lede"><?= te('assistant.chat.lede') ?></p>
</section>

<div class="assistant-chat" id="assistant-chat"
     data-chat-url="/assistant/chat"
     data-confirm-url="/assistant/confirm"
     data-i18n="<?= e(json_encode($i18n, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}') ?>">
  <div class="assistant-chat__log" id="assistant-chat-log" aria-live="polite"></div>
  <form class="assistant-chat__form" id="assistant-chat-form">
    <label class="sr-only" for="assistant-chat-input"><?= te('assistant.chat.placeholder') ?></label>
    <textarea id="assistant-chat-input" name="message" rows="2"
              placeholder="<?= te('assistant.chat.placeholder') ?>" required></textarea>
    <button type="submit" class="btn" id="assistant-chat-send"><?= te('assistant.chat.send') ?></button>
  </form>
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
  <p class="muted"><?= te('assistant.err.tools') ?></p>
<?php endif; ?>
