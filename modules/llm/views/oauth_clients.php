<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $clients */
/** @var list<array<string,mixed>> $connections */
/** @var array<string,string> $endpoints */
/** @var string $mcpUrl */
/** @var string $oauthBase */
/** @var bool $hostMismatch */
/** @var string $requestHost */
/** @var string $oauthHost */
/** @var bool $assistant_enabled */
/** @var string $message */
/** @var string $error */
$clients = is_array($clients ?? null) ? $clients : [];
$connections = is_array($connections ?? null) ? $connections : [];
$endpoints = is_array($endpoints ?? null) ? $endpoints : [];
$mcpUrl = (string)($mcpUrl ?? '');
$oauthBase = (string)($oauthBase ?? '');
$hostMismatch = !empty($hostMismatch);
$requestHost = (string)($requestHost ?? '');
$oauthHost = (string)($oauthHost ?? '');
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

<?php if ($hostMismatch): ?>
  <div class="flash flash-warn">
    <?= te('llm.oauth.host_mismatch', [
        'ui' => $requestHost,
        'oauth' => $oauthHost !== '' ? $oauthHost : $oauthBase,
    ]) ?>
  </div>
<?php endif; ?>

<section class="llm-oauth-howto">
  <h2 class="subhead"><?= te('llm.oauth.chatgpt_title') ?></h2>
  <ol class="lede">
    <li><?= te('llm.oauth.chatgpt_1', ['url' => $mcpUrl]) ?></li>
    <li><?= te('llm.oauth.chatgpt_2') ?></li>
    <li><?= te('llm.oauth.chatgpt_3') ?></li>
  </ol>
  <p class="llm-oauth-mcp-url">
    <span class="muted"><?= te('llm.oauth.mcp_label') ?></span>
    <code id="llm-mcp-url"><?= e($mcpUrl) ?></code>
  </p>
  <p class="muted">
    <a href="<?= e(NavContext::withReturn('/settings')) ?>"><?= te('llm.oauth.base_url_link') ?></a>
    <?php if ($oauthBase !== ''): ?>
      — <code><?= e($oauthBase) ?></code>
    <?php endif; ?>
  </p>
</section>

<details class="muted">
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
</details>

<h2 class="subhead"><?= te('llm.oauth.clients_title') ?></h2>
<p class="muted"><?= te('llm.oauth.clients_hint') ?></p>
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

<h2 class="subhead"><?= te('llm.oauth.connections_title') ?></h2>
<p class="muted"><?= te('llm.oauth.connections_hint') ?></p>
<p class="muted"><?= te('llm.oauth.connections_issuer', ['base' => $oauthBase]) ?></p>
<?php if ($connections === []): ?>
  <p class="muted"><?= te('llm.oauth.connections_empty') ?></p>
<?php else: ?>
<table class="data-table">
  <thead>
    <tr>
      <th><?= te('llm.oauth.col.user') ?></th>
      <th><?= te('llm.oauth.col.client') ?></th>
      <th><?= te('llm.oauth.col.scopes') ?></th>
      <th><?= te('llm.oauth.col.connected_at') ?></th>
      <th><?= te('llm.oauth.col.refresh_until') ?></th>
      <th></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($connections as $conn):
      $cname = trim((string)($conn['client_name'] ?? ''));
      if ($cname === '') {
          $cid = (string)($conn['client_id'] ?? '');
          $cname = $cid !== '' ? $cid : '—';
      }
      ?>
    <tr>
      <td><?= UsersRepository::badgeHtml((int)($conn['user_id'] ?? 0), true) ?></td>
      <td>
        <span><?= e($cname) ?></span>
        <div class="muted"><code><?= e((string)($conn['client_id'] ?? '')) ?></code></div>
      </td>
      <td><code><?= e((string)($conn['scope'] ?? '')) ?></code></td>
      <td><?= (int)($conn['created_at'] ?? 0) > 0 ? e(I18n::formatDate((int)$conn['created_at'], 'datetime')) : '—' ?></td>
      <td><?= (int)($conn['refresh_expires_at'] ?? 0) > 0 ? e(I18n::formatDate((int)$conn['refresh_expires_at'], 'datetime')) : '—' ?></td>
      <td>
        <form method="post" style="display:inline" onsubmit="return confirm(<?= json_encode(t('llm.oauth.revoke_confirm'), JSON_HEX_TAG | JSON_HEX_APOS) ?>);">
          <input type="hidden" name="action" value="revoke_connection">
          <input type="hidden" name="id" value="<?= (int)($conn['id'] ?? 0) ?>">
          <button type="submit" class="btn"><?= te('llm.oauth.revoke') ?></button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
