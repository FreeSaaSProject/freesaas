<?php
/** @var string $message */
/** @var string $error */
/** @var array<string,mixed>|null $report */
/** @var array<string,mixed>|null $last */
/** @var bool $api_ready */
/** @var bool $pull_enabled */
?>
<section class="page-head">
  <h1><?= te('yclients.import.title') ?></h1>
  <p class="muted"><?= te('yclients.import.lede') ?></p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<?php if (!$pull_enabled || !$api_ready): ?>
  <div class="flash flash-warn">
    <?= !$pull_enabled ? te('yclients.status.off') : te('yclients.status.missing') ?>
    — <a href="/yclients/settings"><?= te('yclients.menu.settings') ?></a>
  </div>
<?php endif; ?>

<form method="post" class="form-grid">
  <input type="hidden" name="action" value="clients">
  <label class="check"><input type="checkbox" name="dry_run" value="1" checked> <?= te('yclients.import.dry_run') ?></label>
  <div class="page-actions">
    <button class="btn-primary" type="submit"<?= (!$pull_enabled || !$api_ready) ? ' disabled' : '' ?>><?= te('yclients.import.clients') ?></button>
  </div>
</form>

<?php if (is_array($report)): ?>
<section>
  <h2>Report</h2>
  <pre class="code-block"><?= e(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '') ?></pre>
</section>
<?php endif; ?>

<section>
  <h2><?= te('yclients.import.last') ?></h2>
  <?php if (!is_array($last)): ?>
    <p class="muted"><?= te('yclients.status.none') ?></p>
  <?php else: ?>
    <pre class="code-block"><?= e(json_encode($last, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '') ?></pre>
  <?php endif; ?>
</section>
