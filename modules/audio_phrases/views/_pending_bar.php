<?php
declare(strict_types=1);
/**
 * Pending phrases strip (top/bottom). Later → nav badge.
 *
 * Important: do not assign to $items / $total — those belong to the parent list view.
 *
 * @var array{total?:int,items?:list<array{id:int,lemmas:string,example?:string}>}|null $pending
 * @var string $pendingHref Base list URL for “all” link (optional)
 */
$pendingData = is_array($pending ?? null) ? $pending : ['total' => 0, 'items' => []];
$pendingTotal = (int)($pendingData['total'] ?? 0);
$pendingItems = is_array($pendingData['items'] ?? null) ? $pendingData['items'] : [];
if ($pendingTotal < 1 && $pendingItems === []) {
    return;
}
$pendingHref = (string)($pendingHref ?? '/audio/phrases?status=new');
?>
<div class="ap-pending-bar" role="region" aria-label="<?= te('audio_phrases.pending.aria') ?>">
  <div class="ap-pending-head">
    <strong><?= te('audio_phrases.pending.title', ['n' => (string)$pendingTotal]) ?></strong>
    <?php if ($pendingTotal > count($pendingItems)): ?>
      <a class="ap-pending-all" href="<?= e($pendingHref) ?>"><?= te('audio_phrases.pending.all') ?></a>
    <?php endif; ?>
  </div>
  <?php if ($pendingItems !== []): ?>
  <ul class="ap-pending-chips">
    <?php foreach ($pendingItems as $p):
        $pid = (int)($p['id'] ?? 0);
        $label = trim((string)($p['lemmas'] ?? ''));
        if ($label === '') {
            $label = trim((string)($p['example'] ?? ''));
        }
        if ($label === '' || $pid < 1) {
            continue;
        }
        $short = mb_strlen($label, 'UTF-8') > 48 ? (mb_substr($label, 0, 46, 'UTF-8') . '…') : $label;
        ?>
      <li>
        <a class="ap-pending-chip" href="#phrase-<?= $pid ?>" title="<?= e($label) ?>">#<?= $pid ?> <?= e($short) ?></a>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</div>
