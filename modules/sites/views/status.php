<?php
declare(strict_types=1);
/** @var array<string,mixed> $report */
$status = (string)($report['status'] ?? 'unknown');
$issues = is_array($report['issues'] ?? null) ? $report['issues'] : [];
$hosts = is_array($report['hosts'] ?? null) ? $report['hosts'] : [];
$modules = is_array($report['modules'] ?? null) ? $report['modules'] : [];
$booking = is_array($report['booking'] ?? null) ? $report['booking'] : [];
$ft = is_array($report['free_ticket'] ?? null) ? $report['free_ticket'] : null;
$showFt = $ft !== null || (!empty($hosts) && array_key_exists('free_ticket', $hosts[0]));
?>
<section class="page-head">
  <h1><?= te('sites.status.title') ?></h1>
  <p class="muted"><?= te('sites.status.intro') ?></p>
</section>

<div class="flash flash-<?= $status === 'ok' ? 'ok' : ($status === 'fail' ? 'warn' : 'ok') ?>">
  <strong><?= te('sites.status.status') ?>:</strong> <?= e($status) ?>
  · <code>?format=json</code>
</div>

<?php if ($issues !== []): ?>
<section>
  <h2><?= te('sites.status.issues') ?></h2>
  <ul>
    <?php foreach ($issues as $iss): ?>
      <li>
        <code><?= e((string)($iss['level'] ?? '')) ?></code>
        <?= e((string)($iss['message'] ?? '')) ?>
        <?php if (!empty($iss['module'])): ?>
          <span class="muted">(<?= e((string)$iss['module']) ?>)</span>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<section>
  <h2><?= te('sites.status.modules') ?></h2>
  <table class="data-table">
    <thead><tr><th>id</th><th>config</th><th>booted</th></tr></thead>
    <tbody>
    <?php foreach ($modules as $mid => $m): ?>
      <tr>
        <td><code><?= e((string)$mid) ?></code></td>
        <td><?= !empty($m['config_enabled']) ? te('core.common.on') : te('core.common.off') ?></td>
        <td><?= !empty($m['booted']) ? te('core.common.on') : te('core.common.off') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>

<section>
  <h2><?= te('sites.status.booking') ?></h2>
  <p>
    active=<?= !empty($booking['active']) ? '1' : '0' ?>
    · bookable=<?= e((string)($booking['bookable_services'] ?? '0')) ?>
  </p>
  <?php if (is_array($ft)): ?>
  <p class="muted">
    FREE_TICKET (<?= e((string)($ft['module'] ?? 'overlay')) ?>):
    threshold=<?= e((string)($ft['threshold'] ?? '0')) ?>
    · hosts_flag=<?= e((string)($ft['hosts_with_flag'] ?? '0')) ?>
  </p>
  <?php endif; ?>
</section>

<section>
  <h2><?= te('sites.status.hosts') ?> (<?= (int)($report['hosts_total'] ?? 0) ?>)</h2>
  <table class="data-table">
    <thead>
      <tr>
        <th>host</th><th>theme</th><th>catalog</th>
        <?php if ($showFt): ?><th>FT</th><?php endif; ?>
        <th>on</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($hosts as $h): ?>
      <tr>
        <td>
          <a href="/sites/hosts/<?= (int)$h['id'] ?>/edit"><?= e((string)$h['host']) ?></a>
          <?= !empty($h['is_default']) ? ' ★' : '' ?>
        </td>
        <td>
          <code><?= e((string)$h['theme']) ?></code>
          <?= empty($h['theme_ok']) ? ' ✗' : '' ?>
        </td>
        <td class="muted"><?php
          $roots = is_array($h['catalog_root_ids'] ?? null) ? $h['catalog_root_ids'] : [];
          echo $roots === [] ? te('sites.hosts.catalog_all') : e('#' . implode(', #', $roots));
        ?></td>
        <?php if ($showFt): ?>
        <td><?= !empty($h['free_ticket']) ? '1' : '0' ?></td>
        <?php endif; ?>
        <td><?= !empty($h['enabled']) ? te('core.common.on') : te('core.common.off') ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($hosts === []): ?>
      <tr><td colspan="<?= $showFt ? 5 : 4 ?>" class="muted"><?= te('sites.hosts.empty') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</section>
