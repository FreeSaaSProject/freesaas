<?php
declare(strict_types=1);
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
$matrix = $matrix ?? ['days' => [], 'operators' => [], 'assigned' => [], 'day_mas_max' => []];
$days = $matrix['days'] ?? [];
$operators = $matrix['operators'] ?? [];
$assigned = $matrix['assigned'] ?? [];
$dayMasMax = $matrix['day_mas_max'] ?? [];
$availableOps = $availableOps ?? [];
$canManageOps = !empty($canManageOps);
$formAction = (string)($formAction ?? '/workweeks/nazop');
$retUrl = (string)($retUrl ?? '/calendar');
$mode = (string)($mode ?? 'all');
$shiftWindows = WorkweeksRepository::shiftWindows();
$defaultLane = WorkweeksRepository::normalizePriceCol(1);
$colspan = 3 + count($operators) + 1;

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

$statusDefaults = WorkweeksRepository::defaultPriceColsForOperators(
    array_values(array_unique(array_merge(array_keys($visibleIds), $allIds)))
);

$shiftOptionsHtml = static function (int $selected) use ($shiftWindows): string {
    $html = '';
    foreach ($shiftWindows as $n => $w) {
        $n = (int)$n;
        $sel = $selected === $n ? ' selected' : '';
        // Label only in the matrix (times live in workweeks settings / tooltips).
        $text = (string)$w['label'];
        $title = $w['label'] . ' ' . $w['start'] . '–' . $w['end'];
        $html .= '<option value="' . $n . '"' . $sel . ' title="' . e($title) . '">' . e($text) . '</option>';
    }
    return $html;
};

$bdayByDay = [];
$bdayOpIds = [];
if (class_exists('UsersRepository', false)) {
    $dayDates = [];
    foreach ($days as $day) {
        $dd = (string)($day['date'] ?? '');
        if ($dd !== '') {
            $dayDates[] = $dd;
        }
    }
    $bdayByDay = UsersRepository::birthdaysForDates($dayDates);
    foreach ($bdayByDay as $list) {
        foreach ($list as $bd) {
            $bid = (int)($bd['id'] ?? 0);
            if ($bid > 0 && isset($visibleIds[$bid])) {
                $bdayOpIds[$bid] = true;
            }
        }
    }
}
?>
<form method="post" action="<?= e($formAction) ?>" class="nazop-form" id="nazop-form"
      data-nazop-day="<?= e($mode) ?>"
      data-nazop-ret="<?= e($retUrl) ?>"
      data-nazop-current-ops="<?= e($opsCsv) ?>">
  <input type="hidden" name="nazop_action" value="save">
  <input type="hidden" name="ret" value="<?= e($retUrl) ?>">
  <input type="hidden" name="mode" value="<?= e($mode) ?>">
  <?php foreach ($operators as $op): ?>
    <input type="hidden" name="ops[]" value="<?= (int)$op['id'] ?>">
  <?php endforeach; ?>

  <div class="nazop-chrome">
    <div class="nazop-toolbar">
      <div class="nazop-toolbar-actions">
        <button type="submit" class="btn-primary btn-sm"><?= te('workweeks.nazop.save') ?></button>
        <button type="button" class="btn-secondary btn-sm" data-nazop-check="1"><?= te('workweeks.nazop.check_all') ?></button>
        <button type="button" class="btn-secondary btn-sm" data-nazop-check="0"><?= te('workweeks.nazop.uncheck_all') ?></button>
        <span class="nazop-bulk-shift" title="<?= te('workweeks.nazop.set_shift_hint') ?>">
          <select class="nazop-select" data-nazop-bulk-shift aria-label="<?= te('workweeks.nazop.set_shift') ?>">
            <?= $shiftOptionsHtml(1) ?>
          </select>
          <button type="button" class="btn-secondary btn-sm" data-nazop-set-shift><?= te('workweeks.nazop.set_shift') ?></button>
        </span>
        <button type="button" class="btn-secondary btn-sm" data-nazop-hide-shifts><?= te('workweeks.nazop.hide_shifts') ?></button>
        <button type="button" class="btn-secondary btn-sm" data-nazop-show-shifts hidden><?= te('workweeks.nazop.show_shifts') ?></button>
      </div>
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
  </div>

  <?php if (!$operators): ?>
    <div class="flash flash-warn"><?= te('workweeks.nazop.no_ops') ?></div>
  <?php else: ?>
  <?php if ($bdayOpIds !== []): ?>
    <div class="flash flash-warn nazop-bday-flash"><?= te('workweeks.nazop.birthday_warn') ?></div>
  <?php endif; ?>
  <div class="nazop-table-wrap">
    <table class="data nazop-table">
      <thead>
        <tr>
          <th>#</th>
          <th><?= te('workweeks.nazop.col_day') ?></th>
          <th title="<?= te('workweeks.nazop.col_none') ?>">н</th>
          <?php foreach ($operators as $op): ?>
            <?php
            $opIsBday = isset($bdayOpIds[(int)$op['id']]);
            $opTitle = (string)$op['label'];
            if ($opIsBday) {
                $opTitle .= ' · ' . t('workweeks.nazop.birthday_op');
            }
            ?>
            <th class="nazop-op-h<?= $opIsBday ? ' is-bday' : '' ?>"
                title="<?= e($opTitle) ?>"
                style="background:<?= e($op['bg']) ?>;color:<?= e($op['fg']) ?>;"><?= e($op['acronym']) ?><?= $opIsBday ? '·ДР' : '' ?></th>
          <?php endforeach; ?>
          <th><?= te('workweeks.col.mas_max') ?></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($days as $i => $day): ?>
        <?php
        $d = $day['date'];
        $rowAssigned = $assigned[$d] ?? [];
        $mm = (int)($dayMasMax[$d] ?? 1);
        $onCount = 0;
        foreach ($rowAssigned as $oid => $_meta) {
            if (isset($visibleIds[(int)$oid])) {
                $onCount++;
            }
        }
        $dayBdays = $bdayByDay[$d] ?? [];
        $dayBdayIds = [];
        $dayBdayNames = [];
        foreach ($dayBdays as $bd) {
            $bid = (int)($bd['id'] ?? 0);
            if ($bid < 1 || !isset($visibleIds[$bid])) {
                continue;
            }
            $dayBdayIds[$bid] = true;
            $dayBdayNames[] = (string)($bd['name'] ?? '') . ' (№' . (int)($bd['age'] ?? 0) . ')';
        }
        $rowClass = !empty($day['is_weekend']) ? 'is-weekend' : '';
        if ($dayBdayIds !== []) {
            $rowClass = trim($rowClass . ' is-bday-row');
        }
        ?>
        <tr<?= $rowClass !== '' ? ' class="' . e($rowClass) . '"' : '' ?>>
          <td class="muted"><?= (int)$i ?></td>
          <td class="nazop-day">
            <nobr><?= e($day['label']) ?> <?= e($day['weekday']) ?></nobr>
            <?php if ($dayBdayNames !== []): ?>
              <div class="nazop-bday-note" title="<?= e(implode(', ', $dayBdayNames)) ?>"><?= e(t('workweeks.nazop.birthday_day', [
                'names' => implode(', ', $dayBdayNames),
              ])) ?></div>
            <?php endif; ?>
          </td>
          <td><input type="checkbox" checked disabled aria-hidden="true"></td>
          <?php foreach ($operators as $op): ?>
            <?php
            $oid = (int)$op['id'];
            $on = isset($rowAssigned[$oid]);
            $statusPc = (int)($statusDefaults[$oid] ?? 0);
            $opPc = $on
                ? WorkweeksRepository::normalizePriceCol((int)$rowAssigned[$oid]['price_col'])
                : ($statusPc >= 1 ? $statusPc : $defaultLane);
            $cellBday = isset($dayBdayIds[$oid]);
            ?>
            <td class="nazop-cell<?= $cellBday ? ' is-bday' : '' ?>" style="background:<?= e($op['bg']) ?>;color:<?= e($op['fg']) ?>;">
              <label class="nazop-check"<?= $cellBday ? ' title="' . e(t('workweeks.nazop.birthday_op')) . '"' : '' ?>>
                <input type="checkbox"
                       name="checked[<?= e($d) ?>][<?= $oid ?>]"
                       value="1"
                       <?= $on ? ' checked' : '' ?>>
                <select name="op_price[<?= e($d) ?>][<?= $oid ?>]"
                        class="nazop-select nazop-op-price"
                        aria-label="<?= te('workweeks.col.price') ?>"
                        title="<?= te('workweeks.col.price') ?>">
                  <?= $shiftOptionsHtml($opPc) ?>
                </select>
              </label>
            </td>
          <?php endforeach; ?>
          <td>
            <select name="mas_max[<?= e($d) ?>]" class="nazop-select nazop-mas-max" aria-label="<?= te('workweeks.col.mas_max') ?>">
              <?php for ($n = 1; $n <= 9; $n++): ?>
                <option value="<?= $n ?>"<?= $mm === $n ? ' selected' : '' ?>><?= $n ?></option>
              <?php endfor; ?>
            </select>
            <span class="muted nazop-on-count">[<?= (int)$onCount ?>]</span>
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
