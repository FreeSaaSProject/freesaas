<?php
/** @var array<string,mixed> $payload */
$maps = is_array($payload['map_counts'] ?? null) ? $payload['map_counts'] : [];
$jobs = is_array($payload['jobs'] ?? null) ? $payload['jobs'] : [];
?>
<section class="page-head">
  <h1><?= te('yclients.status.title') ?></h1>
  <p class="muted"><?= te('yclients.status.lede') ?></p>
</section>

<dl class="kv-list">
  <dt><?= te('yclients.status.pull') ?></dt>
  <dd><?= !empty($payload['pull_enabled']) ? te('yclients.status.on') : te('yclients.status.off') ?></dd>
  <dt><?= te('yclients.status.api') ?></dt>
  <dd><?= !empty($payload['api_ready']) ? te('yclients.status.ready') : te('yclients.status.missing') ?>
    <?php if ((int)($payload['company_id'] ?? 0) > 0): ?>
      <span class="muted">#<?= (int)$payload['company_id'] ?></span>
    <?php endif; ?>
  </dd>
  <dt><?= te('yclients.status.worker') ?></dt>
  <dd><?= !empty($payload['worker_token_configured']) ? te('yclients.status.ready') : te('yclients.status.missing') ?></dd>
</dl>

<section>
  <h2><?= te('yclients.status.maps') ?></h2>
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
  <h2><?= te('yclients.status.jobs') ?></h2>
  <?php if ($jobs === []): ?>
    <p class="muted"><?= te('yclients.status.none') ?></p>
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
          <td><?= !empty($job['last_ok']) ? te('yclients.status.job_ok') : te('yclients.status.job_fail') ?></td>
          <td class="muted"><?= e((string)($job['last_error'] ?? '')) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>

<div class="page-actions">
  <a class="btn-primary" href="/yclients/import"><?= te('yclients.menu.import') ?></a>
  <a class="btn-secondary" href="/yclients/settings"><?= te('yclients.menu.settings') ?></a>
</div>
