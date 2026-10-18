<?php
declare(strict_types=1);
/** @var array{day:string,day_start:int,day_end:int,total:float,lines:list<array<string,mixed>>} $detail */

$fmt = static function (float $n): string {
    return number_format($n, 0, '.', ' ');
};
$fmtRate = static function (float $n): string {
    return rtrim(rtrim(number_format($n * 100, 2, '.', ''), '0'), '.') . '%';
};
$fmtAbsRate = static function (float $n): string {
    return rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
};
$fmtQty = static function (float $n): string {
    return rtrim(rtrim(number_format($n, 2, '.', ' '), '0'), '.');
};

$roleLabels = [
    'operator' => t('procents.audit.role.operator'),
    'terminal' => t('procents.audit.role.terminal'),
    'shift' => t('procents.audit.role.shift'),
    'sales' => t('procents.audit.role.sales'),
    'audio' => t('procents.audit.role.audio'),
    'scripts' => t('procents.audit.role.scripts'),
];

$lines = is_array($detail['lines'] ?? null) ? $detail['lines'] : [];
?>
<?php if ($lines === []): ?>
  <p class="muted procents-day-empty"><?= te('procents.audit.empty_day') ?></p>
<?php else: ?>
  <div class="table-wrap">
    <table class="data procents-day-lines">
      <thead>
        <tr>
          <th><?= te('procents.audit.col.time') ?></th>
          <th><?= te('procents.audit.col.role') ?></th>
          <th><?= te('procents.col.type') ?></th>
          <th><?= te('procents.col.descr') ?></th>
          <th><?= te('procents.col.sum') ?></th>
          <th><?= te('procents.col.rate') ?></th>
          <th><?= te('procents.col.accrued') ?></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($lines as $r): ?>
        <?php
          $abs = (($r['rate_kind'] ?? '') === 'absolute');
          $role = (string)($r['role'] ?? '');
          $roleLabel = $roleLabels[$role] ?? $role;
        ?>
        <tr>
          <td><?= e((string)($r['time'] ?? '')) ?></td>
          <td><?= e($roleLabel) ?></td>
          <td><?= e((string)($r['type'] ?? '')) ?></td>
          <td><?= e((string)($r['descr'] ?? '')) ?></td>
          <td><?= e($abs ? $fmtQty((float)$r['sum']) : $fmt((float)$r['sum'])) ?></td>
          <td><?= e($abs ? $fmtAbsRate((float)$r['rate']) : $fmtRate((float)$r['rate'])) ?></td>
          <td><?= e($fmt((float)$r['accrued'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="procents-day-total"><strong><?= te('procents.audit.day_total') ?>:</strong> <?= e($fmt((float)$detail['total'])) ?></p>
<?php endif; ?>
