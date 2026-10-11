<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var array<int,string> $products */
/** @var string $message */
/** @var string $error */
?>
<section class="page-head"><h1><?= e((string)$title) ?></h1></section>
<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="form-grid form-wide">
  <fieldset>
    <label><?= te('norms.col.proc') ?>
      <select name="id_proc" required>
        <option value="">—</option>
        <?php foreach ($products as $pid => $pname): ?>
          <option value="<?= (int)$pid ?>"<?= (int)($row['id_proc'] ?? 0) === (int)$pid ? ' selected' : '' ?>><?= e($pname) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= te('norms.col.good') ?>
      <select name="id_good" required>
        <option value="">—</option>
        <?php foreach ($products as $pid => $pname): ?>
          <option value="<?= (int)$pid ?>"<?= (int)($row['id_good'] ?? 0) === (int)$pid ? ' selected' : '' ?>><?= e($pname) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= te('norms.col.qtyu2') ?>
      <input name="qtyu2" value="<?= e((string)($row['qtyu2'] ?? '1')) ?>" required>
    </label>
    <label><?= te('norms.col.qtyu') ?>
      <input type="number" name="qtyu" value="<?= e((string)($row['qtyu'] ?? '0')) ?>">
    </label>
  </fieldset>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/norms"><?= te('core.common.cancel') ?></a>
  </div>
</form>
