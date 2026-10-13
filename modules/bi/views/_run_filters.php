<?php
declare(strict_types=1);
/** @var string $familyId */
/** @var array<string,mixed> $params */
/** @var list<array{id:int,label:string}> $operators */
/** @var list<array{id:int,label:string}> $admins */
/** @var list<array{id:int,label:string}> $products */
/** @var bool $shareMode */
/** @var string $workUrl */
/** @var string|null $formAction */
/** @var string $shareToken */
$operators = $operators ?? [];
$admins = $admins ?? [];
$products = $products ?? [];
$shareMode = !empty($shareMode);
$shareToken = trim((string)($shareToken ?? ''));
$years = range((int)date('Y') + 1, (int)date('Y') - 8);
$adj = BiFilterOptions::adjacentMonth((int)$params['year'], (int)$params['month']);
$formAction = (string)($formAction ?? ($shareMode ? $workUrl : '/bi/run'));
// Guest share must not expose staff/product roster; staff share + director UI may.
$showEntityFilters = !$shareMode || (class_exists('Auth', false) && Auth::check());
?>
<form id="bi-run-form" class="bi-run-form" method="get" action="<?= e($formAction) ?>"
      data-bi-nav
      data-prev-year="<?= (int)$adj['prev_year'] ?>"
      data-prev-month="<?= (int)$adj['prev_month'] ?>"
      data-next-year="<?= (int)$adj['next_year'] ?>"
      data-next-month="<?= (int)$adj['next_month'] ?>">
  <?php if (!$shareMode): ?>
    <input type="hidden" name="family" value="<?= e($familyId) ?>">
  <?php endif; ?>
  <?php if ($shareToken !== ''): ?>
    <input type="hidden" name="share" value="<?= e($shareToken) ?>">
  <?php endif; ?>

  <div class="bi-month-nav" role="group" aria-label="<?= te('bi.run.nav_aria') ?>">
    <button type="button" class="btn-secondary bi-nav-btn" data-bi-nav-dir="-1" title="<?= te('bi.run.prev_month') ?>" aria-label="<?= te('bi.run.prev_month') ?>">«</button>
    <label class="bi-nav-year"><?= te('bi.run.year') ?>
      <select name="year" data-bi-auto>
        <?php foreach ($years as $y): ?>
          <option value="<?= (int)$y ?>"<?= (int)$params['year'] === (int)$y ? ' selected' : '' ?>><?= (int)$y ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="bi-nav-month"><?= te('bi.run.month') ?>
      <select name="month" data-bi-auto>
        <?php for ($m = 1; $m <= 12; $m++): ?>
          <option value="<?= $m ?>"<?= (int)$params['month'] === $m ? ' selected' : '' ?>><?= e(t('bi.month.' . $m)) ?></option>
        <?php endfor; ?>
      </select>
    </label>
    <button type="button" class="btn-secondary bi-nav-btn" data-bi-nav-dir="1" title="<?= te('bi.run.next_month') ?>" aria-label="<?= te('bi.run.next_month') ?>">»</button>
    <span class="muted bi-nav-hint"><?= te('bi.run.keyboard') ?></span>
  </div>

  <div class="bi-run-grid">
    <label class="bi-check"><input type="checkbox" name="dayshow" value="1" data-bi-auto<?= !empty($params['dayshow']) ? ' checked' : '' ?>> <?= te('bi.run.dayshow') ?></label>
    <?php if ($familyId === 'staff_kpi'): ?>
      <label><?= te('bi.run.variant') ?>
        <select name="variant" data-bi-auto>
          <option value="full"<?= ($params['variant'] ?? '') === 'full' ? ' selected' : '' ?>><?= te('bi.run.variant_full') ?></option>
          <option value="ppa"<?= ($params['variant'] ?? '') === 'ppa' ? ' selected' : '' ?>><?= te('bi.run.variant_ppa') ?></option>
        </select>
      </label>
    <?php endif; ?>

    <?php if ($showEntityFilters): ?>
      <label><?= te('bi.run.operator') ?>
        <select name="id_operator" data-bi-auto>
          <option value="0"><?= te('bi.run.filter_all') ?></option>
          <?php foreach ($operators as $opt): ?>
            <option value="<?= (int)$opt['id'] ?>"<?= (int)($params['id_operator'] ?? 0) === (int)$opt['id'] ? ' selected' : '' ?>><?= e($opt['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><?= te('bi.run.admin') ?>
        <select name="id_admin" data-bi-auto>
          <option value="0"><?= te('bi.run.filter_all') ?></option>
          <?php foreach ($admins as $opt): ?>
            <option value="<?= (int)$opt['id'] ?>"<?= (int)($params['id_admin'] ?? 0) === (int)$opt['id'] ? ' selected' : '' ?>><?= e($opt['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><?= te('bi.run.product') ?>
        <select name="id_good" data-bi-auto>
          <option value="0"><?= te('bi.run.filter_all') ?></option>
          <?php foreach ($products as $opt): ?>
            <option value="<?= (int)$opt['id'] ?>"<?= (int)($params['id_good'] ?? 0) === (int)$opt['id'] ? ' selected' : '' ?>><?= e($opt['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    <?php endif; ?>
  </div>
</form>

<div id="bi-progress-strip" class="bi-progress-strip" hidden aria-live="polite">
  <div class="bi-progress-bar"></div>
  <div id="bi-progress-status" class="bi-progress-status"><?= te('bi.run.progress') ?></div>
</div>

<div id="bi-report" class="bi-report"></div>

<footer id="bi-build-footer" class="bi-build-footer" hidden>
  <div class="bi-build-footer-title"><?= te('bi.run.build_log') ?></div>
  <div id="bi-progress-log" class="bi-progress-log"></div>
</footer>
<iframe id="bi-work-frame" name="bi_work_frame" class="bi-work-frame" title="<?= te('bi.run.progress') ?>"></iframe>
