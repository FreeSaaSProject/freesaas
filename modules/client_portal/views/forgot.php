<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $channels */
$channels = is_array($channels ?? null) ? $channels : [];
?>
<section class="cp-auth">
  <div class="cp-auth-card">
    <h1><?= te('client_portal.reset.forgot_title') ?></h1>
    <p class="cp-auth-lead"><?= te('client_portal.reset.forgot_lead_bots') ?></p>
    <?php if ($channels === []): ?>
      <p class="flash err"><?= te('client_portal.reset.no_channels') ?></p>
    <?php else: ?>
      <ul class="cp-reset-channels">
        <?php foreach ($channels as $ch): ?>
          <li>
            <strong><?= e((string)$ch['label']) ?></strong>
            <?php if ((string)($ch['hint'] ?? '') !== ''): ?>
              <span class="muted"> — <?= e((string)$ch['hint']) ?></span>
            <?php endif; ?>
            <?php if ((string)($ch['url'] ?? '') !== ''): ?>
              <div>
                <a class="site-button btn" href="<?= e((string)$ch['url']) ?>" target="_blank" rel="noopener">
                  <?= te('client_portal.reset.open_bot_named', ['name' => (string)$ch['label']]) ?>
                </a>
              </div>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <p class="muted"><?= te('client_portal.reset.forgot_bot_howto') ?></p>
    <?php endif; ?>
    <p class="cp-auth-actions">
      <a class="site-button btn outline" href="/cabinet/login"><?= te('client_portal.login.title') ?></a>
    </p>
  </div>
</section>
