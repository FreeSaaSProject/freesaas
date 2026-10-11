<?php
declare(strict_types=1);
/** @var array<string,string> $cfg */
$mode = $cfg['mode'] ?? 'local';
?>
<fieldset class="form-fieldset">
  <legend><?= te('audio.settings.storage') ?></legend>
  <p class="muted"><?= te('audio.settings.storage_hint') ?></p>
  <input type="hidden" name="audio_storage_save" value="1">
  <div class="form-row-inline">
    <label><?= te('audio.settings.mode') ?>
      <select name="audio_storage_mode">
        <option value="local"<?= $mode === 'local' ? ' selected' : '' ?>><?= te('audio.settings.mode_local') ?></option>
        <option value="ftp"<?= $mode === 'ftp' ? ' selected' : '' ?>><?= te('audio.settings.mode_ftp') ?></option>
      </select>
    </label>
  </div>
  <div class="form-row-inline">
    <label><?= te('audio.settings.inbox') ?>
      <input name="audio_inbox" value="<?= e($cfg['audio_inbox'] ?? 'audio') ?>">
    </label>
    <label><?= te('audio.settings.parsed') ?>
      <input name="audio_parsed" value="<?= e($cfg['audio_parsed'] ?? 'audioParsed') ?>">
    </label>
    <label><?= te('audio.settings.chunks') ?>
      <input name="audio_chunks" value="<?= e($cfg['audio_chunks'] ?? 'audiochunks') ?>">
    </label>
  </div>
  <div class="form-row-inline">
    <label><?= te('audio.settings.ftp_host') ?>
      <input name="audio_ftp_host" value="<?= e($cfg['ftp_host'] ?? '') ?>">
    </label>
    <label><?= te('audio.settings.ftp_port') ?>
      <input name="audio_ftp_port" value="<?= e($cfg['ftp_port'] ?? '21') ?>">
    </label>
    <label><?= te('audio.settings.ftp_user') ?>
      <input name="audio_ftp_user" value="<?= e($cfg['ftp_user'] ?? '') ?>">
    </label>
  </div>
  <div class="form-row-inline">
    <label for="audio-ftp-pass"><?= te('audio.settings.ftp_pass') ?>
      <span class="auth-pass">
        <input type="password" name="audio_ftp_pass" id="audio-ftp-pass"
               value="<?= e($cfg['ftp_pass'] ?? '') ?>"
               autocomplete="new-password">
        <button type="button"
                class="auth-pass-toggle"
                data-password-toggle
                aria-pressed="false"
                aria-label="<?= te('audio.settings.ftp_pass_show') ?>"
                data-label-show="<?= te('audio.settings.ftp_pass_show') ?>"
                data-label-hide="<?= te('audio.settings.ftp_pass_hide') ?>"
                title="<?= te('audio.settings.ftp_pass_show') ?>">
          <span class="auth-pass-icon" aria-hidden="true"></span>
        </button>
      </span>
    </label>
  </div>
  <p class="muted"><?= te('audio.settings.ftp_pass_hint') ?></p>
  <div class="form-row-inline">
    <label><?= te('audio.settings.ftp_base') ?>
      <input name="audio_ftp_base" value="<?= e($cfg['ftp_base_path'] ?? '/') ?>">
    </label>
    <label><?= te('audio.settings.ftp_http') ?>
      <input name="audio_ftp_http" value="<?= e($cfg['ftp_http_url'] ?? '') ?>">
    </label>
    <label><?= te('audio.settings.stem_cache') ?>
      <input name="audio_stem_cache" value="<?= e($cfg['stem_cache_dir'] ?? 'var/audio_stem_cache') ?>">
    </label>
  </div>
</fieldset>
