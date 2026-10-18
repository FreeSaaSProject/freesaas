<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $days */
/** @var int $operatorId */

$fmt = static function (float $n): string {
    return number_format($n, 0, '.', ' ');
};
$days = is_array($days ?? null) ? $days : [];
?>
<?php if ($days === []): ?>
  <p class="muted"><?= te('procents.audit.empty_days') ?></p>
<?php else: ?>
  <div class="procents-day-tree" data-error="<?= e(t('procents.audit.error')) ?>">
    <?php foreach ($days as $day): ?>
      <?php
        $dayKey = (string)($day['day'] ?? '');
        $dayUrl = '/procents/day?' . http_build_query([
            'operator_id' => (int)$operatorId,
            'day' => $dayKey,
        ]);
      ?>
      <details class="procents-day" data-day-url="<?= e($dayUrl) ?>">
        <summary>
          <span class="procents-day-label"><?= e((string)($day['label'] ?? $dayKey)) ?></span>
          <span class="procents-day-sum"><?= e($fmt((float)($day['total'] ?? 0))) ?></span>
        </summary>
        <div class="procents-day-body">
          <p class="muted procents-day-loading"><?= te('procents.audit.loading') ?></p>
        </div>
      </details>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
