<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var string $error */
$isNew = !empty($isNew);
$row = is_array($row ?? null) ? $row : [];
?>
<?php if (!empty($message)): ?><div class="flash flash-ok"><?= e((string)$message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid">
  <?= NavContext::hiddenField() ?>
  <?php if ($isNew): ?>
    <label><?= te('llm.tokens.id') ?>
      <input type="text" name="id" required pattern="[A-Za-z0-9][A-Za-z0-9_-]{0,63}" value="<?= e((string)($row['id'] ?? '')) ?>">
      <span class="muted"><?= te('llm.tokens.id_hint') ?></span>
    </label>
  <?php else: ?>
    <p><strong><?= te('llm.tokens.col.id') ?>:</strong> <code><?= e((string)($row['id'] ?? '')) ?></code></p>
  <?php endif; ?>

  <label><?= te('llm.tokens.label') ?>
    <input type="text" name="label" value="<?= e((string)($row['label'] ?? '')) ?>" required>
  </label>
  <label><?= te('llm.tokens.api_base') ?>
    <input type="url" name="api_base" value="<?= e((string)($row['api_base'] ?? LlmConfig::DEFAULT_API_BASE)) ?>" required>
  </label>
  <label><?= te('llm.tokens.model_chat') ?>
    <input type="text" name="model_chat" value="<?= e((string)($row['model_chat'] ?? LlmConfig::DEFAULT_MODEL)) ?>" required>
  </label>
  <label><?= $isNew ? te('llm.tokens.api_key_new') : te('llm.tokens.api_key') ?>
    <input type="password" name="api_key" value="" autocomplete="new-password"<?= $isNew ? ' required' : '' ?>>
    <?php if (!$isNew): ?>
      <span class="muted"><?= te('llm.tokens.api_key_hint') ?> — <?= e((string)($row['api_key_masked'] ?? '')) ?></span>
    <?php endif; ?>
  </label>
  <label class="check"><input type="checkbox" name="enabled" value="1"<?= !empty($row['enabled']) ? ' checked' : '' ?>> <?= te('llm.tokens.enabled') ?></label>
  <label class="check"><input type="checkbox" name="set_default" value="1"<?= !empty($row['is_default']) ? ' checked' : '' ?>> <?= te('llm.tokens.set_default') ?></label>

  <p class="form-actions">
    <button type="submit" class="btn"><?= te('llm.tokens.save') ?></button>
    <a class="btn btn-ghost" href="<?= e(NavContext::withReturn('/llm/tokens')) ?>"><?= te('core.common.cancel') ?></a>
  </p>
</form>
