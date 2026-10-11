<?php
declare(strict_types=1);
/** @var array<string,mixed> $row */
/** @var string $message */
/** @var string $error */
?>
<section class="page-head">
  <h1><?= te('terminals.fullstats.edit.title', ['type' => (string)($row['ticket_type'] ?? '')]) ?></h1>
  <p class="muted"><?= te('terminals.fullstats.edit.hint') ?></p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid form-wide">
  <p><strong><?= te('terminals.fullstats.col.title') ?>:</strong> <?= e((string)($row['title_full'] ?? '')) ?></p>
  <label><?= te('terminals.fullstats.col.id_type') ?>
    <input type="number" name="id_type" min="0" value="<?= (int)($row['id_type'] ?? 0) ?>">
  </label>
  <label class="check">
    <input type="checkbox" name="is_limited" value="1"<?= !empty($row['is_limited']) ? ' checked' : '' ?>>
    <?= te('terminals.fullstats.col.is_limited') ?>
  </label>
  <label class="check">
    <input type="checkbox" name="is_payer" value="1"<?= !empty($row['is_payer']) ? ' checked' : '' ?>>
    <?= te('terminals.fullstats.col.is_payer') ?>
  </label>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/terminals/fullstats"><?= te('core.common.cancel') ?></a>
  </div>
</form>
