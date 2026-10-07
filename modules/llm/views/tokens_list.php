<?php
declare(strict_types=1);
/** @var array<string,array<string,mixed>> $profiles */
/** @var string $message */
/** @var string $error */
$profiles = is_array($profiles ?? null) ? $profiles : [];
?>
<section class="page-head">
  <p class="lede"><?= te('llm.tokens.lede') ?></p>
  <p><a class="btn" href="<?= e(NavContext::withReturn('/llm/tokens/new')) ?>"><?= te('llm.tokens.add') ?></a></p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<?php if ($profiles === []): ?>
  <p class="muted"><?= te('llm.tokens.empty') ?></p>
<?php else: ?>
<table class="data-table">
  <thead>
    <tr>
      <th><?= te('llm.tokens.col.id') ?></th>
      <th><?= te('llm.tokens.col.label') ?></th>
      <th><?= te('llm.tokens.col.model') ?></th>
      <th><?= te('llm.tokens.col.base') ?></th>
      <th><?= te('llm.tokens.col.key') ?></th>
      <th><?= te('llm.tokens.col.enabled') ?></th>
      <th></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($profiles as $p): ?>
    <tr>
      <td>
        <a href="<?= e(NavContext::withReturn('/llm/tokens/' . rawurlencode((string)$p['id']))) ?>"><?= e((string)$p['id']) ?></a>
        <?php if (!empty($p['is_default'])): ?>
          <span class="pill"><?= te('llm.tokens.default_badge') ?></span>
        <?php endif; ?>
      </td>
      <td><?= e((string)$p['label']) ?></td>
      <td><?= e((string)$p['model_chat']) ?></td>
      <td class="muted"><code><?= e((string)$p['api_base']) ?></code></td>
      <td><code><?= e((string)$p['api_key_masked']) ?></code></td>
      <td><?= !empty($p['enabled']) ? te('llm.tokens.yes') : te('llm.tokens.no') ?></td>
      <td>
        <form method="post" class="inline" onsubmit="return confirm(<?= tj('llm.tokens.delete_confirm') ?>);">
          <input type="hidden" name="delete_id" value="<?= e((string)$p['id']) ?>">
          <?= NavContext::hiddenField() ?>
          <button type="submit" class="btn btn-sm btn-danger"><?= te('llm.tokens.delete') ?></button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
