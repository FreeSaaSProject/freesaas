<?php
declare(strict_types=1);
/** @var array{ok:bool,summary:array{ok:int,warn:int,fail:int},checks:list<array<string,mixed>>,checked_at:int} $report */
$sum = $report['summary'] ?? ['ok' => 0, 'warn' => 0, 'fail' => 0];
$ok = !empty($report['ok']);
$backupsTab = 'check';
require __DIR__ . '/_nav.php';
?>
<section class="page-head">
  <p class="lede"><?= te('backups.doctor.lede') ?></p>
</section>

<div class="flash <?= $ok ? 'flash-ok' : 'flash-warn' ?>">
  <?= te($ok ? 'backups.doctor.summary_ok' : 'backups.doctor.summary_issues', [
      'ok' => (string)(int)$sum['ok'],
      'warn' => (string)(int)$sum['warn'],
      'fail' => (string)(int)$sum['fail'],
  ]) ?>
  <span class="muted"> · <?= e(date('Y-m-d H:i:s', (int)($report['checked_at'] ?? time()))) ?></span>
</div>

<form method="post" class="page-actions" style="margin-bottom:1rem"
      data-bk-busy="<?= te('backups.ui.busy.doctor') ?>">
  <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
  <button class="btn-primary" type="submit"><?= te('backups.doctor.run') ?></button>
</form>

<table class="data-table">
  <thead>
    <tr>
      <th><?= te('backups.doctor.col.status') ?></th>
      <th><?= te('backups.doctor.col.check') ?></th>
      <th><?= te('backups.doctor.col.detail') ?></th>
      <th><?= te('backups.doctor.col.hint') ?></th>
    </tr>
  </thead>
  <tbody>
    <?php foreach (($report['checks'] ?? []) as $c): ?>
      <?php
        $st = (string)($c['status'] ?? 'warn');
        $pill = $st === 'ok' ? 'ok' : ($st === 'fail' ? 'warn' : '');
        $labelKey = (string)($c['label_key'] ?? '');
        $label = $labelKey !== '' && function_exists('t') ? t($labelKey) : (string)($c['id'] ?? '');
        if ($label === $labelKey) {
            $label = (string)($c['id'] ?? '');
        }
      ?>
      <tr>
        <td><span class="pill<?= $pill !== '' ? ' ' . e($pill) : '' ?>"><?= te('backups.doctor.status.' . $st) ?></span></td>
        <td>
          <code><?= e((string)($c['id'] ?? '')) ?></code>
          <div><?= e($label) ?></div>
        </td>
        <td><?= e((string)($c['detail'] ?? '')) ?></td>
        <td class="muted"><?= e((string)($c['hint'] ?? '')) ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
