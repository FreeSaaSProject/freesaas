<?php
declare(strict_types=1);
/** @var array $metrics */
/** @var string $createHref */
/** @var bool $showProcLine */
$m = is_array($metrics ?? null) ? $metrics : [];
$term = is_array($m['term'] ?? null) ? $m['term'] : [];
$mode = (string)($m['conversion_mode'] ?? 'script_finish');
$countLabel = $mode === 'scans'
    ? t('likespa.promo.scans', ['n' => (string)(int)($m['scan_unique_clients'] ?? 0)])
    : t('likespa.promo.finishes', ['n' => (string)(int)($m['finish_count'] ?? 0)]);
?>
<span class="likespa-promo">
  <span class="likespa-promo-stats">
    <?= e(t('likespa.promo.hours', ['h' => (string)($m['work_hours'] ?? 0)])) ?>
    · <?= e(t('likespa.promo.scripts', ['n' => (string)(int)($m['script_count'] ?? 0)])) ?>
    · <?= e($countLabel) ?>
    · <?= e(t('likespa.promo.conversion', ['pct' => (string)($m['conversion'] ?? 0)])) ?>
  </span>
  <?php if (!empty($showProcLine) && (int)($term['unique_clients'] ?? 0) > 0): ?>
    <span class="likespa-promo-proc">
      <?= e(t('likespa.promo.proc_line', [
          'n' => (string)(int)($term['unique_clients_with_procedures'] ?? 0),
          'pct' => (string)($term['procedure_conversion'] ?? 0),
      ])) ?>
    </span>
  <?php endif; ?>
  <?php if (!empty($m['has_bonus'])): ?>
    <span class="likespa-promo-bonus likespa-promo-bonus--done"><?= te('likespa.promo.bonus_done') ?></span>
  <?php elseif (!empty($m['eligible']) && $createHref !== ''): ?>
    <a class="likespa-promo-bonus" href="<?= e($createHref) ?>" title="<?= te('likespa.promo.create') ?>"><?= te('likespa.promo.bonus') ?></a>
  <?php elseif (!empty($m['eligible'])): ?>
    <span class="likespa-promo-bonus"><?= te('likespa.promo.bonus') ?></span>
  <?php endif; ?>
</span>
