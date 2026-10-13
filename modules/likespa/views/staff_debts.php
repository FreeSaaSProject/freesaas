<?php
declare(strict_types=1);
/** @var string $message */
/** @var string $error */
/** @var array<string,mixed>|null $result */
/** @var int $newsId */
/** @var int $commentUserId */
/** @var string $dailyAt */
/** @var int $braceletCount */
/** @var bool $onecLive */
?>
<section class="page-head">
  <h1><?= te('likespa.staff_debts.title') ?></h1>
  <p class="lede"><?= te('likespa.staff_debts.lede') ?></p>
  <p class="muted"><?= te('likespa.staff_debts.meta', [
      'news' => (string)$newsId,
      'user' => (string)$commentUserId,
      'at' => $dailyAt,
      'n' => (string)$braceletCount,
  ]) ?></p>
  <?php if (!$onecLive): ?>
    <p class="flash flash-warn"><?= te('likespa.staff_debts.warn.onec') ?></p>
  <?php endif; ?>
  <?php if ($newsId < 1 || $commentUserId < 1): ?>
    <p class="flash flash-warn"><?= te('likespa.staff_debts.warn.config') ?>
      <a href="/likespa/settings"><?= te('likespa.menu.settings') ?></a>
    </p>
  <?php endif; ?>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="page-actions">
  <button type="submit" class="btn-primary"<?= ($newsId < 1 || $commentUserId < 1 || !$onecLive) ? ' disabled' : '' ?>>
    <?= te('likespa.staff_debts.run') ?>
  </button>
</form>

<?php if (is_array($result) && !empty($result['lines']) && is_array($result['lines'])): ?>
  <section class="likespa-staff-debts-log">
    <h2><?= te('likespa.staff_debts.log') ?></h2>
    <ul>
      <?php foreach ($result['lines'] as $line): ?>
        <li><?= e((string)$line) ?></li>
      <?php endforeach; ?>
    </ul>
  </section>
<?php endif; ?>
