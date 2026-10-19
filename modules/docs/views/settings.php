<?php
declare(strict_types=1);
/** @var bool $qrArchive */
/** @var bool $qrLib */
/** @var array<string,string> $org */
/** @var string $message */
$long = ['license_scope', 'license_issuer_address', 'address', 'registrar'];
?>
<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<form method="post" class="form-grid form-wide">
  <fieldset>
    <legend><?= te('docs.settings.sec_org') ?></legend>
    <p class="form-hint"><?= te('docs.settings.org_hint') ?></p>
    <?php foreach (DocsConfig::ORG_KEYS as $k): ?>
      <label><?= te('docs.org.' . $k) ?> <code class="muted">{org.<?= e($k) ?>}</code>
        <?php if (in_array($k, $long, true)): ?>
          <textarea name="org[<?= e($k) ?>]" rows="<?= $k === 'license_scope' ? 4 : 2 ?>"><?= e($org[$k] ?? '') ?></textarea>
        <?php else: ?>
          <input name="org[<?= e($k) ?>]" value="<?= e($org[$k] ?? '') ?>" maxlength="500"
                 placeholder="<?= te('docs.sample.org.' . $k) ?>">
        <?php endif; ?>
      </label>
    <?php endforeach; ?>
  </fieldset>
  <fieldset>
    <legend><?= te('docs.settings.sec_qr') ?></legend>
    <label class="check">
      <input type="checkbox" name="qr_archive" value="1"<?= $qrArchive ? ' checked' : '' ?>>
      <?= te('docs.settings.qr_archive') ?>
    </label>
    <p class="form-hint"><?= te('docs.settings.qr_hint') ?></p>
    <?php if (!$qrLib): ?>
      <p class="flash flash-warn"><?= te('docs.settings.qr_no_lib') ?></p>
    <?php endif; ?>
  </fieldset>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
  </div>
</form>
