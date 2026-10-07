<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $clients */
/** @var array<string,string> $endpoints */
/** @var bool $assistant_enabled */
/** @var string $message */
/** @var string $error */
$clients = is_array($clients ?? null) ? $clients : [];
$endpoints = is_array($endpoints ?? null) ? $endpoints : [];
?>
<section class="page-head">
  <p class="lede"><?= te('llm.oauth.lede') ?></p>
  <p><a class="btn" href="<?= e(NavContext::withReturn('/llm/oauth/new')) ?>"><?= te('llm.oauth.add') ?></a></p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<?php if (empty($assistant_enabled)): ?>
  <div class="flash flash-warn"><?= te('llm.oauth.need_assistant') ?></div>
<?php endif; ?>

<details class="muted" open>
  <summary><?= te('llm.oauth.endpoints') ?></summary>
  <table class="data-table">
    <tbody>
    <?php foreach ($endpoints as $k => $url): ?>
      <tr>
        <th><?= e((string)$k) ?></th>
        <td><code><?= e((string)$url) ?></code></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p><?= te('llm.oauth.hint_gpt') ?></p>
</details>

<?php if ($clients === []): ?>
  <p class="muted"><?= te('llm.oauth.empty') ?></p>
<?php else: ?>
<table class="data-table">
  <thead>
    <tr>
      <th><?= te('llm.oauth.col.name') ?></th>
      <th><?= te('llm.oauth.col.client_id') ?></th>
      <th><?= te('llm.oauth.col.scopes') ?></th>
      <th><?= te('llm.oauth.col.enabled') ?></th>
      <th></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($clients as $c): ?>
    <tr>
      <td><a href="<?= e(NavContext::withReturn('/llm/oauth/' . (int)$c['id'])) ?>"><?= e((string)$c['name']) ?></a></td>
      <td><code><?= e((string)$c['client_id']) ?></code></td>
      <td><code><?= e((string)$c['scopes']) ?></code></td>
      <td><?= !empty($c['enabled']) ? te('llm.tokens.yes') : te('llm.tokens.no') ?></td>
      <td>
        <form method="post" style="display:inline" onsubmit="return confirm(<?= json_encode(t('llm.oauth.delete_confirm'), JSON_HEX_TAG | JSON_HEX_APOS) ?>);">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
          <button type="submit" class="btn"><?= te('llm.oauth.delete') ?></button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
