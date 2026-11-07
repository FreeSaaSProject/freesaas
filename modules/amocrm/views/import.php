<?php
declare(strict_types=1);
?>
<section class="page-head"><h1><?= te('amocrm.menu.import') ?></h1></section>
<?php if (!$apiReady): ?><div class="flash flash-warn"><?= te('amocrm.warn.api') ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>
<?php if (is_array($report)): ?>
  <pre class="muted"><?= e(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '') ?></pre>
<?php endif; ?>
<?php foreach (['contact', 'company', 'lead'] as $ent): ?>
<form method="post" class="form-grid" style="margin-bottom:1rem">
  <input type="hidden" name="action" value="<?= e($ent) ?>">
  <label class="check"><input type="checkbox" name="dry_run" value="1" checked> <?= te('amocrm.import.dry_run') ?></label>
  <button class="btn-primary" type="submit"><?= te('amocrm.import.run') ?>: <?= e($ent) ?></button>
</form>
<?php endforeach; ?>
<p class="muted"><?= te('amocrm.import.hint') ?></p>
