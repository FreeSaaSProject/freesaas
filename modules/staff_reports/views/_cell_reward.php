<?php
declare(strict_types=1);
/** @var array{reward?:bool,ticket?:int,no_records?:bool,comment_id?:int} $meta */
$meta = is_array($meta ?? null) ? $meta : [];
$ticket = (int)($meta['ticket'] ?? 0);
$reward = !empty($meta['reward']);
$noRecords = !empty($meta['no_records']);
$href = $ticket > 0 ? '/news/' . $ticket : '';
$label = $noRecords
    ? t('staff_reports.reward.no_records')
    : ($reward ? t('staff_reports.reward.ok') : t('staff_reports.reward.no'));
$icon = $noRecords ? '🎙' : ($reward ? '✅' : '❌');
?>
<span class="sr-cal-reward<?= $reward ? ' is-ok' : ' is-no' ?>" title="<?= e($label) ?>">
<?php if ($href !== ''): ?>
  <a href="<?= e(NavContext::withReturn($href)) ?>" class="sr-cal-reward-link"><?= e($icon) ?></a>
<?php else: ?>
  <?= e($icon) ?>
<?php endif; ?>
</span>
