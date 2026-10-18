<?php
declare(strict_types=1);
/** @var array<string,mixed> $diag */
$bot = is_array($diag['bot'] ?? null) ? $diag['bot'] : [];
$chats = is_array($diag['chats'] ?? null) ? $diag['chats'] : [];
?>
<section class="page-head">
  <h1><?= te('assistant_groups.status.title') ?></h1>
  <p class="lede"><?= te('assistant_groups.status.lede') ?></p>
</section>

<?php if (empty($diag['has_token'])): ?>
  <div class="flash flash-warn"><?= te('assistant_groups.status.no_token') ?></div>
<?php else: ?>
  <p><?= te('assistant_groups.status.bot', [
      'name' => (string)($bot['name'] ?? '—'),
      'username' => (string)($bot['username'] ?? '—'),
      'id' => (string)(int)($bot['user_id'] ?? 0),
  ]) ?></p>
  <p class="muted"><?= te('assistant_groups.status.token_source', ['source' => (string)($diag['token_source'] ?? '')]) ?></p>
  <p class="muted"><?= te('assistant_groups.status.subs', ['n' => (string)(int)($diag['subscriptions_count'] ?? 0)]) ?></p>
  <?php if (!empty($diag['error'])): ?>
    <div class="flash flash-warn"><?= e((string)$diag['error']) ?></div>
  <?php endif; ?>
<?php endif; ?>

<ul>
<?php foreach ($chats as $c): ?>
  <?php if (!is_array($c)) { continue; } ?>
  <li>
    <?php if (!empty($c['ok'])): ?>
      <?= te('assistant_groups.status.chat_ok', [
          'id' => (string)($c['chat_id'] ?? ''),
          'admin' => !empty($c['is_admin']) ? 'yes' : 'no',
          'perms' => implode(', ', is_array($c['permissions'] ?? null) ? $c['permissions'] : []),
      ]) ?>
    <?php else: ?>
      <?= te('assistant_groups.status.chat_fail', [
          'id' => (string)($c['chat_id'] ?? ''),
          'error' => (string)($c['error'] ?? ''),
      ]) ?>
    <?php endif; ?>
  </li>
<?php endforeach; ?>
</ul>

<p class="muted">
  group_enabled=<?= !empty($diag['group_enabled']) ? '1' : '0' ?>
  · require_mention=<?= !empty($diag['require_mention']) ? '1' : '0' ?>
  · ops=<?= e(implode(',', is_array($diag['group_ops'] ?? null) ? $diag['group_ops'] : [])) ?>
</p>

<div class="form-actions">
  <a class="btn" href="/assistant-groups/status"><?= te('assistant_groups.status.refresh') ?></a>
  <a class="btn" href="/assistant-groups/settings"><?= te('assistant_groups.status.back') ?></a>
</div>
