<?php
declare(strict_types=1);
/**
 * Oper-style nazcab: assign massage tables (sections) + soft badges.
 *
 * @var array $matrix
 * @var string $formAction
 * @var string $retUrl
 * @var string $mode
 */
Assets::need('workweeks');
$matrix = $matrix ?? [
    'days' => [],
    'operators' => [],
    'assigned' => [],
    'badges' => [],
    'table_names' => [],
    'badge_options' => [],
    'show_badges' => false,
    'show_tables' => true,
];
$days = $matrix['days'] ?? [];
$operators = $matrix['operators'] ?? [];
$assigned = $matrix['assigned'] ?? [];
$badges = $matrix['badges'] ?? [];
$tableNames = $matrix['table_names'] ?? [];
$badgeOptions = $matrix['badge_options'] ?? [];
$showBadges = !empty($matrix['show_badges']);
$showTables = !empty($matrix['show_tables']);
$formAction = (string)($formAction ?? '/workweeks/nazcab');
$retUrl = (string)($retUrl ?? '/calendar');
$mode = (string)($mode ?? 'all');
$today = date('Y-m-d');

$tableOptionsHtml = static function (int $selected) use ($tableNames): string {
    $html = '<option value="0"' . ($selected < 1 ? ' selected' : '') . '>'
        . e(t('workweeks.nazcab.table_none')) . '</option>';
    foreach ($tableNames as $tid => $name) {
        $tid = (int)$tid;
        $sel = $selected === $tid ? ' selected' : '';
        $html .= '<option value="' . $tid . '"' . $sel . '>' . e((string)$name) . '</option>';
    }
    return $html;
};
?>
<form method="post" action="<?= e($formAction) ?>" class="nazcab-form" id="nazcab-form"
      data-nazcab-day="<?= e($mode) ?>"
      data-nazcab-ret="<?= e($retUrl) ?>">
  <input type="hidden" name="nazcab_action" value="save">
  <input type="hidden" name="ret" value="<?= e($retUrl) ?>">
  <input type="hidden" name="mode" value="<?= e($mode) ?>">

  <div class="nazop-toolbar">
    <div class="nazop-toolbar-actions">
      <button type="submit" class="btn-primary btn-sm"><?= te('workweeks.nazcab.save') ?></button>
      <a class="btn-secondary btn-sm" href="<?= e($retUrl) ?>" data-nazcab-close><?= te('core.common.cancel') ?></a>
    </div>
    <p class="muted nazop-lede"><?= WorkweeksRepository::canNazcabPeriod()
        ? te('workweeks.nazcab.lede')
        : te('workweeks.nazcab.lede_today') ?></p>
  </div>

  <?php if ($operators === []): ?>
    <p class="muted"><?= te('workweeks.nazcab.no_shifts') ?></p>
  <?php else: ?>
  <div class="nazop-table-wrap">
    <table class="data nazop-table nazcab-table">
      <thead>
        <tr>
          <th scope="col"><?= te('workweeks.nazop.col_day') ?></th>
          <?php foreach ($operators as $op): ?>
            <th scope="col" class="nazop-op-h"
                title="<?= e((string)$op['label']) ?>"
                style="background:<?= e((string)$op['bg']) ?>;color:<?= e((string)$op['fg']) ?>;">
              <?= e((string)$op['acronym']) ?>
            </th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($days as $day): ?>
          <?php
          $d = (string)$day['date'];
          $rowAssigned = $assigned[$d] ?? [];
          $isToday = $d === $today;
          $tablesEditable = $showTables && $isToday;
          ?>
          <tr class="<?= !empty($day['is_weekend']) ? 'is-weekend' : '' ?><?= $isToday ? ' is-today' : '' ?>">
            <td>
              <span class="nazop-day-label"><?= e((string)$day['label']) ?></span>
              <span class="muted"><?= e((string)$day['weekday']) ?></span>
              <?php if ($isToday && $showTables): ?>
                <span class="nazcab-today-mark muted"><?= te('workweeks.nazcab.tables_today_hint') ?></span>
              <?php endif; ?>
            </td>
            <?php foreach ($operators as $op): ?>
              <?php
              $oid = (int)$op['id'];
              $on = array_key_exists($oid, $rowAssigned);
              $tid = $on ? (int)$rowAssigned[$oid] : 0;
              $assist = !empty($op['assist']);
              $audio = !empty($op['audio']);
              $badgeVal = (string)($badges[$d][$oid] ?? '0');
              if ($badgeVal === '') {
                  $badgeVal = '0';
              }
              ?>
              <td class="nazop-cell nazcab-cell">
                <?php if (!$on): ?>
                  <span class="muted">—</span>
                <?php else: ?>
                  <?php if ($tablesEditable && !$assist): ?>
                    <select name="table[<?= e($d) ?>][<?= $oid ?>]" class="nazcab-table-sel"
                            title="<?= te('workweeks.col.table') ?>">
                      <?= $tableOptionsHtml($tid) ?>
                    </select>
                  <?php elseif ($tablesEditable && $assist): ?>
                    <span class="muted" title="<?= te('workweeks.nazcab.assist_no_table') ?>">—</span>
                  <?php endif; ?>
                  <?php if ($showBadges && $audio): ?>
                    <select name="badge[<?= e($d) ?>][<?= $oid ?>]" class="nazcab-badge-sel"
                            title="<?= te('workweeks.nazcab.badge') ?>">
                      <?php foreach ($badgeOptions as $code => $label): ?>
                        <option value="<?= e((string)$code) ?>"<?= (string)$code === $badgeVal ? ' selected' : '' ?>>
                          <?= e((string)$label) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  <?php elseif (!$tablesEditable && !$showBadges): ?>
                    <span class="muted">—</span>
                  <?php endif; ?>
                <?php endif; ?>
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <div class="nazop-toolbar nazop-toolbar-bottom">
    <div class="nazop-toolbar-actions">
      <button type="submit" class="btn-primary btn-sm"><?= te('workweeks.nazcab.save') ?></button>
      <a class="btn-secondary btn-sm" href="<?= e($retUrl) ?>" data-nazcab-close><?= te('core.common.cancel') ?></a>
    </div>
  </div>
</form>
