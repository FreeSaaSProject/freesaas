<?php
declare(strict_types=1);

/**
 * @var string $title
 * @var string $day
 * @var int $emp
 * @var int $year
 * @var int $month
 * @var bool $canGenerate
 */
?>
<div class="page-lede">
  <p><?= te('staff_reports.generate.hint') ?></p>
</div>
<?php if (empty($canGenerate)): ?>
  <p class="err"><?= te('staff_reports.generate.denied') ?></p>
<?php else: ?>
  <form method="get" action="/staff-reports/generate" class="form" target="_blank">
    <input type="hidden" name="run" value="1">
    <div class="form-row">
      <label><?= te('staff_reports.generate.day') ?>
        <input type="date" name="day" value="<?= e((string)($day ?? '')) ?>">
      </label>
    </div>
    <div class="form-row">
      <label><?= te('staff_reports.generate.emp') ?>
        <input type="number" min="0" name="emp" value="<?= (int)($emp ?? 0) ?>">
      </label>
    </div>
    <div class="form-row">
      <label class="form-check">
        <input type="checkbox" name="month" value="1">
        <?= te('staff_reports.generate.month') ?>
      </label>
      <input type="number" name="year" value="<?= (int)($year ?? date('Y')) ?>" min="2000" max="2100" style="width:6rem">
      <input type="number" name="month_num" value="<?= (int)($month ?? date('n')) ?>" min="1" max="12" style="width:4rem">
    </div>
    <button type="submit" class="btn"><?= te('staff_reports.generate.run') ?></button>
  </form>
<?php endif; ?>
