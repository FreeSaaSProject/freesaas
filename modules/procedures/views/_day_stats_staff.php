<?php
declare(strict_types=1);
/**
 * @var list<array<string,mixed>> $lines mass/admin lines
 * @var bool $myOnly
 */
$lines = $lines ?? [];
$myOnly = !empty($myOnly);
if (!$lines) {
    return;
}
?>
<div class="cal-stat-staff">
  <?php
  $seenAdmin = false;
  foreach ($lines as $line):
      $isAdmin = !empty($line['is_admin']);
      $sep = $isAdmin && !$seenAdmin;
      if ($isAdmin) {
          $seenAdmin = true;
      }
  ?>
    <div class="cal-stat-staff-line<?= $isAdmin ? ' is-admin' : '' ?><?= $sep ? ' is-admin-first' : '' ?>">
      <span class="cal-stat-op" style="<?= !empty($line['bg']) ? ('background:' . e((string)$line['bg']) . ';color:' . e((string)($line['fg'] ?? '#000')) . ';') : '' ?>"><?= e((string)($line['acronym'] ?? '')) ?></span>
      <span class="cal-stat-text"><?= e((string)($line['text'] ?? '')) ?></span>
    </div>
  <?php endforeach; ?>
</div>
