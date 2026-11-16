<?php
declare(strict_types=1);
/** @var string $message */
/** @var string $error */
/** @var list<array<string,mixed>> $channels */
/** @var bool $maxSsl */
/** @var string $clientTelegramUsername */
/** @var string $clientMaxBotUrl */
/** @var array<string,array<string,mixed>> $telegramWebhooks */
/** @var array<string,array<string,mixed>> $telegramMe */
$clientTelegramUsername = $clientTelegramUsername ?? '';
$clientMaxBotUrl = $clientMaxBotUrl ?? '';
$telegramWebhooks = is_array($telegramWebhooks ?? null) ? $telegramWebhooks : [];
$telegramMe = is_array($telegramMe ?? null) ? $telegramMe : [];
?>
<section class="page-head">
  <p class="lede"><?= te('bots.settings.lede') ?></p>
  <p class="muted"><?= te('bots.settings.ini_hint') ?></p>
  <p>
    <a href="/bots/sends"><?= te('bots.menu.sends') ?></a>
    ·
    <a href="/bots/plugins"><?= te('bots.menu.plugins') ?></a>
    <?php if (ModuleManager::isEnabled('client_portal') && Auth::can('client_portal.manage')): ?>
      ·
      <a href="/client-portal/status"><?= te('client_portal.menu.status') ?></a>
    <?php endif; ?>
  </p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid">
  <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
  <input type="hidden" name="action" value="save">
  <fieldset>
    <legend><?= te('bots.settings.sec.channels') ?></legend>
    <?php foreach ($channels as $ch): ?>
      <?php
        $id = (string)$ch['id'];
        $audience = (string)$ch['audience'];
        $platform = (string)$ch['platform'];
        $audLabel = t('bots.settings.audience.' . $audience);
        $platLabel = t('bots.settings.platform.' . $platform);
        $wh = ($platform === 'telegram' && isset($telegramWebhooks[$audience]) && is_array($telegramWebhooks[$audience]))
            ? $telegramWebhooks[$audience]
            : null;
        $me = ($platform === 'telegram' && isset($telegramMe[$audience]) && is_array($telegramMe[$audience]))
            ? $telegramMe[$audience]
            : null;
      ?>
      <h3><?= e($audLabel . ' / ' . $platLabel) ?></h3>
      <label><?= te('bots.settings.token') ?>
        <input name="<?= e($id) ?>_token" value="<?= e((string)$ch['token_masked']) ?>" autocomplete="off" placeholder="<?= !empty($ch['has_token']) ? '••••' : '' ?>">
      </label>
      <label><?= te('bots.settings.secret') ?>
        <input name="<?= e($id) ?>_webhook_secret" value="<?= !empty($ch['has_secret']) ? '••••••••' : '' ?>" autocomplete="off">
      </label>
      <p class="muted"><?= te('bots.settings.secret_hint') ?></p>
      <p class="muted"><?= te('bots.settings.webhook') ?>: <code><?= e((string)$ch['webhook_path']) ?></code></p>
      <?php if ($me !== null && !empty($me['ok']) && (string)($me['username'] ?? '') !== ''): ?>
        <p class="muted">@<?= e((string)$me['username']) ?></p>
      <?php endif; ?>
      <?php if ($wh !== null): ?>
        <?php
          $whUrl = trim((string)($wh['url'] ?? ''));
          $whErr = trim((string)($wh['last_error_message'] ?? ''));
          $pending = (int)($wh['pending_update_count'] ?? 0);
          $subscribed = $whUrl !== '';
        ?>
        <p>
          <?php if ($subscribed && $whErr === ''): ?>
            <span class="flash flash-ok" style="display:inline;padding:0.15rem 0.5rem"><?= te('bots.settings.webhook_status.ok') ?></span>
          <?php elseif ($subscribed && $whErr !== ''): ?>
            <span class="flash flash-warn" style="display:inline;padding:0.15rem 0.5rem"><?= te('bots.settings.webhook_status.error') ?></span>
            — <?= e($whErr) ?>
          <?php else: ?>
            <span class="flash flash-warn" style="display:inline;padding:0.15rem 0.5rem"><?= te('bots.settings.webhook_status.none') ?></span>
          <?php endif; ?>
          <?php if ($pending > 0): ?>
            · <?= te('bots.settings.webhook_pending', ['n' => (string)$pending]) ?>
          <?php endif; ?>
        </p>
        <?php if ($whUrl !== ''): ?>
          <p class="muted"><code><?= e($whUrl) ?></code></p>
        <?php endif; ?>
      <?php endif; ?>
      <?php if (!empty($ch['has_token'])): ?>
        <p>
          <button
            class="btn"
            type="submit"
            form="bots-subscribe-<?= e($id) ?>"
            <?= Auth::can('bots.manage') ? '' : ' disabled' ?>
          ><?= te('bots.settings.subscribe_btn') ?></button>
        </p>
      <?php endif; ?>
    <?php endforeach; ?>
  </fieldset>
  <fieldset>
    <legend><?= te('bots.settings.sec.public_links') ?></legend>
    <p class="muted"><?= te('bots.settings.public_links_hint') ?></p>
    <label><?= te('bots.settings.client_telegram_username') ?>
      <input name="client_telegram_username" value="<?= e($clientTelegramUsername) ?>" autocomplete="off" placeholder="likespabot">
    </label>
    <label><?= te('bots.settings.client_max_bot_url') ?>
      <input name="client_max_bot_url" value="<?= e($clientMaxBotUrl) ?>" autocomplete="off" placeholder="https://max.ru/…">
    </label>
  </fieldset>
  <label class="check">
    <input type="checkbox" name="max_ssl_insecure" value="1"<?= $maxSsl ? ' checked' : '' ?>>
    <?= te('bots.settings.max_ssl_insecure') ?>
  </label>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
  </div>
</form>

<?php foreach ($channels as $ch): ?>
  <?php if (empty($ch['has_token'])) {
      continue;
  } ?>
  <form id="bots-subscribe-<?= e((string)$ch['id']) ?>" method="post" style="display:none">
    <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
    <input type="hidden" name="action" value="subscribe_webhook">
    <input type="hidden" name="audience" value="<?= e((string)$ch['audience']) ?>">
    <input type="hidden" name="platform" value="<?= e((string)$ch['platform']) ?>">
  </form>
<?php endforeach; ?>
