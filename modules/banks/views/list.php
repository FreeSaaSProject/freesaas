<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var list<array{id:string,label:string,configured:bool,connection_id:int}> $providers */
/** @var bool $canConnect */
/** @var bool $canSettings */
/** @var bool $autoSyncOn */
/** @var array{job_id:string,enabled:int,last_status:string,last_message:string,last_finished_at:int,every:?int}|null $cronStatus */
$flash = flash_take();
$anyConfigured = false;
foreach ($providers as $p) {
    if (!empty($p['configured'])) {
        $anyConfigured = true;
        break;
    }
}
// Existing connections (tokens in DB) count as set up even if ini keys were cleared.
$hasConnections = $items !== [];
$showSetupNeeded = !$anyConfigured && !$hasConnections;
$autoSyncOn = !empty($autoSyncOn);
$cronStatus = is_array($cronStatus ?? null) ? $cronStatus : null;
?>
<?php if ($flash): ?>
  <div class="flash flash-<?= e($flash['type'] === 'ok' ? 'ok' : 'warn') ?>"><?= e((string)$flash['message']) ?></div>
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
    <div class="page-actions">
      <?php if (!empty($canSettings)): ?>
        <a class="btn-secondary" href="/banks/settings"><?= te('banks.menu.settings') ?></a>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php if ($showSetupNeeded): ?>
  <div class="flash flash-warn">
    <?= te('banks.list.setup_needed') ?>
    <?php if (!empty($canSettings)): ?>
      <a href="/banks/settings"><?= te('banks.list.open_settings') ?></a>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if (!$canConnect): ?>
  <div class="flash flash-warn"><?= te('banks.list.no_connect_perm') ?></div>
<?php else: ?>
<section class="banks-connect-panel">
  <h2><?= te('banks.list.connect_heading') ?></h2>
  <div class="banks-connect-grid">
    <?php foreach ($providers as $p):
      $connId = (int)($p['connection_id'] ?? 0);
      $configured = !empty($p['configured']);
      ?>
      <div class="banks-connect-card<?= $connId > 0 ? ' is-connected' : '' ?>">
        <div class="banks-connect-card-title"><?= e((string)$p['label']) ?></div>
        <?= BanksConfig::portalLinksHtml((string)$p['id']) ?>
        <div class="banks-connect-actions">
          <?php if ($connId > 0): ?>
            <a class="btn-primary" href="/banks/connections/<?= $connId ?>">
              <?= te('banks.list.open') ?>
            </a>
            <?php if ($configured): ?>
              <a class="btn-secondary" href="/banks/oauth/<?= e((string)$p['id']) ?>/start"
                 title="<?= te('banks.list.reconnect_hint') ?>">
                <?= te('banks.list.reconnect') ?>
              </a>
            <?php endif; ?>
          <?php elseif ($configured): ?>
            <a class="btn-primary" href="/banks/oauth/<?= e((string)$p['id']) ?>/start">
              <?= te('banks.list.connect') ?>
            </a>
          <?php elseif (!empty($canSettings)): ?>
            <a class="btn-secondary" href="/banks/settings#<?= e((string)$p['id']) ?>">
              <?= te('banks.list.configure') ?>
            </a>
          <?php else: ?>
            <span class="btn-secondary banks-connect-disabled" title="<?= te('banks.err.not_configured') ?>">
              <?= te('banks.err.not_configured') ?>
            </span>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<div class="table-wrap"><table class="data">
<thead><tr>
  <th>ID</th>
  <th><?= te('banks.list.col.provider') ?></th>
  <th><?= te('banks.list.col.status') ?></th>
  <th><?= te('banks.list.col.party') ?></th>
  <th><?= te('banks.list.col.updated') ?></th>
  <th></th>
</tr></thead>
<tbody>
<?php foreach ($items as $r): ?>
<tr>
  <td class="muted"><?= (int)$r['id'] ?></td>
  <td><?= e(BanksConfig::providerLabel((string)$r['provider'])) ?></td>
  <td><?= e(t('banks.status.' . (string)$r['status'])) ?></td>
  <td><?= e((string)($r['external_party'] !== '' ? $r['external_party'] : ($r['label'] ?? ''))) ?></td>
  <td class="muted"><?= (int)$r['dt_update'] > 0 ? e(date('d.m.Y H:i', (int)$r['dt_update'])) : '—' ?></td>
  <td><a href="/banks/connections/<?= (int)$r['id'] ?>"><?= te('banks.list.open') ?></a></td>
</tr>
<?php endforeach; ?>
<?php if (!$items): ?>
<tr><td colspan="6" class="muted"><?= te('banks.list.empty') ?></td></tr>
<?php endif; ?>
</tbody></table></div>
