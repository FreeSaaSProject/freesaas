<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var string $q */
/** @var array<int,string> $categoryNames */
/** @var bool $canEdit */
/** @var string $sort */
/** @var string $dir */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */
$path = '/categories';
$showAll = !empty($showAll);
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, $showAll);
?>
<section class="page-head">
  <div class="page-head-row">
    <div>
      <p class="lede"><?= t('catalog.cat.lede', ['total' => (int)$total]) ?></p>
    </div>
    <?php if ($canEdit): ?>
    <div class="page-actions">
      <a class="btn-primary" href="/categories/new"><?= te('core.common.add') ?></a>
    </div>
    <?php endif; ?>
  </div>
</section>

<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('catalog.cat.search_placeholder') ?>" data-filter-debounce>
</form>

<?= $pager ?>

<div class="table-wrap">
  <table class="data">
    <thead>
      <tr>
        <?= ListQuery::sortTh('ID', 'id', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('catalog.col.num'), 'num', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('catalog.col.name'), 'name', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('catalog.cat.parent'), 'parent_id', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('catalog.cat.no_cart'), 'no_cart', $sort, $dir, $path, $filters) ?>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $c): ?>
      <tr>
        <td class="muted"><?= (int)$c['id'] ?></td>
        <td><?= (int)$c['num'] ?></td>
        <td>
          <a href="/categories/<?= (int)$c['id'] ?>/edit"><?= e((string)$c['name']) ?></a>
          <?php if ((string)($c['name_short'] ?? '') !== ''): ?>
            <span class="muted">(<?= e((string)$c['name_short']) ?>)</span>
          <?php endif; ?>
        </td>
        <td class="muted">
          <?php
            $pid = (int)$c['parent_id'];
            echo $pid > 0 ? e($categoryNames[$pid] ?? ('#' . $pid)) : '—';
          ?>
        </td>
        <td><?= !empty($c['no_cart']) ? te('core.common.yes') : te('core.common.no') ?></td>
        <td>
          <?php if ($canEdit): ?>
            <a href="/categories/<?= (int)$c['id'] ?>/edit"><?= te('core.common.edit') ?></a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?>
      <tr><td colspan="6" class="muted"><?= te('catalog.cat.empty') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?= $pager ?>
