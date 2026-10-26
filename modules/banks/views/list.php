<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var list<array{id:string,label:string,configured:bool,connection_id:int,setup_status?:string,setup_step?:string}> $providers */
/** @var bool $canConnect */
/** @var bool $canSettings */
/** @var list<array<string,mixed>> $alfaIntegrations */
/** @var bool $autoSyncOn */
/** @var array{job_id:string,enabled:int,last_status:string,last_message:string,last_finished_at:int,every:?int}|null $cronStatus */
/** @var array<string,string> $cfg */
/** @var array<int,string> $moveTypes */
/** @var string $message */
/** @var string $error */
$autoSyncOn = !empty($autoSyncOn);
$cronStatus = is_array($cronStatus ?? null) ? $cronStatus : null;
$anyConfigured = false;
foreach ($providers as $p) {
    if (!empty($p['configured'])) {
        $anyConfigured = true;
        break;
    }
}
$hasConnections = $items !== [];
$showSetupNeeded = !$anyConfigured && !$hasConnections;
?>
<?php if ($message !== ''): ?>
  <div class="flash flash-ok"><?= e($message) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="flash flash-warn"><?= e($error) ?></div>
<?php endif; ?>

<section class="page-head">
  <div class="page-head-row">
    <div>
      <h1><?= te('banks.list.title') ?></h1>
      <p class="muted banks-cron-status">
        <?= te('banks.list.cron_status') ?>:
        <?php if (!$autoSyncOn): ?>
          <?= te('banks.list.cron_off') ?>
        <?php elseif ($cronStatus === null || (int)($cronStatus['last_finished_at'] ?? 0) < 1): ?>
          <?= te('banks.list.cron_never') ?>
          <?php if ($cronStatus !== null && (int)($cronStatus['every'] ?? 0) > 0): ?>
            · <?= e(t('banks.list.cron_every', ['n' => (int)$cronStatus['every']])) ?>
          <?php endif; ?>
        <?php else: ?>
          <?= e(t('banks.list.cron_last', [
              'when' => date('d.m.Y H:i', (int)$cronStatus['last_finished_at']),
              'status' => (string)($cronStatus['last_status'] !== '' ? $cronStatus['last_status'] : '—'),
          ])) ?>
          <?php if ((string)($cronStatus['last_message'] ?? '') !== ''): ?>
            · <?= e((string)$cronStatus['last_message']) ?>
          <?php endif; ?>
        <?php endif; ?>
      </p>
    </div>
  </div>
</section>

<?php if (!empty($canSettings)): ?>
<section class="banks-hub-common">
  <h2><?= te('banks.list.hub_common') ?></h2>
  <p class="muted"><?= te('banks.list.hub_common_hint') ?></p>
  <form method="post" class="form-grid banks-hub-common-form">
    <label><?= te('banks.settings.default_move_type_id') ?>
      <select name="default_move_type_id">
        <option value="0">—</option>
        <?php foreach ($moveTypes as $id => $name): ?>
          <option value="<?= (int)$id ?>"<?= (string)($cfg['default_move_type_id'] ?? '0') === (string)$id ? ' selected' : '' ?>><?= e((string)$name) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="check">
      <input type="checkbox" name="auto_sync_enabled" value="1"<?= ($cfg['auto_sync_enabled'] ?? '0') === '1' ? ' checked' : '' ?>>
      <?= te('banks.settings.auto_sync_enabled') ?>
    </label>
    <label><?= te('banks.settings.auto_sync_lookback_days') ?>
      <input type="number" name="auto_sync_lookback_days" min="1" max="90" value="<?= e((string)($cfg['auto_sync_lookback_days'] ?? '2')) ?>">
    </label>
    <label><?= te('banks.settings.worker_token') ?>
      <input type="password" name="worker_token" value="" autocomplete="new-password" placeholder="<?= te('banks.settings.secret_keep') ?>">
    </label>
    <div class="page-actions">
      <button type="submit" class="btn-primary"><?= te('core.common.save') ?></button>
    </div>
  </form>
</section>
<?php endif; ?>

<?php if ($showSetupNeeded): ?>
  <div class="flash flash-warn">
    <?= te('banks.list.setup_needed') ?>
  </div>
<?php endif; ?>

<?php if (!$canConnect): ?>
  <div class="flash flash-warn"><?= te('banks.list.no_connect_perm') ?></div>
<?php endif; ?>

<p class="muted"><?= te('banks.list.sync_model') ?></p>

<?php if (!empty($canSettings) && !empty($alfaIntegrations) && is_array($alfaIntegrations)): ?>
<section class="banks-hub-common">
  <h2><?= te('banks.int.alfa_heading') ?></h2>
  <p class="muted"><?= te('banks.int.alfa_lede') ?></p>
  <div class="banks-connect-grid">
    <?php foreach ($alfaIntegrations as $ai):
      $aid = (int)$ai['id'];
      $aenv = (string)($ai['env'] ?? '');
      ?>
      <div class="banks-connect-card">
        <div class="banks-connect-card-title"><?= e((string)($ai['label'] ?? '')) ?></div>
        <p class="muted"><span class="bank-chip"><?= e($aenv) ?></span> · <?= e((string)($ai['status'] ?? '')) ?></p>
        <div class="banks-connect-actions">
          <a class="btn-secondary" href="/banks/integrations/<?= $aid ?>"><?= te('banks.list.open_settings') ?></a>
          <?php if ($canConnect): ?>
            <a class="btn-primary" href="/banks/oauth/alfa_ul/start?integration_id=<?= $aid ?>"><?= te('banks.list.connect') ?></a>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<section class="banks-connect-panel">
  <h2><?= te('banks.list.connect_heading') ?></h2>
  <div class="banks-connect-grid">
    <?php foreach ($providers as $p):
      $connId = (int)($p['connection_id'] ?? 0);
      $configured = !empty($p['configured']);
      $setupStatus = (string)($p['setup_status'] ?? 'not_started');
      $setupHref = '/banks/setup/' . rawurlencode((string)$p['id']);
      $nConn = (int)($p['connections_count'] ?? 0);
      ?>
      <div class="banks-connect-card<?= $connId > 0 ? ' is-connected' : '' ?>">
        <div class="banks-connect-card-title"><?= e((string)$p['label']) ?></div>
        <?php if (empty($p['verified'])): ?>
          <p class="banks-unverified-chip" title="<?= te('banks.list.unverified_hint') ?>"><?= te('banks.list.unverified') ?></p>
        <?php endif; ?>
        <?= BanksConfig::portalLinksHtml((string)$p['id']) ?>
        <p class="muted banks-setup-chip"><?= e(t('banks.list.setup_status', ['status' => $setupStatus])) ?></p>
        <?php if ($nConn > 0): ?>
          <p class="muted banks-setup-chip"><?= e(t('banks.list.connections_n', ['n' => $nConn])) ?></p>
        <?php endif; ?>
        <div class="banks-connect-actions">
          <?php if (!empty($canSettings)): ?>
            <?php if ($configured || $connId > 0 || $setupStatus === 'ready' || $setupStatus === 'connected'): ?>
              <a class="btn-secondary" href="/banks/settings?tab=<?= e(urlencode((string)$p['id'])) ?>">
                <?= te('banks.list.open_settings') ?>
              </a>
            <?php endif; ?>
            <a class="btn-secondary" href="<?= e($setupHref) ?>">
              <?= $setupStatus === 'not_started' || $setupStatus === 'in_progress'
                  ? te('banks.list.setup_continue')
                  : te('banks.list.setup_wizard') ?>
            </a>
          <?php endif; ?>
          <?php if ($connId > 0): ?>
            <a class="btn-primary" href="/banks/connections/<?= $connId ?>">
              <?= te('banks.list.open') ?>
            </a>
            <?php if ($configured && $canConnect): ?>
              <a class="btn-secondary" href="/banks/oauth/<?= e((string)$p['id']) ?>/start"
                 title="<?= te('banks.list.reconnect_hint') ?>">
                <?= te('banks.list.reconnect') ?>
              </a>
            <?php endif; ?>
          <?php elseif ($configured && $canConnect): ?>
            <a class="btn-primary" href="/banks/oauth/<?= e((string)$p['id']) ?>/start">
              <?= te('banks.list.connect') ?>
            </a>
          <?php elseif (empty($canSettings)): ?>
            <span class="btn-secondary banks-connect-disabled" title="<?= te('banks.err.not_configured') ?>">
              <?= te('banks.err.not_configured') ?>
            </span>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<div class="table-wrap"><table class="data">
<thead><tr>
  <th>ID</th>
  <th><?= te('banks.list.col.provider') ?></th>
  <th><?= te('banks.list.col.status') ?></th>
  <th><?= te('banks.list.col.party') ?></th>
  <th><?= te('banks.list.col.sync_role') ?></th>
  <th><?= te('banks.list.col.updated') ?></th>
  <th></th>
</tr></thead>
<tbody>
<?php foreach ($items as $r): ?>
<tr>
  <td class="muted"><?= (int)$r['id'] ?></td>
  <td><?= e(BanksConfig::providerLabel((string)$r['provider'])) ?></td>
  <td><?= e(t('banks.status.' . (string)$r['status'])) ?></td>
  <td><?= e((string)(($r['label'] ?? '') !== '' ? $r['label'] : ($r['external_party'] ?? ''))) ?></td>
  <td>
    <?php if (!empty($r['in_auto_sync'])): ?>
      <span class="bank-chip"><?= te('banks.list.role_auto_sync') ?></span>
      <span class="muted">· <?= e(t('banks.list.syncing_n', ['n' => (int)($r['syncing_count'] ?? 0)])) ?></span>
    <?php elseif ((int)($r['linked_count'] ?? 0) > 0): ?>
      <span class="muted"><?= te('banks.list.role_linked_only') ?></span>
    <?php else: ?>
      <span class="muted"><?= te('banks.list.role_idle') ?></span>
    <?php endif; ?>
  </td>
  <td class="muted"><?= (int)$r['dt_update'] > 0 ? e(date('d.m.Y H:i', (int)$r['dt_update'])) : '—' ?></td>
  <td><a href="/banks/connections/<?= (int)$r['id'] ?>"><?= te('banks.list.open') ?></a></td>
</tr>
<?php endforeach; ?>
<?php if (!$items): ?>
<tr><td colspan="7" class="muted"><?= te('banks.list.empty') ?></td></tr>
<?php endif; ?>
</tbody></table></div>
