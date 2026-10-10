<?php
/** @var string $message */
/** @var string $error */
/** @var list<array<string,mixed>> $jobs */
/** @var list<array<string,mixed>> $runs */
/** @var string $tick_url */
/** @var bool $agent_token_configured */
/** @var bool $tables_ready */
$jobs = is_array($jobs ?? null) ? $jobs : [];
$runs = is_array($runs ?? null) ? $runs : [];
?>
<section class="page-head">
  <p class="lede"><?= te('core.cron.lede') ?></p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<?php if (empty($tables_ready)): ?>
  <div class="flash flash-warn"><?= te('core.cron.tables_missing') ?></div>
<?php endif; ?>

<section class="card-block">
  <h2><?= te('core.cron.host.title') ?></h2>
  <p class="muted"><?= te('core.cron.host.hint') ?></p>
  <?php if (empty($agent_token_configured)): ?>
    <p class="flash flash-warn"><?= te('core.cron.host.no_token') ?></p>
  <?php endif; ?>
  <pre class="code-block">*/5 * * * * curl -sS -H "X-FreeSaaS-Agent-Token: YOUR_TOKEN" "<?= e($tick_url) ?>" >/dev/null</pre>
  <p class="muted"><?= te('core.cron.host.cli') ?> <code>php bin/freesaas.php cron tick --format=json</code></p>
  <form method="post" class="inline-form">
    <input type="hidden" name="action" value="run_all">
    <button type="submit" class="btn"><?= te('core.cron.btn.run_all') ?></button>
  </form>
</section>

<section class="card-block">
  <h2><?= te('core.cron.jobs.title') ?></h2>
  <?php if ($jobs === []): ?>
    <p class="muted"><?= te('core.cron.jobs.empty') ?></p>
  <?php else: ?>
  <table class="data-table">
    <thead>
      <tr>
        <th><?= te('core.cron.col.job') ?></th>
        <th><?= te('core.cron.col.module') ?></th>
        <th><?= te('core.cron.col.schedule') ?></th>
        <th><?= te('core.cron.col.status') ?></th>
        <th><?= te('core.cron.col.last') ?></th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($jobs as $j): ?>
      <?php
        $jid = (string)($j['job_id'] ?? '');
        $labelKey = (string)($j['label'] ?? $jid);
        $label = te($labelKey);
        if ($label === $labelKey) {
            $label = $jid;
        }
        $sched = '';
        if (!empty($j['daily_at'])) {
            $sched = t('core.cron.sched.daily', ['at' => (string)$j['daily_at']]);
        } elseif (!empty($j['every'])) {
            $sched = t('core.cron.sched.every', ['n' => (string)(int)$j['every']]);
        }
        $finished = (int)($j['last_finished_at'] ?? 0);
        $last = $finished > 0
            ? date('Y-m-d H:i', $finished) . ' (' . (int)($j['last_ms'] ?? 0) . ' ms)'
            : '—';
        $st = (string)($j['last_status'] ?? '');
        $en = (int)($j['enabled'] ?? 1) === 1;
        $modOn = !empty($j['module_enabled']);
      ?>
      <tr>
        <td>
          <strong><?= e($label) ?></strong>
          <div class="muted small"><?= e($jid) ?></div>
          <?php if ((string)($j['last_message'] ?? '') !== ''): ?>
            <div class="muted small"><?= e((string)$j['last_message']) ?></div>
          <?php endif; ?>
        </td>
        <td><?= e((string)($j['module'] ?? '')) ?><?php if (!$modOn): ?> <span class="muted">(<?= te('core.cron.module_off') ?>)</span><?php endif; ?></td>
        <td><?= e($sched) ?></td>
        <td><?= e($st !== '' ? $st : '—') ?><?php if (!$en): ?> · <?= te('core.common.off') ?><?php endif; ?></td>
        <td><?= e($last) ?></td>
        <td class="actions">
          <form method="post" class="inline-form">
            <input type="hidden" name="action" value="run_one">
            <input type="hidden" name="job_id" value="<?= e($jid) ?>">
            <button type="submit" class="btn-secondary"><?= te('core.cron.btn.run') ?></button>
          </form>
          <form method="post" class="inline-form">
            <input type="hidden" name="action" value="<?= $en ? 'disable' : 'enable' ?>">
            <input type="hidden" name="job_id" value="<?= e($jid) ?>">
            <button type="submit" class="btn-secondary"><?= $en ? te('core.cron.btn.disable') : te('core.cron.btn.enable') ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>

<section class="card-block">
  <h2><?= te('core.cron.runs.title') ?></h2>
  <?php if ($runs === []): ?>
    <p class="muted"><?= te('core.cron.runs.empty') ?></p>
  <?php else: ?>
  <table class="data-table">
    <thead>
      <tr>
        <th><?= te('core.cron.col.when') ?></th>
        <th><?= te('core.cron.col.job') ?></th>
        <th><?= te('core.cron.col.status') ?></th>
        <th><?= te('core.cron.col.ms') ?></th>
        <th><?= te('core.cron.col.message') ?></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($runs as $r): ?>
      <tr>
        <td><?= e((int)($r['started_at'] ?? 0) > 0 ? date('Y-m-d H:i:s', (int)$r['started_at']) : '—') ?></td>
        <td><?= e((string)($r['job_id'] ?? '')) ?></td>
        <td><?= e((string)($r['status'] ?? '')) ?></td>
        <td><?= (int)($r['ms'] ?? 0) ?></td>
        <td class="muted small"><?= e((string)($r['message'] ?? '')) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>
