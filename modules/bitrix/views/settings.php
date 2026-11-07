<?php
declare(strict_types=1);
?>
<section class="page-head"><h1><?= te('bitrix.menu.settings') ?></h1></section>
<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="form-grid form-wide">
  <label class="check"><input type="checkbox" name="pull_enabled" value="1"<?= ($cfg['pull_enabled'] ?? '') === '1' ? ' checked' : '' ?>> <?= te('bitrix.col.pull') ?></label>
  <label class="check"><input type="checkbox" name="push_enabled" value="1"<?= ($cfg['push_enabled'] ?? '') === '1' ? ' checked' : '' ?>> <?= te('bitrix.col.push') ?></label>
  <label><?= te('bitrix.col.portal') ?><input name="portal" value="<?= e((string)($cfg['portal'] ?? '')) ?>"></label>
  <label><?= te('bitrix.col.rest_url') ?><input name="rest_url" value="<?= e((string)($cfg['rest_url'] ?? '')) ?>"></label>
  <label><?= te('bitrix.col.webhook_token') ?> (<?= e((string)($cfg['webhook_token_masked'] ?? '')) ?>)
    <input name="webhook_token" type="password" autocomplete="new-password" placeholder="<?= te('bitrix.secret.keep') ?>">
  </label>
  <label><?= te('bitrix.col.worker_token') ?> (<?= e((string)($cfg['worker_token_masked'] ?? '')) ?>)
    <input name="worker_token" type="password" autocomplete="new-password" placeholder="<?= te('bitrix.secret.keep') ?>">
  </label>
  <label><?= te('bitrix.col.timeout') ?><input type="number" name="timeout_sec" value="<?= e((string)($cfg['timeout_sec'] ?? '30')) ?>"></label>
  <label><?= te('bitrix.col.page_size') ?><input type="number" name="page_size" value="<?= e((string)($cfg['page_size'] ?? '50')) ?>"></label>
  <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
</form>
