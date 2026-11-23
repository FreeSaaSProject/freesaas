<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var string $q */
/** @var int $unmarked */
/** @var int $result */
/** @var string $day */
/** @var array<int,string> $resultLabels */
/** @var array<int,string> $kindLabels */
/** @var bool $canEdit */
/** @var string $sort */
/** @var string $dir */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */
$path = '/acquisition/calls';
$showAll = !empty($showAll);
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, $showAll);
?>
<section class="page-head">
  <div class="page-head-row">
    <div></div>
    <div class="page-actions">
      <?php if ($canEdit): ?>
        <a class="btn-primary" href="/acquisition/calls/new"><?= te('acquisition.calls.new') ?></a>
      <?php endif; ?>
      <a class="btn-secondary<?= $unmarked ? ' is-active' : '' ?>" href="/acquisition/calls?unmarked=1"><?= te('acquisition.calls.unmarked') ?></a>
    </div>
  </div>
</section>

<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('acquisition.calls.col.phone') ?>" data-filter-debounce>
  <input type="date" name="day" value="<?= e($day) ?>">
  <select name="result">
    <option value=""><?= te('acquisition.calls.col.result') ?></option>
    <?php foreach ($resultLabels as $rid => $lab): ?>
      <option value="<?= (int)$rid ?>"<?= $result === (int)$rid ? ' selected' : '' ?>><?= e($lab) ?></option>
    <?php endforeach; ?>
  </select>
  <label class="check">
    <input type="checkbox" name="unmarked" value="1"<?= $unmarked ? ' checked' : '' ?>>
    <?= te('acquisition.calls.unmarked') ?>
  </label>
</form>

<?= $pager ?>

<div class="table-wrap">
  <table class="data">
    <thead>
      <tr>
        <th><?= te('acquisition.calls.col.dt') ?></th>
        <th><?= te('acquisition.calls.col.phone') ?></th>
        <th><?= te('acquisition.calls.col.kind') ?></th>
        <th><?= te('acquisition.calls.col.result') ?></th>
        <th><?= te('acquisition.calls.col.channel') ?></th>
        <th><?= te('acquisition.calls.col.request') ?></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $c): ?>
      <tr>
        <td class="muted">
          <?php if ($canEdit): ?>
            <a href="/acquisition/calls/<?= (int)$c['id'] ?>/edit"><?= e(date('Y-m-d H:i', (int)$c['dt_call'])) ?></a>
          <?php else: ?>
            <?= e(date('Y-m-d H:i', (int)$c['dt_call'])) ?>
          <?php endif; ?>
        </td>
        <td><?= e((string)$c['phone']) ?></td>
        <td><?= e($kindLabels[(int)$c['client_kind']] ?? (string)$c['client_kind']) ?></td>
        <td><?= e($resultLabels[(int)$c['result_id']] ?? (string)$c['result_id']) ?></td>
        <td><?= e((string)$c['channel']) ?></td>
        <td><?= e(mb_substr((string)$c['request'], 0, 80)) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?>
      <tr><td colspan="6" class="muted"><?= te('core.common.empty') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?= $pager ?>
