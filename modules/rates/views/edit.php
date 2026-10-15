<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var array<int,string> $staff */
/** @var array<int,string> $typeNames */
/** @var string $dtValue */
/** @var bool $showAudioRates */
/** @var string $message */
/** @var string $error */
$showAudioRates = !empty($showAudioRates);

$sections = [
    'content' => t('rates.section.content'),
    'content_sales' => t('rates.section.content_sales'),
    'content_income' => t('rates.section.content_income'),
    'content_terminal' => t('rates.section.content_terminal'),
];
?>
<section class="page-head">
  <h1><?= $isNew ? te('rates.edit.heading_new') : e((string)($row['descr'] ?? '')) ?></h1>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid form-wide">
  <label><?= te('rates.col.operator') ?>
    <select name="operator_id" required>
      <option value=""><?= te('rates.list.filter_all') ?></option>
      <?php foreach ($staff as $sid => $sname): ?>
        <option value="<?= (int)$sid ?>"<?= (int)($row['operator_id'] ?? 0) === (int)$sid ? ' selected' : '' ?>><?= e($sname) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('rates.col.dt') ?>
    <input type="datetime-local" name="dt" value="<?= e($dtValue) ?>" required>
  </label>
  <label><?= te('rates.col.descr') ?>
    <input name="descr" value="<?= e((string)($row['descr'] ?? '')) ?>" required>
  </label>
  <label><?= te('rates.col.loss') ?>
    <input name="loss" value="<?= e((string)($row['loss'] ?? '0')) ?>">
  </label>
  <label><?= te('rates.col.loss_fix') ?>
    <input name="loss_fix" value="<?= e((string)($row['loss_fix'] ?? '0')) ?>">
  </label>
  <label><?= te('rates.col.offers') ?>
    <input name="offers" type="number" min="0" value="<?= e((string)($row['offers'] ?? '0')) ?>">
  </label>
  <?php if ($showAudioRates): ?>
  <label><?= te('rates.col.audio_hour_rate') ?>
    <input name="audio_hour_rate" value="<?= e((string)($row['audio_hour_rate'] ?? '0')) ?>">
  </label>
  <label><?= te('rates.col.script_score_rate') ?>
    <input name="script_score_rate" value="<?= e((string)($row['script_score_rate'] ?? '0')) ?>">
  </label>
  <?php endif; ?>

  <?php foreach ($sections as $key => $label): ?>
    <fieldset class="form-fieldset">
      <legend><?= e($label) ?></legend>
      <?php if (!$typeNames): ?>
        <p class="muted"><?= te('rates.list.empty') ?></p>
      <?php else: ?>
        <?php
          $map = is_array($row[$key] ?? null) ? $row[$key] : [];
        ?>
        <?php foreach ($typeNames as $tid => $tname): ?>
          <label><?= e($tname) ?>
            <input name="<?= e($key) ?>[<?= (int)$tid ?>]" value="<?= e((string)($map[$tid] ?? $map[(string)$tid] ?? '0')) ?>">
          </label>
        <?php endforeach; ?>
      <?php endif; ?>
    </fieldset>
  <?php endforeach; ?>

  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/rates"><?= te('core.common.cancel') ?></a>
  </div>
</form>

<?php if (empty($isNew) && empty($is_new)): ?>
<?= EntityDelete::form('rates', (int)($row['id'] ?? 0)) ?>
<?php endif; ?>

