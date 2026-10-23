<?php
declare(strict_types=1);
/** @var string $message */
/** @var string $error */
/** @var array<string,mixed> $settings */
$domain = (string)($settings['session_cookie_domain'] ?? '');
$suggest = (string)($settings['session_cookie_domain_suggested'] ?? '');
$adminHost = (string)($settings['admin_host'] ?? '');
$publicHost = (string)($settings['public_host'] ?? '');
$publicPrint = !empty($settings['public_print_enabled']);
$publicPrintUrl = (string)($settings['public_print_url'] ?? '');
$activationMode = (string)($settings['activation_mode'] ?? StickerActivation::MODE_SCAN);
?>
<section class="page-head">
  <h1><?= te('stickers.settings.title') ?></h1>
  <p class="lede"><?= te('stickers.settings.lede') ?></p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid" style="max-width:40rem">
  <?= Csrf::field() ?>

  <fieldset class="form-fieldset">
    <legend><?= te('stickers.settings.sec.activation') ?></legend>
    <p class="muted"><?= te('stickers.settings.activation_intro') ?></p>
    <label class="check">
      <input type="radio" name="activation_mode" value="<?= e(StickerActivation::MODE_SCAN) ?>"<?= $activationMode !== StickerActivation::MODE_MANUAL ? ' checked' : '' ?>>
      <?= te('stickers.settings.activation_scan') ?>
    </label>
    <p class="muted"><?= te('stickers.settings.activation_scan_hint') ?></p>
    <label class="check">
      <input type="radio" name="activation_mode" value="<?= e(StickerActivation::MODE_MANUAL) ?>"<?= $activationMode === StickerActivation::MODE_MANUAL ? ' checked' : '' ?>>
      <?= te('stickers.settings.activation_manual') ?>
    </label>
    <p class="muted"><?= te('stickers.settings.activation_manual_hint') ?></p>
  </fieldset>

  <fieldset class="form-fieldset">
    <legend><?= te('stickers.settings.sec.session') ?></legend>
    <p class="muted"><?= te('stickers.settings.session_intro') ?></p>
    <p class="muted">
      <?= te('stickers.status.admin_host') ?>: <code><?= e($adminHost !== '' ? $adminHost : '—') ?></code>
      ·
      <?= te('stickers.status.public_host') ?>: <code><?= e($publicHost !== '' ? $publicHost : '—') ?></code>
    </p>
    <label>
      <?= te('stickers.settings.cookie_domain') ?>
      <input name="session_cookie_domain" value="<?= e($domain) ?>"
             placeholder="<?= e($suggest !== '' ? $suggest : '.example.com') ?>"
             autocomplete="off" spellcheck="false">
    </label>
    <p class="muted"><?= te('stickers.settings.cookie_domain_hint') ?></p>
    <?php if ($suggest !== '' && $domain === ''): ?>
      <p class="muted"><?= te('stickers.settings.cookie_domain_suggest', ['suggest' => $suggest]) ?></p>
    <?php endif; ?>
    <p class="muted"><?= te('stickers.settings.relogin_note') ?></p>
  </fieldset>

  <fieldset class="form-fieldset">
    <legend><?= te('stickers.settings.sec.public_print') ?></legend>
    <p class="muted"><?= te('stickers.status.public_print_hint') ?></p>
    <input type="hidden" name="public_print_present" value="1">
    <label class="check">
      <input type="checkbox" name="public_print_enabled" value="1"<?= $publicPrint ? ' checked' : '' ?>>
      <?= te('stickers.status.public_print_enable') ?>
    </label>
    <?php if ($publicPrint && $publicPrintUrl !== ''): ?>
      <p class="muted"><?= te('stickers.status.public_print_link') ?>:
        <a href="<?= e($publicPrintUrl) ?>" target="_blank" rel="noopener"><?= e($publicPrintUrl) ?></a>
      </p>
    <?php endif; ?>
  </fieldset>

  <div class="form-actions">
    <button type="submit" class="btn-primary"><?= te('stickers.settings.save') ?></button>
    <a class="btn" href="/stickers/status"><?= te('stickers.menu.status') ?></a>
  </div>
</form>
