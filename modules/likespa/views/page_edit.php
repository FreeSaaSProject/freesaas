<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var string $message */
/** @var string $error */
/** @var bool $hasChildren */
/** @var array<int,string> $parentOptions */
?>
<section class="page-head">
  <h1><?= $isNew ? te('likespa.pages.edit.heading_new') : e((string)($row['name'] ?? '')) ?></h1>
  <?php if (!empty($hasChildren)): ?>
    <p class="muted"><?= te('likespa.pages.has_children_hint') ?></p>
  <?php endif; ?>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid form-wide">
  <fieldset>
    <legend><?= te('likespa.pages.sec.main') ?></legend>
    <label><?= te('likespa.pages.field.name') ?>
      <input name="name" maxlength="255" value="<?= e((string)($row['name'] ?? '')) ?>">
    </label>
    <label><?= te('likespa.pages.field.num') ?>
      <input type="number" min="0" name="num" value="<?= e((string)($row['num'] ?? '1')) ?>">
    </label>
    <label><?= te('likespa.pages.field.link') ?>
      <input name="link" maxlength="255" value="<?= e((string)($row['link'] ?? '')) ?>" required>
    </label>
    <label><?= te('likespa.pages.field.link_rw') ?>
      <input name="link_rw" maxlength="255" value="<?= e((string)($row['link_rw'] ?? '')) ?>">
      <span class="field-hint muted"><?= te('likespa.pages.field.link_rw_hint') ?></span>
    </label>
    <label><?= te('likespa.pages.field.bg_url') ?>
      <input name="bg_url" maxlength="255" value="<?= e((string)($row['bg_url'] ?? '')) ?>">
    </label>
    <label><?= te('likespa.pages.field.parent_to') ?>
      <select name="parent_to">
        <?php foreach ($parentOptions as $pid => $label): ?>
          <option value="<?= (int)$pid ?>"<?= (int)($row['parent_to'] ?? 0) === (int)$pid ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="check"><input type="checkbox" name="enabled" value="1"<?= !empty($row['enabled']) ? ' checked' : '' ?>> <?= te('likespa.pages.field.enabled') ?></label>
  </fieldset>

  <fieldset>
    <legend><?= te('likespa.pages.sec.content') ?></legend>
    <label><?= te('likespa.pages.field.content') ?>
      <textarea name="content" rows="16"><?= e((string)($row['content'] ?? '')) ?></textarea>
    </label>
  </fieldset>

  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/likespa/pages"><?= te('core.common.cancel') ?></a>
  </div>
</form>
