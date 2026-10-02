<?php
/**
 * Oper-style nazop matrix (days × operators).
 *
 * @var array $matrix
 * @var array<int,array<string,mixed>> $availableOps
 * @var bool $canManageOps
 * @var string $formAction
 * @var string $retUrl
 * @var string $mode all|Y-m-d
 */
Assets::need('workweeks');
$matrix = $matrix ?? ['days' => [], 'operators' => [], 'assigned' => [], 'day_price' => [], 'day_mas_max' => []];
$days = $matrix['days'] ?? [];
$operators = $matrix['operators'] ?? [];
$assigned = $matrix['assigned'] ?? [];
$dayPrice = $matrix['day_price'] ?? [];
$dayMasMax = $matrix['day_mas_max'] ?? [];
$availableOps = $availableOps ?? [];
$canManageOps = !empty($canManageOps);
$formAction = (string)($formAction ?? '/calendar/nazop');
$retUrl = (string)($retUrl ?? '/calendar');
$mode = (string)($mode ?? 'all');
$colspan = 3 + count($operators) + 2;

$visibleIds = [];
foreach ($operators as $op) {
    $visibleIds[(int)$op['id']] = true;
}
$opsCsv = implode(',', array_map('intval', array_keys($visibleIds)));
$allIds = [];
foreach ($availableOps as $oid => $_info) {
    $allIds[] = (int)$oid;
}
$allCsv = implode(',', $allIds);
$allVisible = $availableOps !== [] && count($visibleIds) >= count($availableOps);
?>
<form method="post" action="<?= e($formAction) ?>" class="nazop-form" id="nazop-form"
      data-nazop-day="<?= e($mode) ?>"
      data-nazop-ret="<?= e($retUrl) ?>"
      data-nazop-ops="<?= e($opsCsv) ?>">
  <input type="hidden" name="nazop_action" value="save">
  <input type="hidden" name="ret" value="<?= e($retUrl) ?>">
  <input type="hidden" name="mode" value="<?= e($mode) ?>">
  <?php foreach ($operators as $op): ?>
    <input type="hidden" name="ops[]" value="<?= (int)$op['id'] ?>">
  <?php endforeach; ?>

  <div class="nazop-toolbar">
    <div class="nazop-toolbar-actions">
      <button type="submit" class="btn-primary btn-sm"><?= te('workweeks.nazop.save') ?></button>
      <a class="btn-secondary btn-sm" href="<?= e($retUrl) ?>" data-nazop-close><?= te('core.common.cancel') ?></a>
      <button type="button" class="btn-secondary btn-sm" data-nazop-check="1"><?= te('workweeks.nazop.check_all') ?></button>
      <button type="button" class="btn-secondary btn-sm" data-nazop-check="0"><?= te('workweeks.nazop.uncheck_all') ?></button>
    </div>
    <p class="muted nazop-lede"><?= te('workweeks.nazop.lede') ?></p>
  </div>

  <?php if ($canManageOps && $availableOps): ?>
  <div class="nazop-ops" role="group" aria-label="<?= te('workweeks.nazop.ops_aria') ?>">
    <?php foreach ($availableOps as $oid => $info): ?>
      <?php
      $oid = (int)$oid;
      $on = isset($visibleIds[$oid]);
      if ($on) {
          $next = [];
          foreach (array_keys($visibleIds) as $vid) {
              if ((int)$vid !== $oid) {
                  $next[] = (int)$vid;
              }
          }
          $nextCsv = $next === [] ? (string)Auth::userId() : implode(',', $next);
          $sign = '−';
      } else {
          $next = array_keys($visibleIds);
          $next[] = $oid;
          $nextCsv = implode(',', array_map('intval', $next));
          $sign = '+';
      }
      $bg = (string)($info['bg'] ?? 'var(--bg-input)');
      $fg = (string)($info['fg'] ?? 'var(--text)');
      $label = (string)($info['acronym'] ?? $info['label'] ?? ('#' . $oid));
      ?>
      <button type="button"
              class="nazop-op-btn<?= $on ? ' is-on' : '' ?>"
              data-nazop-ops="<?= e($nextCsv) ?>"
              style="background:<?= e($bg) ?>;color:<?= e($fg) ?>;"
              title="<?= e((string)($info['label'] ?? $label)) ?>"><?= e($sign . $label) ?></button>
    <?php endforeach; ?>
    <?php if ($allVisible): ?>
      <button type="button" class="nazop-op-btn nazop-op-all" data-nazop-ops="<?= (int)Auth::userId() ?>">
        <?= te('workweeks.nazop.ops_none') ?>
      </button>
    <?php else: ?>
      <button type="button" class="nazop-op-btn nazop-op-all" data-nazop-ops="<?= e($allCsv) ?>">
        <?= te('workweeks.nazop.ops_all') ?>
      </button>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if (!$operators): ?>
    <div class="flash flash-warn"><?= te('workweeks.nazop.no_ops') ?></div>
  <?php else: ?>
  <div class="nazop-table-wrap">
    <table class="data nazop-table">
      <thead>
        <tr>
          <th>#</th>
          <th><?= te('workweeks.nazop.col_day') ?></th>
          <th title="<?= te('workweeks.nazop.col_none') ?>">н</th>
          <?php foreach ($operators as $op): ?>
            <th class="nazop-op-h"
                title="<?= e($op['label']) ?>"
                style="background:<?= e($op['bg']) ?>;color:<?= e($op['fg']) ?>;"><?= e($op['acronym']) ?></th>
          <?php endforeach; ?>
          <th><?= te('workweeks.col.price') ?></th>
          <th><?= te('workweeks.col.mas_max') ?></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($days as $i => $day): ?>
        <?php
        $d = $day['date'];
        $rowAssigned = $assigned[$d] ?? [];
        $pc = (int)($dayPrice[$d] ?? 1);
        $mixed = ($pc === 0);
        if ($mixed) {
            $pc = 1;
        }
        $mm = (int)($dayMasMax[$d] ?? 1);
        $onCount = 0;
        foreach ($rowAssigned as $oid => $_meta) {
            if (isset($visibleIds[(int)$oid])) {
                $onCount++;
            }
        }
        ?>
        <tr<?= !empty($day['is_weekend']) ? ' class="is-weekend"' : '' ?>>
          <td class="muted"><?= (int)$i ?></td>
          <td class="nazop-day"><nobr><?= e($day['label']) ?> <?= e($day['weekday']) ?></nobr></td>
          <td><input type="checkbox" checked disabled aria-hidden="true"></td>
          <?php foreach ($operators as $op): ?>
            <?php
            $oid = (int)$op['id'];
            $on = isset($rowAssigned[$oid]);
            ?>
            <td class="nazop-cell" style="background:<?= e($op['bg']) ?>;color:<?= e($op['fg']) ?>;">
              <label class="nazop-check">
                <input type="checkbox"
                       name="checked[<?= e($d) ?>][<?= $oid ?>]"
                       value="1"
                       <?= $on ? ' checked' : '' ?>>
                <?php if ($on && (int)$rowAssigned[$oid]['price_col'] > 1): ?>
                  <span class="nazop-pc"><?= (int)$rowAssigned[$oid]['price_col'] ?></span>
                <?php endif; ?>
              </label>
            </td>
          <?php endforeach; ?>
          <td>
            <?php if ($mixed): ?>
              <span class="muted" title="<?= te('workweeks.nazop.mixed_prices') ?>">[<?= (int)$onCount ?>]</span>
              <input type="hidden" name="price[<?= e($d) ?>]" value="1">
            <?php else: ?>
              <select name="price[<?= e($d) ?>]" class="nazop-select" aria-label="<?= te('workweeks.col.price') ?>">
                <?php for ($n = 1; $n <= 5; $n++): ?>
                  <option value="<?= $n ?>"<?= $pc === $n ? ' selected' : '' ?>><?= $n ?></option>
                <?php endfor; ?>
              </select>
              <span class="muted">[<?= (int)$onCount ?>]</span>
            <?php endif; ?>
          </td>
          <td>
            <select name="mas_max[<?= e($d) ?>]" class="nazop-select" aria-label="<?= te('workweeks.col.mas_max') ?>">
              <?php for ($n = 1; $n <= 9; $n++): ?>
                <option value="<?= $n ?>"<?= $mm === $n ? ' selected' : '' ?>><?= $n ?></option>
              <?php endfor; ?>
            </select>
          </td>
        </tr>
        <?php if ((int)date('N', $day['ts']) === 7): ?>
          <tr class="nazop-week-sep"><td colspan="<?= (int)$colspan ?>"><hr></td></tr>
        <?php endif; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="nazop-toolbar nazop-toolbar-bottom">
    <button type="submit" class="btn-primary"><?= te('workweeks.nazop.save') ?></button>
    <a class="btn-secondary" href="<?= e($retUrl) ?>" data-nazop-close><?= te('core.common.cancel') ?></a>
  </div>
  <?php endif; ?>
</form>
