<?php
declare(strict_types=1);
/** @var array<string,mixed> $payload */
$maps = is_array($payload['map_counts'] ?? null) ? $payload['map_counts'] : [];
$jobs = is_array($payload['jobs'] ?? null) ? $payload['jobs'] : [];
?>
<section class="page-head">
  <h1><?= te('clientics.status.title') ?></h1>
  <p class="muted"><?= te('clientics.status.lede') ?></p>
</section>

<dl class="kv-list">
  <dt><?= te('clientics.status.pull') ?></dt>
  <dd><?= !empty($payload['pull_enabled']) ? te('clientics.status.on') : te('clientics.status.off') ?></dd>
  <dt><?= te('clientics.status.api') ?></dt>
  <dd><?= !empty($payload['api_ready']) ? te('clientics.status.ready') : te('clientics.status.missing') ?>
    <?php if ((string)($payload['account_id'] ?? '') !== ''): ?>
      <span class="muted">#<?= e((string)$payload['account_id']) ?></span>
    <?php endif; ?>
  </dd>
  <dt><?= te('clientics.status.worker') ?></dt>
  <dd><?= !empty($payload['worker_token_configured']) ? te('clientics.status.ready') : te('clientics.status.missing') ?></dd>
  <dt><?= te('clientics.status.webhook') ?></dt>
  <dd><?= !empty($payload['webhook_token_configured']) ? te('clientics.status.ready') : te('clientics.status.missing') ?></dd>
</dl>

<section>
  <h2><?= te('clientics.status.maps') ?></h2>
  <?php if ($maps === []): ?>
    <p class="muted">—</p>
  <?php else: ?>
    <ul>
      <?php foreach ($maps as $entity => $count): ?>
        <li><code><?= e((string)$entity) ?></code>: <?= (int)$count ?></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<section>
  <h2><?= te('clientics.status.jobs') ?></h2>
  <?php if ($jobs === []): ?>
    <p class="muted"><?= te('clientics.status.none') ?></p>
  <?php else: ?>
    <table class="table">
      <thead>
        <tr>
          <th>job</th>
          <th>when</th>
          <th>status</th>
          <th>error</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($jobs as $job): ?>
        <tr>
          <td><code><?= e((string)($job['job'] ?? '')) ?></code></td>
          <td><?= (int)($job['last_run'] ?? 0) > 0 ? e(date('Y-m-d H:i', (int)$job['last_run'])) : '—' ?></td>
          <td><?= !empty($job['last_ok']) ? te('clientics.status.job_ok') : te('clientics.status.job_fail') ?></td>
          <td class="muted"><?= e((string)($job['last_error'] ?? '')) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>

<div class="page-actions">
  <a class="btn-primary" href="/clientics/import"><?= te('clientics.menu.import') ?></a>
  <a class="btn-secondary" href="/clientics/settings"><?= te('clientics.menu.settings') ?></a>
</div>
