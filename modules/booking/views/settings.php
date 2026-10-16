<?php
declare(strict_types=1);
/** @var array<string,string> $cfg */
/** @var string $message */
/** @var string $error */
?>
<section class="page-head">
  <h1><?= te('booking.settings.title') ?></h1>
  <p class="muted"><?= te('booking.settings.lede') ?></p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid form-wide">
  <fieldset>
    <legend><?= te('booking.settings.sec.lead') ?></legend>
    <label><?= te('booking.settings.lead_sec') ?>
      <input type="number" name="lead_sec" min="0" max="7200" step="60"
             value="<?= e((string)($cfg['lead_sec'] ?? '180')) ?>">
      <span class="muted"><?= te('booking.settings.lead_sec_hint') ?></span>
    </label>
  </fieldset>

  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/booking/new"><?= te('core.common.cancel') ?></a>
  </div>
</form>
