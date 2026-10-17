<?php
declare(strict_types=1);
/** @var array<string,string> $cfg */
/** @var list<array<string,mixed>> $hosts */
/** @var bool $tablesReady */
/** @var string $message */
/** @var string $error */
?>
<section class="page-head">
  <h1><?= te('client_mcp.settings.title') ?></h1>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>
<?php if (!$tablesReady): ?><div class="flash flash-warn"><?= te('client_mcp.health.table_missing') ?></div><?php endif; ?>

<p class="muted"><?= te('client_mcp.settings.intro') ?></p>

<form method="post" class="form-grid form-wide">
  <?php foreach ($hosts as $h): ?>
    <?php $hid = (int)$h['id']; $s = $h['settings']; $p = 'hosts[' . $hid . ']'; ?>
    <fieldset>
      <legend><?= e((string)$h['host']) ?><?= (string)$h['name'] !== '' ? ' — ' . e((string)$h['name']) : '' ?></legend>
      <input type="hidden" name="<?= e($p) ?>[present]" value="1">
      <?php if (empty($h['site_enabled'])): ?><p class="muted"><?= te('client_mcp.settings.site_disabled') ?></p><?php endif; ?>
      <label class="check"><input type="checkbox" name="<?= e($p) ?>[enabled]" value="1"<?= !empty($s['enabled']) ? ' checked' : '' ?>> <?= te('client_mcp.settings.enabled') ?></label>
      <label><?= te('client_mcp.settings.server_name') ?>
        <input name="<?= e($p) ?>[server_name]" maxlength="80" value="<?= e((string)$s['server_name']) ?>" placeholder="<?= e((string)$h['name']) ?>">
      </label>
      <label><?= te('client_mcp.settings.description') ?>
        <textarea name="<?= e($p) ?>[description]" rows="2" maxlength="400"><?= e((string)$s['description']) ?></textarea>
        <span class="muted"><?= te('client_mcp.settings.description_hint') ?></span>
      </label>
      <label><?= te('client_mcp.settings.consent_url') ?>
        <input name="<?= e($p) ?>[consent_url]" value="<?= e((string)$s['consent_url']) ?>" placeholder="/page/offer/">
        <span class="muted"><?= te('client_mcp.settings.consent_url_hint') ?></span>
      </label>
      <label class="check"><input type="checkbox" name="<?= e($p) ?>[show_note]" value="1"<?= !empty($s['show_note']) ? ' checked' : '' ?>> <?= te('client_mcp.settings.show_note') ?></label>
      <label><?= te('client_mcp.settings.note_text') ?>
        <input name="<?= e($p) ?>[note_text]" maxlength="400" value="<?= e((string)($s['note_text'] ?? '')) ?>" placeholder="<?= e(t('client_mcp.note.default')) ?>">
        <span class="muted"><?= te('client_mcp.settings.note_text_hint') ?></span>
      </label>

      <p><b><?= te('client_mcp.settings.tools') ?></b></p>
      <table class="data-table">
        <tbody>
        <?php foreach ($h['tools'] as $tool): ?>
          <tr>
            <td>
              <label class="check">
                <input type="checkbox" name="<?= e($p) ?>[tools][]" value="<?= e($tool['name']) ?>"<?= $tool['enabled'] ? ' checked' : '' ?><?= $tool['core'] ? ' checked disabled' : '' ?>>
                <code><?= e($tool['name']) ?></code>
              </label>
            </td>
            <td><?= e($tool['title']) ?></td>
            <td class="muted"><?= e($tool['module']) ?></td>
            <td class="muted">
              <?php if ($tool['core']): ?><?= te('client_mcp.settings.tool_core') ?><?php endif; ?>
              <?php if ($tool['reason'] !== ''): ?><?= te('client_mcp.settings.tool_unavailable', ['reason' => $tool['reason']]) ?><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <details class="muted">
        <summary><?= te('client_mcp.settings.endpoints') ?></summary>
        <table class="data-table">
          <tbody>
            <tr><th><?= te('client_mcp.settings.endpoint_mcp') ?></th><td><code><?= e($h['endpoints']['mcp']) ?></code></td></tr>
            <tr><th><?= te('client_mcp.settings.endpoint_card') ?></th><td><code><?= e($h['endpoints']['server_card']) ?></code></td></tr>
            <tr><th><?= te('client_mcp.settings.endpoint_catalog') ?></th><td><code><?= e($h['endpoints']['ai_catalog']) ?></code></td></tr>
          </tbody>
        </table>
        <p class="muted"><?= te('client_mcp.settings.snippet_hint') ?></p>
        <pre><code><?= e((string)$h['snippet']) ?></code></pre>
        <p><?= te('client_mcp.settings.stats_line', [
            'active' => (string)$h['stats']['active'],
            'day' => (string)$h['stats']['last_day'],
            'bookings' => (string)$h['stats']['bookings_last_day'],
        ]) ?></p>
      </details>
    </fieldset>
  <?php endforeach; ?>

  <fieldset>
    <legend><?= te('client_mcp.settings.common') ?></legend>
    <label class="check"><input type="checkbox" name="require_name_for_new" value="1"<?= ($cfg['require_name_for_new'] ?? '1') === '1' ? ' checked' : '' ?>> <?= te('client_mcp.settings.require_name_for_new') ?></label>
    <label><?= te('client_mcp.settings.currency') ?>
      <input name="currency" maxlength="3" value="<?= e($cfg['currency'] ?? 'RUB') ?>">
    </label>
    <label><?= te('client_mcp.settings.sessions_per_ip_hour') ?>
      <input type="number" name="sessions_per_ip_hour" min="1" max="1000" value="<?= e($cfg['sessions_per_ip_hour'] ?? '20') ?>">
    </label>
    <label><?= te('client_mcp.settings.bookings_per_phone_day') ?>
      <input type="number" name="bookings_per_phone_day" min="1" max="50" value="<?= e($cfg['bookings_per_phone_day'] ?? '3') ?>">
    </label>
    <label><?= te('client_mcp.settings.token_ttl_hours') ?>
      <input type="number" name="token_ttl_hours" min="1" max="720" value="<?= e($cfg['token_ttl_hours'] ?? '24') ?>">
    </label>
    <p class="muted"><?= te('client_mcp.settings.verification_hint') ?></p>
  </fieldset>

  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
  </div>
</form>
