<?php
declare(strict_types=1);
/**
 * Banner: likely owners of unassigned records for one badge (bounded day/month).
 *
 * @var array{
 *   badge:string,
 *   from:int,
 *   to:int,
 *   orphan_count:int,
 *   candidates:list<array{
 *     admin_id:int,
 *     weight:int,
 *     assignment_sec:int,
 *     assigned_count:int,
 *     assigned_playtime:int,
 *     windows:list<array{from:int,to:int}>
 *   }>
 * } $orphanHints
 * @var bool $canEdit
 * @var array<string,string> $listFilters
 */
$hints = is_array($orphanHints ?? null) ? $orphanHints : null;
if ($hints === null || (int)($hints['orphan_count'] ?? 0) < 1) {
    return;
}
$canEdit = !empty($canEdit);
$filters = is_array($listFilters ?? null) ? $listFilters : [];
$badge = (string)($hints['badge'] ?? '');
$from = (int)($hints['from'] ?? 0);
$to = (int)($hints['to'] ?? 0);
$n = (int)$hints['orphan_count'];
$candidates = is_array($hints['candidates'] ?? null) ? $hints['candidates'] : [];
?>
<div class="audio-orphan-hints" role="region" aria-label="<?= te('audio.orphan_hints.aria') ?>">
  <p class="audio-orphan-hints-title">
    <?= te('audio.orphan_hints.title', ['badge' => $badge, 'n' => $n]) ?>
  </p>
  <?php if ($candidates === []): ?>
    <p class="muted"><?= te('audio.orphan_hints.empty') ?></p>
  <?php else: ?>
    <ul class="audio-orphan-hints-list">
      <?php foreach ($candidates as $c):
          $aid = (int)$c['admin_id'];
          $bits = [];
          if ((int)$c['assignment_sec'] > 0) {
              $bits[] = t('audio.orphan_hints.reason_assign', [
                  'hours' => round((int)$c['assignment_sec'] / 3600, 1),
              ]);
          }
          if ((int)$c['assigned_count'] > 0) {
              $play = max(0, (int)$c['assigned_playtime']);
              $bits[] = t('audio.orphan_hints.reason_records', [
                  'n' => (int)$c['assigned_count'],
                  'time' => sprintf('%d:%02d', intdiv($play, 3600), intdiv($play % 3600, 60)),
              ]);
          }
          ?>
        <li class="audio-orphan-hints-item">
          <span class="audio-orphan-hints-who"><?= UsersRepository::badgeHtml($aid, false, true) ?></span>
          <?php if ($bits !== []): ?>
            <span class="muted audio-orphan-hints-why"><?= e(implode(' · ', $bits)) ?></span>
          <?php endif; ?>
          <?php if ($canEdit): ?>
            <form method="post" action="/audio" class="audio-orphan-hints-claim inline">
              <?php foreach ($filters as $fk => $fv): ?>
                <?php if ($fv === '' || $fv === null) {
                    continue;
                } ?>
                <input type="hidden" name="<?= e((string)$fk) ?>" value="<?= e((string)$fv) ?>">
              <?php endforeach; ?>
              <input type="hidden" name="action" value="claim_badge_orphans">
              <input type="hidden" name="claim_admin" value="<?= $aid ?>">
              <input type="hidden" name="claim_badge" value="<?= e($badge) ?>">
              <input type="hidden" name="claim_from" value="<?= $from ?>">
              <input type="hidden" name="claim_to" value="<?= $to ?>">
              <button type="submit" class="btn-secondary btn-sm"
                      onclick="return confirm(<?= tj('audio.orphan_hints.claim_confirm', ['n' => $n]) ?>);">
                <?= te('audio.orphan_hints.claim') ?>
              </button>
            </form>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>
