<?php
declare(strict_types=1);
/** @var array<string,mixed> $period */
/** @var list<array<string,mixed>> $rows */
/** @var array<string,float> $totals */
/** @var list<array<string,mixed>> $negative */
/** @var string $gran */
/** @var int $fundType */
/** @var bool $hideEmpty */
/** @var string $date */
/** @var string $urlPrev */
/** @var string $urlNext */
/** @var string $urlThis */
/** @var string $urlWeek */
/** @var string $urlMonth */
/** @var bool $canEdit */
$pagerAttrs = ' data-pager-prev="' . e($urlPrev) . '" data-pager-next="' . e($urlNext) . '"';
$cols = ['opening', 'accrued', 'transfer_in', 'in_other', 'spent', 'transfer_out', 'out_other', 'closing'];
$fmt = static function (float $v): string {
    return FundMovementsRepository::fmt($v);
};
$renderRow = static function (array $r, bool $child) use ($cols, $fmt, $canEdit): void {
    $cls = ($r['is_group'] ? 'funds-group-row' : ($child ? 'funds-child-row' : '')) . (!empty($r['negative']) ? ' funds-row-negative' : '');
    echo '<tr class="' . e(trim($cls)) . '">';
    echo '<td class="' . ($child ? 'funds-name' : '') . '"><a href="/funds/' . (int)$r['id'] . '">' . e((string)$r['name']) . '</a>';
    if (!empty($r['rules'])) {
        $parts = [];
        foreach ($r['rules'] as $rule) {
            if (empty($rule['enabled'])) {
                continue;
            }
            $parts[] = $rule['kind'] === 'percent'
                ? rtrim(rtrim(number_format((float)$rule['percent'], 3, '.', ''), '0'), '.') . '%'
                : FundMovementsRepository::fmt((float)$rule['fixed_sum']);
        }
        if ($parts !== []) {
            echo ' <span class="muted funds-count">' . e(implode(', ', $parts)) . '</span>';
        }
    }
    if (!empty($r['negative'])) {
        echo ' <span class="pill funds-pill-negative">' . te('funds.statement.negative') . '</span>';
        if ($canEdit && !$r['is_group']) {
            echo ' <a href="/funds/transfer?to=' . (int)$r['id'] . '">' . te('funds.statement.cover') . '</a>';
        }
    }
    echo '</td>';
    foreach ($cols as $c) {
        $bold = $c === 'closing' ? ' funds-closing' : '';
        echo '<td class="num' . $bold . '">' . e($fmt((float)$r[$c])) . '</td>';
    }
    echo '</tr>';
};
?>
<section class="page-head">
  <div class="page-head-row">
    <div></div>
    <div class="page-actions">
      <a class="btn-secondary<?= $gran === 'week' ? ' is-active' : '' ?>" href="<?= e($urlWeek) ?>"><?= te('funds.statement.gran.week') ?></a>
      <a class="btn-secondary<?= $gran === 'month' ? ' is-active' : '' ?>" href="<?= e($urlMonth) ?>"><?= te('funds.statement.gran.month') ?></a>
      <a class="btn-secondary" href="/funds/rules"><?= te('funds.menu.rules') ?></a>
      <?php if ($canEdit): ?><a class="btn-primary" href="/funds/transfer"><?= te('funds.menu.transfer') ?></a><?php endif; ?>
    </div>
  </div>
</section>

<nav class="pager date-day-nav" aria-label="<?= te('funds.statement.nav_aria') ?>"<?= $pagerAttrs ?>>
  <a class="btn-secondary" href="<?= e($urlPrev) ?>" title="<?= te('funds.statement.prev') ?>">‹</a>
  <strong><?= e((string)$period['label']) ?></strong>
  <a class="btn-secondary" href="<?= e($urlNext) ?>" title="<?= te('funds.statement.next') ?>">›</a>
  <?php if (empty($period['is_current'])): ?>
    <a class="btn-secondary" href="<?= e($urlThis) ?>"><?= te('funds.statement.this') ?></a>
  <?php endif; ?>
</nav>

<form class="filters" method="get" action="/funds/statement" data-auto-filter>
  <input type="hidden" name="gran" value="<?= e($gran) ?>">
  <input type="hidden" name="date" value="<?= e($date) ?>">
  <label><?= te('funds.moves.filter_fund_type') ?>
    <select name="fund_type">
      <option value="2"<?= $fundType === 2 ? ' selected' : '' ?>><?= te('funds.type.2') ?></option>
      <option value="1"<?= $fundType === 1 ? ' selected' : '' ?>><?= te('funds.type.1') ?></option>
      <option value="0"<?= $fundType === 0 ? ' selected' : '' ?>><?= te('funds.moves.filter_all') ?></option>
    </select>
  </label>
  <label class="check"><input type="checkbox" name="hide_empty" value="1"<?= $hideEmpty ? ' checked' : '' ?>> <?= te('funds.statement.hide_empty') ?></label>
</form>

<?php if ($negative): ?>
  <div class="flash flash-warn">
    <?= te('funds.statement.overdrawn', ['n' => count($negative)]) ?>:
    <?php foreach ($negative as $i => $n): ?><?= $i > 0 ? ', ' : ' ' ?><a href="/funds/<?= (int)$n['id'] ?>"><?= e((string)$n['name']) ?></a> (<?= e($fmt((float)$n['closing'])) ?>)<?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="table-wrap"><table class="data funds-table">
<thead><tr>
  <th><?= te('funds.col.name') ?></th>
  <?php foreach ($cols as $c): ?><th class="num"><?= te('funds.statement.col.' . $c) ?></th><?php endforeach; ?>
</tr></thead>
<tbody>
<?php foreach ($rows as $r): ?>
  <?php $renderRow($r, false); ?>
  <?php foreach ($r['children'] as $c): ?><?php $renderRow($c, true); ?><?php endforeach; ?>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="9" class="muted"><?= te('funds.list.empty') ?></td></tr><?php endif; ?>
</tbody>
<?php if ($rows): ?>
<tfoot><tr>
  <td><?= te('funds.view.total') ?></td>
  <?php foreach ($cols as $c): ?><td class="num"><strong><?= e($fmt((float)$totals[$c])) ?></strong></td><?php endforeach; ?>
</tr></tfoot>
<?php endif; ?>
</table></div>
<p class="muted"><?= te('funds.statement.hint') ?></p>
