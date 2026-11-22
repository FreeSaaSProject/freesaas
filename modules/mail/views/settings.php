<?php
declare(strict_types=1);
/** @var string $message */
/** @var string $error */
/** @var array<string,mixed> $values */
/** @var int $pending */
$values = is_array($values ?? null) ? $values : [];
$enc = (string)($values['smtp_encryption'] ?? 'tls');
?>
<section class="page-head">
  <p class="lede"><?= te('mail.settings.lede') ?></p>
  <p class="muted"><?= te('mail.settings.ini_hint') ?></p>
  <p>
    <a href="/mail/sends"><?= te('mail.menu.sends') ?></a>
    · <?= te('mail.health.queue', ['n' => (string)(int)($pending ?? 0)]) ?>
    <?php if (empty($values['configured'])): ?>
      · <span class="flash flash-warn" style="display:inline;padding:0.15rem 0.5rem"><?= te('mail.settings.not_configured') ?></span>
    <?php endif; ?>
  </p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid">
  <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
  <input type="hidden" name="action" value="save">
  <fieldset>
    <legend><?= te('mail.settings.sec.smtp') ?></legend>
    <label><?= te('mail.settings.smtp_host') ?>
      <input name="smtp_host" value="<?= e((string)($values['smtp_host'] ?? '')) ?>" autocomplete="off" placeholder="smtp.example.com">
    </label>
    <label><?= te('mail.settings.smtp_port') ?>
      <input name="smtp_port" type="number" min="1" max="65535" value="<?= e((string)($values['smtp_port'] ?? 587)) ?>" autocomplete="off">
    </label>
    <label><?= te('mail.settings.smtp_encryption') ?>
      <select name="smtp_encryption">
        <?php foreach (MailConfig::ENCRYPTIONS as $opt): ?>
          <option value="<?= e($opt) ?>"<?= $enc === $opt ? ' selected' : '' ?>><?= te('mail.settings.encryption.' . $opt) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= te('mail.settings.smtp_user') ?>
      <input name="smtp_user" value="<?= e((string)($values['smtp_user'] ?? '')) ?>" autocomplete="off">
    </label>
    <label><?= te('mail.settings.smtp_pass') ?>
      <input name="smtp_pass" type="password" value="<?= e((string)($values['smtp_pass'] ?? '')) ?>" autocomplete="new-password">
    </label>
    <p class="muted"><?= te('mail.settings.pass_hint') ?></p>
  </fieldset>
  <fieldset>
    <legend><?= te('mail.settings.sec.sender') ?></legend>
    <label><?= te('mail.settings.from_email') ?>
      <input name="from_email" type="email" value="<?= e((string)($values['from_email'] ?? '')) ?>" autocomplete="off" placeholder="noreply@example.com">
    </label>
    <label><?= te('mail.settings.from_name') ?>
      <input name="from_name" value="<?= e((string)($values['from_name'] ?? '')) ?>" autocomplete="off">
    </label>
    <label><?= te('mail.settings.reply_to') ?>
      <input name="reply_to" type="email" value="<?= e((string)($values['reply_to'] ?? '')) ?>" autocomplete="off">
    </label>
  </fieldset>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
  </div>
</form>

<form method="post" class="form-grid">
  <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
  <input type="hidden" name="action" value="test">
  <fieldset>
    <legend><?= te('mail.settings.sec.test') ?></legend>
    <p class="muted"><?= te('mail.settings.test_hint') ?></p>
    <label><?= te('mail.settings.test_to') ?>
      <input name="to" type="email" required autocomplete="off" placeholder="you@example.com">
    </label>
    <label><?= te('mail.settings.test_subject') ?>
      <input name="subject" autocomplete="off">
    </label>
    <label><?= te('mail.settings.test_text') ?>
      <textarea name="text" rows="4"></textarea>
    </label>
  </fieldset>
  <div class="page-actions">
    <button class="btn" type="submit"><?= te('mail.settings.test_btn') ?></button>
  </div>
</form>
