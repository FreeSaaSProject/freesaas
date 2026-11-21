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
$grain = (($params['grain'] ?? 'month') === 'year') ? 'year' : 'month';
$adj = BiFilterOptions::adjacentMonth((int)$params['year'], (int)$params['month']);
$formAction = (string)($formAction ?? ($shareMode ? $workUrl : '/bi/run'));
// Guest share must not expose staff/product roster; staff share + director UI may.
$showEntityFilters = !$shareMode || (class_exists('Auth', false) && Auth::check());
$prevTitle = $grain === 'year' ? t('bi.run.prev_year') : t('bi.run.prev_month');
$nextTitle = $grain === 'year' ? t('bi.run.next_year') : t('bi.run.next_month');
$navAria = $grain === 'year' ? t('bi.run.nav_aria_year') : t('bi.run.nav_aria');
?>
<form id="bi-run-form" class="bi-run-form" method="get" action="<?= e($formAction) ?>"
      data-bi-nav
      data-bi-grain="<?= e($grain) ?>"
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

  <div class="bi-month-nav" role="group" aria-label="<?= e($navAria) ?>">
    <button type="button" class="btn-secondary bi-nav-btn" data-bi-nav-dir="-1" title="<?= e($prevTitle) ?>" aria-label="<?= e($prevTitle) ?>">«</button>
    <label class="bi-nav-grain"><?= te('bi.run.grain') ?>
      <select name="grain" data-bi-auto data-bi-grain-select>
        <option value="month"<?= $grain === 'month' ? ' selected' : '' ?>><?= te('bi.run.grain_month') ?></option>
        <option value="year"<?= $grain === 'year' ? ' selected' : '' ?>><?= te('bi.run.grain_year') ?></option>
      </select>
    </label>
    <label class="bi-nav-year"><?= te('bi.run.year') ?>
      <select name="year" data-bi-auto>
        <?php foreach ($years as $y): ?>
          <option value="<?= (int)$y ?>"<?= (int)$params['year'] === (int)$y ? ' selected' : '' ?>><?= (int)$y ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="bi-nav-month"<?= $grain === 'year' ? ' hidden' : '' ?> data-bi-month-wrap><?= te('bi.run.month') ?>
      <select name="month" data-bi-auto>
        <?php for ($m = 1; $m <= 12; $m++): ?>
          <option value="<?= $m ?>"<?= (int)$params['month'] === $m ? ' selected' : '' ?>><?= e(t('bi.month.' . $m)) ?></option>
        <?php endfor; ?>
      </select>
    </label>
    <button type="button" class="btn-secondary bi-nav-btn" data-bi-nav-dir="1" title="<?= e($nextTitle) ?>" aria-label="<?= e($nextTitle) ?>">»</button>
    <span class="muted bi-nav-hint"><?= te('bi.run.keyboard') ?></span>
    <button type="button" class="btn-secondary bi-xlsx-btn" data-bi-xlsx><?= te('bi.run.xlsx') ?></button>
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
