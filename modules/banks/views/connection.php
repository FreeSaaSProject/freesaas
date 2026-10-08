<?php
/** @var array<string,mixed> $conn */
/** @var list<array<string,mixed>> $links */
/** @var array<int,string> $ledgerAccounts */
/** @var array<string,mixed>|null $lastSync */
/** @var bool $canConnect */
/** @var bool $canSync */
$id = (int)$conn['id'];
$flash = flash_take();
$lastSync = $lastSync ?? null;
?>
<?php if ($flash): ?>
  <div class="flash flash-<?= e($flash['type'] === 'ok' ? 'ok' : 'warn') ?>"><?= e_nl((string)$flash['message']) ?></div>
<?php endif; ?>
<section class="page-head">
  <div class="page-head-row">
    <div>
      <p class="muted"><?= e(t('banks.status.' . (string)$conn['status'])) ?>
        <?php if (!empty($conn['token_mask'])): ?> · <?= e((string)$conn['token_mask']) ?><?php endif; ?>
      </p>
    </div>
    <div class="page-actions">
      <?php if ($canConnect): ?>
      <form method="post" action="/banks/connections/<?= $id ?>" style="display:inline">
        <input type="hidden" name="action" value="refresh">
        <button type="submit" class="btn-secondary"><?= te('banks.conn.refresh') ?></button>
      </form>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php if ($canSync): ?>
<form class="banks-sync-form" method="post" action="/banks/connections/<?= $id ?>/sync">
  <label><?= te('banks.conn.sync_from') ?>
    <input type="date" name="date_from" value="<?= e(date('Y-m-d', strtotime('-7 days'))) ?>">
  </label>
  <label><?= te('banks.conn.sync_to') ?>
    <input type="date" name="date_to" value="<?= e(date('Y-m-d')) ?>">
  </label>
  <button type="submit" class="btn-primary"><?= te('banks.conn.sync') ?></button>
</form>
<?php endif; ?>

<?php
$syncMessages = is_array($lastSync) && !empty($lastSync['messages']) && is_array($lastSync['messages'])
    ? $lastSync['messages']
    : [];
$showLastSync = is_array($lastSync) && (
    $syncMessages !== []
    || (int)($lastSync['errors'] ?? 0) > 0
    || (int)($lastSync['linked'] ?? 0) > 0
    || (int)($lastSync['created'] ?? 0) > 0
    || (int)($lastSync['at'] ?? 0) > 0
);
if ($showLastSync):
?>
<details class="banks-last-sync<?= (int)($lastSync['errors'] ?? 0) > 0 ? '' : ' is-ok' ?>" open>
  <summary>
    <?= te('banks.conn.last_sync') ?>
    <?php if (is_array($lastSync) && (int)($lastSync['at'] ?? 0) > 0): ?>
      · <?= e(date('d.m.Y H:i', (int)$lastSync['at'])) ?>
    <?php endif; ?>
    — <?= e(t('banks.conn.synced', [
        'created' => (int)($lastSync['created'] ?? 0),
        'linked' => (int)($lastSync['linked'] ?? 0),
        'skipped' => (int)($lastSync['skipped'] ?? 0),
        'errors' => (int)($lastSync['errors'] ?? 0),
    ])) ?>
  </summary>
  <?php if ($syncMessages !== []): ?>
  <ul class="banks-sync-messages">
    <?php foreach ($syncMessages as $m): ?>
      <li><?= e((string)$m) ?></li>
    <?php endforeach; ?>
  </ul>
  <?php else: ?>
    <p class="muted"><?= te('banks.conn.last_sync_no_detail') ?></p>
  <?php endif; ?>
</details>
<?php endif; ?>

<h2><?= te('banks.conn.remote') ?></h2>
<div class="table-wrap"><table class="data banks-remote-table">
<thead><tr>
  <th><?= te('banks.conn.account_number') ?></th>
  <th><?= te('banks.conn.currency') ?></th>
  <th><?= te('banks.conn.ledger_account') ?></th>
  <th></th>
</tr></thead>
<tbody>
<?php foreach ($links as $link): ?>
<?php
  $lid = (int)$link['id'];
  $accId = (int)$link['account_id'];
  $ext = (string)$link['external_account_id'];
?>
<tr>
  <td>
    <?= e((string)$link['account_number'] !== '' ? (string)$link['account_number'] : $ext) ?>
    <?php if ((string)$link['remote_name'] !== ''): ?>
      <div class="muted"><?= e((string)$link['remote_name']) ?></div>
    <?php endif; ?>
  </td>
  <td><?= e((string)$link['currency']) ?></td>
  <td>
    <?php if ($accId > 0): ?>
      <a href="/accounts/<?= $accId ?>/edit"><?= e($ledgerAccounts[$accId] ?? ('#' . $accId)) ?></a>
      <span class="bank-chip"><?= e(BanksConfig::providerLabel((string)$conn['provider'])) ?></span>
    <?php else: ?>
      <span class="muted">—</span>
    <?php endif; ?>
  </td>
  <td>
    <?php if ($canConnect): ?>
      <?php if ($accId > 0): ?>
        <form method="post" action="/banks/connections/<?= $id ?>" class="bank-link-form">
          <input type="hidden" name="action" value="unlink">
          <input type="hidden" name="link_id" value="<?= $lid ?>">
          <button type="submit" class="btn-secondary"><?= te('banks.conn.unlink') ?></button>
        </form>
      <?php else: ?>
        <form method="post" action="/banks/connections/<?= $id ?>" class="bank-link-form">
          <input type="hidden" name="action" value="link">
          <input type="hidden" name="external_account_id" value="<?= e($ext) ?>">
          <select name="account_id" required>
            <option value=""><?= te('banks.conn.choose_account') ?></option>
            <?php foreach ($ledgerAccounts as $aid => $aname): ?>
              <option value="<?= (int)$aid ?>"><?= e($aname) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn-primary"><?= te('banks.conn.link') ?></button>
        </form>
        <form method="post" action="/banks/connections/<?= $id ?>" class="bank-link-form">
          <input type="hidden" name="action" value="create_ledger">
          <input type="hidden" name="external_account_id" value="<?= e($ext) ?>">
          <input type="text" name="account_name" maxlength="20" placeholder="<?= te('ledger.accounts.col.name') ?>">
          <button type="submit" class="btn-secondary"><?= te('banks.conn.create_ledger') ?></button>
        </form>
      <?php endif; ?>
    <?php endif; ?>
  </td>
</tr>
<?php endforeach; ?>
<?php if (!$links): ?>
<tr><td colspan="4" class="muted"><?= te('banks.conn.no_remote') ?></td></tr>
<?php endif; ?>
</tbody></table></div>
