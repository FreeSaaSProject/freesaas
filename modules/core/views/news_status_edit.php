<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array $row */
/** @var int $usage */
/** @var list<string> $policies */
/** @var string $message */
/** @var string $error */
$isNew = !empty($isNew);
$usage = (int)($usage ?? 0);
$policies = $policies ?? ['optional', 'required', 'forbidden'];
$name = (string)($row['NAME'] ?? '');
$type = (int)($row['TYPE'] ?? 2);
$policy = (string)($row['DEADLINE_POLICY'] ?? 'optional');
$color = (string)($row['COLOR'] ?? '');
$id = (int)($row['ID'] ?? 0);
?>
<section class="page-head">
  <p class="lede muted"><?= t('core.news_statuses.lede') ?></p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid">
  <input type="hidden" name="action" value="save">
  <?php if ($isNew): ?>
    <label><?= te('core.news_statuses.field.id') ?>
      <input type="number" name="ID" min="1" step="1" value="" placeholder="<?= te('core.news_statuses.field.id_auto') ?>">
      <span class="muted"><?= te('core.news_statuses.field.id_hint') ?></span>
    </label>
  <?php else: ?>
    <p class="muted">ID: <strong><?= $id ?></strong>
      <?php if ($usage > 0): ?>
        · <?= e(t('core.news_statuses.usage', ['n' => (string)$usage])) ?>
      <?php endif; ?>
    </p>
  <?php endif; ?>

  <label><?= te('core.news_statuses.field.name') ?>
    <input type="text" name="NAME" required maxlength="50" value="<?= e($name) ?>">
  </label>

  <label><?= te('core.news_statuses.field.type') ?>
    <input type="number" name="TYPE" min="0" step="1" value="<?= $type ?>">
    <span class="muted"><?= te('core.news_statuses.field.type_hint') ?></span>
  </label>

  <label><?= te('core.news_statuses.field.policy') ?>
    <select name="DEADLINE_POLICY">
      <?php foreach ($policies as $p): ?>
        <option value="<?= e($p) ?>"<?= $policy === $p ? ' selected' : '' ?>><?= e(t('core.news_statuses.policy.' . $p)) ?></option>
      <?php endforeach; ?>
    </select>
    <span class="muted"><?= te('core.news_statuses.field.policy_hint') ?></span>
  </label>

  <label><?= te('core.news_statuses.field.color') ?>
    <input type="text" name="COLOR" maxlength="10" value="<?= e($color) ?>" placeholder="#336699 / red">
  </label>

  <?php if (!$isNew): ?>
    <p class="preview"><?= status_pill(['NAME' => $name !== '' ? $name : '…', 'COLOR' => $color]) ?></p>
  <?php endif; ?>

  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/news-statuses"><?= te('core.common.cancel') ?></a>
  </div>
</form>

<?php if (!$isNew): ?>
  <form method="post" class="form-grid" style="margin-top:1.5rem"
        onsubmit="return confirm(<?= tj('core.news_statuses.delete_confirm') ?>);">
    <input type="hidden" name="action" value="delete">
    <button class="btn-secondary" type="submit"<?= $usage > 0 ? ' disabled' : '' ?>>
      <?= te('core.common.delete') ?>
    </button>
    <?php if ($usage > 0): ?>
      <p class="muted"><?= e(t('core.news_statuses.err.in_use', ['n' => (string)$usage])) ?></p>
    <?php endif; ?>
  </form>
<?php endif; ?>
