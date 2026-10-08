<?php
/** @var list<array<string,mixed>> $items */
/** @var list<array{id:string,label:string,configured:bool}> $providers */
/** @var bool $canConnect */
/** @var bool $canSettings */
$flash = flash_take();
$anyConfigured = false;
foreach ($providers as $p) {
    if (!empty($p['configured'])) {
        $anyConfigured = true;
        break;
    }
}
?>
<?php if ($flash): ?>
  <div class="flash flash-<?= e($flash['type'] === 'ok' ? 'ok' : 'warn') ?>"><?= e((string)$flash['message']) ?></div>
<?php endif; ?>

<section class="page-head">
  <div class="page-head-row">
    <div>
      <h1><?= te('banks.list.title') ?></h1>
    </div>
    <div class="page-actions">
      <?php if (!empty($canSettings)): ?>
        <a class="btn-secondary" href="/banks/settings"><?= te('banks.menu.settings') ?></a>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php if (!$anyConfigured): ?>
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
    <?php foreach ($providers as $p): ?>
      <div class="banks-connect-card">
        <div class="banks-connect-card-title"><?= e((string)$p['label']) ?></div>
        <?= BanksConfig::portalLinksHtml((string)$p['id']) ?>
        <div class="banks-connect-actions">
          <?php if (!empty($p['configured'])): ?>
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
