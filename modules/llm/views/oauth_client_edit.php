<?php
declare(strict_types=1);
/** @var array<string,mixed> $row */
/** @var bool $is_new */
/** @var string $message */
/** @var string $error */
/** @var string $shown_secret */
/** @var array<string,string> $endpoints */
$urisText = implode("\n", is_array($row['redirect_uris'] ?? null) ? $row['redirect_uris'] : []);
?>
<section class="page-head">
  <p class="lede"><?= te('llm.oauth.edit_lede') ?></p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<?php if ($shown_secret !== ''): ?>
  <div class="flash flash-ok">
    <p><?= te('llm.oauth.secret_once') ?></p>
    <p><code><?= e($shown_secret) ?></code></p>
  </div>
<?php endif; ?>

<form method="post" class="stack">
  <?php if (!$is_new): ?>
    <p>
      <label><?= te('llm.oauth.col.client_id') ?></label>
      <code><?= e((string)($row['client_id'] ?? '')) ?></code>
    </p>
  <?php endif; ?>

  <p>
    <label for="oauth-name"><?= te('llm.oauth.col.name') ?></label>
    <input id="oauth-name" name="name" required value="<?= e((string)($row['name'] ?? '')) ?>">
  </p>
  <p>
    <label for="oauth-uris"><?= te('llm.oauth.redirect_uris') ?></label>
    <textarea id="oauth-uris" name="redirect_uris" rows="4" required placeholder="https://chat.openai.com/aip/…/oauth/callback"><?= e($urisText) ?></textarea>
    <span class="muted"><?= te('llm.oauth.redirect_hint') ?></span>
  </p>
  <p>
    <label for="oauth-scopes"><?= te('llm.oauth.col.scopes') ?></label>
    <input id="oauth-scopes" name="scopes" value="<?= e((string)($row['scopes'] ?? 'ops.read')) ?>">
    <span class="muted"><?= te('llm.oauth.scopes_hint') ?></span>
  </p>
  <?php if (!$is_new): ?>
  <p>
    <label>
      <input type="checkbox" name="enabled" value="1" <?= !empty($row['enabled']) ? 'checked' : '' ?>>
      <?= te('llm.tokens.enabled') ?>
    </label>
  </p>
  <?php endif; ?>
  <p class="btn-row">
    <button type="submit" name="action" value="save" class="btn"><?= te('llm.tokens.save') ?></button>
    <a class="btn" href="<?= e(NavContext::backOr('/llm/oauth')) ?>"><?= te('core.common.cancel') ?></a>
  </p>
</form>

<?php if (!$is_new): ?>
<form method="post" class="stack" onsubmit="return confirm(<?= json_encode(t('llm.oauth.rotate_confirm'), JSON_HEX_TAG | JSON_HEX_APOS) ?>);">
  <button type="submit" name="action" value="rotate" class="btn"><?= te('llm.oauth.rotate') ?></button>
</form>

<details class="muted">
  <summary><?= te('llm.oauth.endpoints') ?></summary>
  <ul>
    <?php foreach ($endpoints as $k => $url): ?>
      <li><strong><?= e((string)$k) ?></strong>: <code><?= e((string)$url) ?></code></li>
    <?php endforeach; ?>
  </ul>
</details>
<?php endif; ?>
