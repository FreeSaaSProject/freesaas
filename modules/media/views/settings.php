<?php
declare(strict_types=1);
/** @var array<string,mixed> $settings */
/** @var string $message */
/** @var string $error */
$s = is_array($settings ?? null) ? $settings : [];
?>
<section class="page-head">
  <h1><?= te('media.settings.title') ?></h1>
</section>
<?php if (($message ?? '') !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if (($error ?? '') !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid form-wide">
  <input type="hidden" name="action" value="save">
  <label><?= te('media.settings.mode') ?>
    <select name="mode">
      <option value="local"<?= ($s['mode'] ?? '') === 'local' ? ' selected' : '' ?>><?= te('media.settings.mode_local') ?></option>
      <option value="ftp"<?= ($s['mode'] ?? '') === 'ftp' ? ' selected' : '' ?>><?= te('media.settings.mode_ftp') ?></option>
    </select>
  </label>
  <label><?= te('media.settings.storage_root') ?>
    <input name="storage_root" value="<?= e((string)($s['storage_root'] ?? 'var/media')) ?>">
  </label>
  <label><?= te('media.settings.max_upload_bytes') ?>
    <input type="number" min="1024" name="max_upload_bytes" value="<?= e((string)($s['max_upload_bytes'] ?? 20971520)) ?>">
  </label>
  <label><?= te('media.settings.max_edge_px') ?>
    <input type="number" min="256" name="max_edge_px" value="<?= e((string)($s['max_edge_px'] ?? 2048)) ?>">
  </label>
  <label><?= te('media.settings.thumb_edge_px') ?>
    <input type="number" min="64" name="thumb_edge_px" value="<?= e((string)($s['thumb_edge_px'] ?? 400)) ?>">
  </label>
  <label><?= te('media.settings.jpeg_quality') ?>
    <input type="number" min="40" max="95" name="jpeg_quality" value="<?= e((string)($s['jpeg_quality'] ?? 85)) ?>">
  </label>
  <label class="check">
    <input type="checkbox" name="strip_exif" value="1"<?= !empty($s['strip_exif']) ? ' checked' : '' ?>>
    <?= te('media.settings.strip_exif') ?>
  </label>
  <label><?= te('media.settings.allowed_mimes') ?>
    <input name="allowed_mimes" value="<?= e((string)($s['allowed_mimes'] ?? '')) ?>">
  </label>

  <fieldset>
    <legend>FTP</legend>
    <label><?= te('media.settings.ftp_host') ?>
      <input name="ftp_host" value="<?= e((string)($s['ftp_host'] ?? '')) ?>" autocomplete="off">
    </label>
    <label><?= te('media.settings.ftp_port') ?>
      <input type="number" name="ftp_port" value="<?= e((string)($s['ftp_port'] ?? '21')) ?>">
    </label>
    <label><?= te('media.settings.ftp_user') ?>
      <input name="ftp_user" value="<?= e((string)($s['ftp_user'] ?? '')) ?>" autocomplete="off">
    </label>
    <label><?= te('media.settings.ftp_pass') ?>
      <input type="password" name="ftp_pass" value="" autocomplete="new-password" placeholder="<?= e((string)($s['ftp_pass_masked'] ?? '')) ?>">
    </label>
    <label class="check">
      <input type="checkbox" name="ftp_pass_clear" value="1">
      <?= te('media.settings.ftp_pass_clear') ?>
    </label>
    <label class="check">
      <input type="checkbox" name="ftp_ssl" value="1"<?= !empty($s['ftp_ssl']) ? ' checked' : '' ?>>
      <?= te('media.settings.ftp_ssl') ?>
    </label>
    <label class="check">
      <input type="checkbox" name="ftp_passive" value="1"<?= !empty($s['ftp_passive']) ? ' checked' : '' ?>>
      <?= te('media.settings.ftp_passive') ?>
    </label>
    <label><?= te('media.settings.ftp_base_path') ?>
      <input name="ftp_base_path" value="<?= e((string)($s['ftp_base_path'] ?? '')) ?>">
    </label>
  </fieldset>

  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
  </div>
</form>

<form method="post" class="form-grid" style="margin-top:1rem">
  <p class="muted"><?= te('media.settings.copy_hint') ?></p>
  <div class="page-actions">
    <button class="btn-secondary" type="submit" name="action" value="copy_ftp_audio"><?= te('media.settings.copy_ftp_audio') ?></button>
    <button class="btn-secondary" type="submit" name="action" value="copy_ftp_backups"><?= te('media.settings.copy_ftp_backups') ?></button>
  </div>
</form>
