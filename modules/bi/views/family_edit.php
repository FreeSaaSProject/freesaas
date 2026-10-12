<?php
declare(strict_types=1);
/** @var int $def_id */
/** @var string $family_id */
/** @var string $name */
/** @var string $recipe_text */
/** @var int $enabled */
/** @var string $builtin_fork */
/** @var bool $readonly_id */
/** @var bool $shipped */
/** @var string $flash */
/** @var string $error */
/** @var array<string,mixed>|null $validate */
/** @var array<string,mixed> $legend */
$shipped = !empty($shipped);
?>
<p class="lede"><?= te('bi.family.lede') ?></p>
<p><a href="/bi/legend"><?= te('bi.family.legend_link') ?></a></p>
<?php if ($flash !== ''): ?><p class="flash-ok"><?= e($flash) ?></p><?php endif; ?>
<?php if ($error !== ''): ?><p class="flash-err"><?= e($error) ?></p><?php endif; ?>
<?php if ($shipped && $def_id < 1): ?>
  <p class="muted"><?= te('bi.family.shipped_hint') ?></p>
<?php endif; ?>

<?php if (is_array($validate)): ?>
  <div class="bi-validate-box <?= !empty($validate['ok']) ? 'ok' : 'err' ?>">
    <?php if (!empty($validate['ok'])): ?>
      <strong><?= te('bi.family.validate_ok') ?></strong>
    <?php else: ?>
      <strong><?= te('bi.family.validate_fail') ?></strong>
    <?php endif; ?>
    <?php if (!empty($validate['errors']) && is_array($validate['errors'])): ?>
      <ul>
        <?php foreach ($validate['errors'] as $err): ?>
          <li class="mono"><?= e((string)$err) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <?php if (!empty($validate['warnings']) && is_array($validate['warnings'])): ?>
      <ul class="muted">
        <?php foreach ($validate['warnings'] as $w): ?>
          <li class="mono"><?= e((string)$w) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <?php if (!empty($validate['dry_run']) && is_array($validate['dry_run'])): ?>
      <p class="muted mono">
        dry_run: sections=<?= (int)($validate['dry_run']['sections'] ?? 0) ?>
        <?php if (!empty($validate['dry_run']['title'])): ?>
          · <?= e((string)$validate['dry_run']['title']) ?>
        <?php endif; ?>
      </p>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="bi-constructor-grid">
  <form method="post" action="" class="bi-family-form">
    <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
    <input type="hidden" name="def_id" value="<?= (int)$def_id ?>">
    <input type="hidden" name="builtin_fork" value="<?= e($builtin_fork) ?>">

    <div class="bi-run-grid">
      <label><?= te('bi.family.id') ?>
        <input type="text" name="family_id" value="<?= e($family_id) ?>"
               pattern="[a-z][a-z0-9_]*" maxlength="64"
               <?= $readonly_id ? 'readonly' : 'required' ?>>
      </label>
      <label><?= te('bi.family.name') ?>
        <input type="text" name="name" value="<?= e($name) ?>" maxlength="255">
      </label>
      <label class="bi-check">
        <input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
        <?= te('bi.family.enabled') ?>
      </label>
    </div>

    <label><?= te('bi.family.recipe') ?>
      <textarea class="bi-code" name="recipe_json" spellcheck="false" required><?= e($recipe_text) ?></textarea>
    </label>

    <div class="bi-family-actions">
      <button type="submit" class="btn-secondary" name="action" value="validate"><?= te('bi.family.validate') ?></button>
      <button type="submit" class="btn-primary" name="action" value="save"><?= te('bi.family.save') ?></button>
      <?php if ($def_id > 0 && !$shipped): ?>
        <button type="submit" class="btn-secondary" name="action" value="delete"
                onclick="return confirm(<?= json_encode(t('bi.family.delete_confirm'), JSON_UNESCAPED_UNICODE) ?>);">
          <?= te('bi.family.delete') ?>
        </button>
      <?php elseif ($def_id > 0 && $shipped): ?>
        <button type="submit" class="btn-secondary" name="action" value="delete"
                onclick="return confirm(<?= json_encode(t('bi.family.reset_confirm'), JSON_UNESCAPED_UNICODE) ?>);">
          <?= te('bi.family.reset_shipped') ?>
        </button>
      <?php endif; ?>
    </div>
  </form>

  <aside class="bi-legend-panel">
    <h2><?= te('bi.family.legend') ?></h2>
    <p class="muted"><?= te('bi.family.legend_hint') ?></p>
    <pre class="bi-legend-pre mono"><?= e(json_encode($legend, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}') ?></pre>
  </aside>
</div>
