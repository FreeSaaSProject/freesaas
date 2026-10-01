<?php
/**
 * Shared day-assignments panel (standalone /workweeks and embedded in calendar).
 *
 * @var string $date Y-m-d
 * @var string $formAction POST target URL
 * @var list<array<string,mixed>> $shifts
 * @var array<int,string> $assignableStaff
 * @var array<int,bool> $assignedIds
 * @var bool $canAssign
 * @var int $staffId optional hidden staff filter for calendar redirect
 */
$shifts = $shifts ?? [];
$assignableStaff = $assignableStaff ?? [];
$assignedIds = $assignedIds ?? [];
$canAssign = !empty($canAssign);
$formAction = (string)($formAction ?? '/workweeks/day/' . ($date ?? date('Y-m-d')));
$staffId = (int)($staffId ?? 0);
$showHeading = $showHeading ?? true;
$cabinetsOn = WorkweeksRepository::cabinetsActive();
$cabinetOptions = $cabinetsOn ? UsersRepository::cabinets() : [];
$colCount = ($cabinetsOn ? 4 : 3) + ($canAssign ? 1 : 0);
$shiftDateField = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($shiftDate ?? $date ?? ''))
    ? (string)($shiftDate ?? $date)
    : '';
?>
<?php if (!empty($showHeading)): ?>
  <h2><?= te('workweeks.day.sec') ?></h2>
<?php endif; ?>
<div class="table-wrap">
  <table class="data">
    <thead>
      <tr>
        <th><?= te('workweeks.col.staff') ?></th>
        <th><?= te('workweeks.col.price') ?></th>
        <th><?= te('workweeks.col.mas_max') ?></th>
        <?php if ($cabinetsOn): ?>
          <th><?= te('workweeks.col.table') ?></th>
        <?php endif; ?>
        <?php if ($canAssign): ?><th></th><?php endif; ?>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($shifts as $sh): ?>
      <?php
      $oid = (int)$sh['id_operator'];
      $fullName = (string)($sh['operator_name'] !== '' ? $sh['operator_name'] : ('#' . $oid));
      $acronym = class_exists('CalendarTimeline', false)
          ? CalendarTimeline::acronym($fullName)
          : $fullName;
      $bg = trim((string)($sh['color'] ?? ''));
      $fg = trim((string)($sh['color_text'] ?? ''));
      if ($bg === '') {
          $bg = 'var(--bg-input)';
      }
      if ($fg === '') {
          $fg = 'var(--text)';
      }
      $g = (int)($sh['group'] ?? 0);
      $nameClass = 'workweeks-op';
      if ($g === 3) {
          $nameClass .= ' is-assist';
      } elseif ($g === 4) {
          $nameClass .= ' is-hidden-op';
      } elseif ($g === 2) {
          $nameClass .= ' is-admin';
      } elseif ($g === 1) {
          $nameClass .= ' is-fl';
      }
      ?>
      <tr>
        <td>
          <span class="<?= e($nameClass) ?>"
                title="<?= e($fullName) ?>"
                style="background:<?= e($bg) ?>;color:<?= e($fg) ?>;"><?= e($acronym) ?></span>
          <span class="muted"><?= e($fullName) ?></span>
        </td>
        <?php if ($canAssign && WorkweeksRepository::canAssignOperator($oid)): ?>
          <td colspan="<?= (int)($colCount - 1) ?>">
            <form method="post" class="calendar-shift-row" action="<?= e($formAction) ?>">
              <?php if ($shiftDateField !== ''): ?>
                <input type="hidden" name="shift_date" value="<?= e($shiftDateField) ?>">
              <?php endif; ?>
              <input type="hidden" name="staff" value="<?= (int)$staffId ?>">
              <input type="hidden" name="shift_id" value="<?= (int)$sh['id'] ?>">
              <input type="number" min="1" max="5" name="price_col" value="<?= (int)$sh['price_col'] ?>" title="<?= te('workweeks.col.price') ?>">
              <input type="number" min="1" name="mas_max" value="<?= (int)$sh['mas_max'] ?>" title="<?= te('workweeks.col.mas_max') ?>">
              <?php if ($cabinetsOn): ?>
                <select name="id_table" title="<?= te('workweeks.col.table') ?>">
                  <?php
                  $curTable = WorkweeksRepository::normalizeTableId((int)$sh['id_table']);
                  foreach ($cabinetOptions as $cid => $clabel):
                  ?>
                    <option value="<?= (int)$cid ?>"<?= $curTable === (int)$cid ? ' selected' : '' ?>><?= e($clabel) ?></option>
                  <?php endforeach; ?>
                  <?php if ($cabinetOptions === []): ?>
                    <option value="1" selected>1</option>
                  <?php endif; ?>
                </select>
              <?php else: ?>
                <input type="hidden" name="id_table" value="1">
              <?php endif; ?>
              <button class="btn-secondary btn-sm" type="submit" name="shift_action" value="update"><?= te('core.common.save') ?></button>
              <button class="btn-danger btn-sm" type="submit" name="shift_action" value="delete"
                      onclick="return confirm(<?= tj('workweeks.confirm_delete') ?>)"><?= te('core.common.delete') ?></button>
            </form>
          </td>
        <?php else: ?>
          <td><?= (int)$sh['price_col'] ?></td>
          <td><?= (int)$sh['mas_max'] ?></td>
          <?php if ($cabinetsOn): ?>
            <td><?= (int)$sh['id_table'] > 0 ? (int)$sh['id_table'] : '1' ?></td>
          <?php endif; ?>
          <?php if ($canAssign): ?><td></td><?php endif; ?>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    <?php if (!$shifts): ?>
      <tr><td colspan="<?= (int)$colCount ?>" class="muted"><?= te('workweeks.day.empty') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?php if ($canAssign): ?>
<form method="post" class="filters calendar-shift-add" action="<?= e($formAction) ?>">
  <?php if ($shiftDateField !== ''): ?>
    <input type="hidden" name="shift_date" value="<?= e($shiftDateField) ?>">
  <?php endif; ?>
  <input type="hidden" name="staff" value="<?= (int)$staffId ?>">
  <input type="hidden" name="shift_action" value="add">
  <select name="id_operator" required aria-label="<?= te('workweeks.col.staff') ?>">
    <option value=""><?= te('workweeks.pick_staff') ?></option>
    <?php foreach ($assignableStaff as $sid => $label): ?>
      <?php if (!empty($assignedIds[(int)$sid])) {
          continue;
      } ?>
      <option value="<?= (int)$sid ?>"><?= e($label) ?></option>
    <?php endforeach; ?>
  </select>
  <input type="number" min="1" max="5" name="price_col" value="1" title="<?= te('workweeks.col.price') ?>" aria-label="<?= te('workweeks.col.price') ?>">
  <input type="number" min="1" name="mas_max" value="1" title="<?= te('workweeks.col.mas_max') ?>" aria-label="<?= te('workweeks.col.mas_max') ?>">
  <?php if ($cabinetsOn): ?>
    <select name="id_table" aria-label="<?= te('workweeks.col.table') ?>">
      <?php foreach ($cabinetOptions as $cid => $clabel): ?>
        <option value="<?= (int)$cid ?>"><?= e($clabel) ?></option>
      <?php endforeach; ?>
      <?php if ($cabinetOptions === []): ?>
        <option value="1">1</option>
      <?php endif; ?>
    </select>
  <?php else: ?>
    <input type="hidden" name="id_table" value="1">
  <?php endif; ?>
  <button class="btn-secondary" type="submit"><?= te('workweeks.add') ?></button>
</form>
<?php endif; ?>
