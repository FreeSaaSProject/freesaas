<?php
declare(strict_types=1);
/** @var string $message */
/** @var string $error */
/** @var bool $deadlinePenalty */
$deadlinePenalty = !empty($deadlinePenalty);
?>
<section class="page-head">
  <p class="lede"><?= te('contracts.settings.lede') ?></p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid">
  <fieldset class="form-fieldset">
    <legend><?= te('contracts.settings.deadline_penalty') ?></legend>
    <p class="muted"><?= te('contracts.settings.deadline_penalty_hint') ?></p>
    <label class="logo-remove">
      <input type="hidden" name="deadline_penalty" value="0">
      <input type="checkbox" name="deadline_penalty" value="1"<?= $deadlinePenalty ? ' checked' : '' ?>>
      <?= te('contracts.settings.deadline_penalty_label') ?>
    </label>
  </fieldset>

  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
  </div>
</form>
