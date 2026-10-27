<?php declare(strict_types=1);
/** @var array<string, mixed> $report */
$issues = $report['issues'] ?? [];
?>
<section class="video-status">
  <p>
    <strong><?= te('video.status.workers_online') ?>:</strong> <?= (int)($report['workers_online'] ?? 0) ?>
    · <strong><?= te('video.status.token') ?>:</strong>
    <?= !empty($report['worker_token_configured']) ? e((string)($report['worker_token_masked'] ?? '')) : te('video.status.token_missing') ?>
    · <strong><?= te('video.dashboard.storage') ?>:</strong>
    <?= e(number_format((int)($report['storage_bytes'] ?? 0) / 1048576, 1)) ?> MB
  </p>
  <?php if ($issues !== []): ?>
    <h2><?= te('video.status.issues') ?></h2>
    <ul>
      <?php foreach ($issues as $i): ?>
        <li class="<?= e((string)($i['severity'] ?? '')) ?>"><?= e((string)($i['message'] ?? '')) ?></li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <p class="ok"><?= te('video.status.ok') ?></p>
  <?php endif; ?>
  <?php if (!empty($report['agent_next'])): ?>
    <h2><?= te('video.status.next') ?></h2>
    <ol>
      <?php foreach ($report['agent_next'] as $step): ?>
        <li><code><?= e((string)$step) ?></code></li>
      <?php endforeach; ?>
    </ol>
  <?php endif; ?>
  <p class="actions"><a class="btn-secondary btn-sm" href="/video"><?= te('video.menu.dashboard') ?></a></p>
</section>
