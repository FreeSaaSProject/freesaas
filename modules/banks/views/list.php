<?php
/** @var list<array<string,mixed>> $items */
/** @var list<array{id:string,label:string,configured:bool}> $providers */
/** @var bool $canConnect */
$flash = flash_take();
?>
<?php if ($flash): ?>
  <div class="flash flash-<?= e($flash['type'] === 'ok' ? 'ok' : 'warn') ?>"><?= e((string)$flash['message']) ?></div>
<?php endif; ?>
<section class="page-head">
  <?php if ($canConnect): ?>
  <div class="page-head-row">
    <div></div>
    <div class="page-actions banks-connect-actions">
      <?php foreach ($providers as $p): ?>
        <?php if (!empty($p['configured'])): ?>
          <a class="btn-primary" href="/banks/oauth/<?= e((string)$p['id']) ?>/start"><?= te('banks.list.connect') ?>: <?= e((string)$p['label']) ?></a>
        <?php else: ?>
          <span class="muted" title="<?= te('banks.err.not_configured') ?>"><?= e((string)$p['label']) ?> — <?= te('banks.err.not_configured') ?></span>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
</section>

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
