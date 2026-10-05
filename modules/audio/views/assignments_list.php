<?php
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var string $q */
/** @var int $operatorId */
/** @var string $badge */
/** @var bool $active */
/** @var array<int,string> $staff */
/** @var array<string,string> $badgeOptions */
/** @var bool $canEdit */
/** @var string $sort */
/** @var string $dir */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */
$path = '/audio/assignments';
$showAll = !empty($showAll);
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, $showAll);
$colSpan = 7;
?>
<section class="page-head">
  <?php if ($canEdit): ?>
  <div class="page-head-row">
    <div></div>
    <div class="page-actions">
      <a class="btn-primary" href="<?= e(NavContext::withReturn('/audio/assignments/new')) ?>"><?= te('core.common.add') ?></a>
    </div>
  </div>
  <?php endif; ?>
</section>

<?php if (!empty($_GET['deleted'])): ?>
  <div class="flash flash-ok"><?= te('audio.assignments.deleted') ?></div>
<?php endif; ?>

<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('audio.assignments.search_placeholder') ?>" data-filter-debounce>
  <label><?= te('audio.assignments.col.operator') ?>
    <select name="operator_id">
      <option value="0"><?= te('audio.assignments.filter_all') ?></option>
      <?php foreach ($staff as $sid => $sname): ?>
        <option value="<?= (int)$sid ?>"<?= (int)$operatorId === (int)$sid ? ' selected' : '' ?>><?= e($sname) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('audio.col.badge') ?>
    <select name="badge">
      <option value=""><?= te('audio.filter.all_badges') ?></option>
      <option value="0"<?= $badge === '0' ? ' selected' : '' ?>><?= te('audio.badge.opt.not_on_shift') ?></option>
      <?php foreach ($badgeOptions as $code => $label): ?>
        <?php if ((string)$code === '0') { continue; } ?>
        <option value="<?= e((string)$code) ?>"<?= $badge === (string)$code ? ' selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>
    <input type="checkbox" name="active" value="1"<?= $active ? ' checked' : '' ?>>
    <?= te('audio.assignments.filter_active') ?>
  </label>
</form>

<?= $pager ?>

<div class="table-wrap">
  <table class="data">
    <thead>
      <tr>
        <?= ListQuery::sortTh('ID', 'id', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('audio.assignments.col.operator'), 'operator', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('audio.col.badge'), 'badge', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('audio.assignments.col.start'), 'start', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('audio.assignments.col.end'), 'end', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('audio.assignments.col.create'), 'create', $sort, $dir, $path, $filters) ?>
        <th><?= te('audio.assignments.col.creator') ?></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $r): ?>
      <?php
        $opName = $staff[(int)$r['operator_id']] ?? ('#' . (int)$r['operator_id']);
        $crName = (int)$r['creator_id'] > 0
            ? ($staff[(int)$r['creator_id']] ?? ('#' . (int)$r['creator_id']))
            : '—';
        $badgeLabel = (string)$r['badge'] === '0'
            ? t('audio.badge.chip.off')
            : (string)$r['badge'];
        $editHref = NavContext::withReturn('/audio/assignments/' . (int)$r['id'] . '/edit');
      ?>
      <tr>
        <td class="muted">
          <?php if ($canEdit): ?>
            <a href="<?= e($editHref) ?>"><?= (int)$r['id'] ?></a>
          <?php else: ?>
            <?= (int)$r['id'] ?>
          <?php endif; ?>
        </td>
        <td><?= e($opName) ?></td>
        <td><code><?= e($badgeLabel) ?></code></td>
        <td><?= (int)$r['dt_start'] > 0 ? e(date('d.m.Y H:i', (int)$r['dt_start'])) : '—' ?></td>
        <td><?= (int)$r['dt_end'] > 0 ? e(date('d.m.Y H:i', (int)$r['dt_end'])) : '—' ?></td>
        <td class="muted"><?= (int)$r['dt_create'] > 0 ? e(date('d.m.Y H:i', (int)$r['dt_create'])) : '—' ?></td>
        <td class="muted"><?= e($crName) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?>
      <tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('audio.assignments.empty') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?= $pager ?>
