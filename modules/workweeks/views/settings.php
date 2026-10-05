<?php
/**
 * @var string $message
 * @var string $error
 * @var bool $loggingOn
 * @var int $newsId
 * @var string $newsLabel
 */
?>
<section class="page-head">
  <p class="lede"><?= te('workweeks.settings.lede') ?></p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<?php if (empty($loggingOn)): ?>
  <div class="flash flash-warn"><?= te('workweeks.settings.need_comments') ?></div>
<?php else: ?>
<form method="post" class="form-grid">
  <label><?= te('workweeks.settings.news_id') ?>
    <input type="number" name="news_schedule_id" min="0" value="<?= (int)$newsId ?>"
           placeholder="0">
  </label>
  <p class="muted"><?= te('workweeks.settings.news_hint') ?></p>
  <?php if ($newsId > 0): ?>
    <p class="muted">
      <?= te('workweeks.settings.current') ?>:
      <?php if ($newsLabel !== ''): ?>
        <a href="/news/<?= (int)$newsId ?>"><?= e($newsLabel) ?></a>
        <span class="muted">#<?= (int)$newsId ?></span>
      <?php else: ?>
        #<?= (int)$newsId ?>
        <span class="flash flash-warn" style="display:inline"><?= te('workweeks.settings.err.news') ?></span>
      <?php endif; ?>
    </p>
  <?php endif; ?>
  <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
</form>
<?php endif; ?>
