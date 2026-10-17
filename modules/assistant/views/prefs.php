<?php
declare(strict_types=1);
/** @var string $message */
/** @var string $error */
/** @var bool $fab_off */
/** @var list<string> $hidden_paths */
$message = (string)($message ?? '');
$error = (string)($error ?? '');
$fab_off = !empty($fab_off);
$hidden_paths = is_array($hidden_paths ?? null) ? $hidden_paths : [];
?>
<section class="page-head">
  <p class="lede"><?= te('assistant.prefs.lede') ?></p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid">
  <fieldset>
    <legend><?= te('assistant.prefs.sec.fab') ?></legend>
    <label class="check">
      <input type="checkbox" name="fab_off" value="1"<?= $fab_off ? ' checked' : '' ?>>
      <?= te('assistant.prefs.fab_off') ?>
    </label>
    <p class="muted"><?= te('assistant.prefs.fab_off_hint') ?></p>
  </fieldset>

  <fieldset>
    <legend><?= te('assistant.prefs.sec.hidden') ?></legend>
    <?php if ($hidden_paths === []): ?>
      <p class="muted"><?= te('assistant.prefs.hidden_empty') ?></p>
    <?php else: ?>
      <p class="muted"><?= te('assistant.prefs.hidden_hint') ?></p>
      <ul class="assistant-prefs-hidden">
        <?php foreach ($hidden_paths as $p): ?>
          <li>
            <label class="check">
              <input type="checkbox" name="unhide[]" value="<?= e($p) ?>">
              <code><?= e($p) ?></code>
              <span class="muted"><?= te('assistant.prefs.unhide') ?></span>
            </label>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </fieldset>

  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn" href="/assistant"><?= e(AssistantConfig::displayName()) ?></a>
  </div>
</form>
