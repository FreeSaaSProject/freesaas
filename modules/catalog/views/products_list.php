<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var string $q */
/** @var string $enabled */
/** @var string $isMaterial */
/** @var int $categoryId */
/** @var array<int,string> $categories */
/** @var bool $canEdit */
/** @var string $sort */
/** @var string $dir */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */
$path = '/products';
$showAll = !empty($showAll);
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, $showAll);
?>
<section class="page-head">
  <div class="page-head-row">
    <div>
      <p class="lede"><?= t('catalog.product.lede', ['total' => (int)$total]) ?></p>
    </div>
    <?php if ($canEdit): ?>
    <div class="page-actions">
      <a class="btn-primary" href="/products/new"><?= te('core.common.add') ?></a>
    </div>
    <?php endif; ?>
  </div>
</section>

<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('catalog.product.search_placeholder') ?>" data-filter-debounce>
  <select name="category" data-auto-submit>
    <option value="0"><?= te('catalog.product.all_categories') ?></option>
    <?php foreach ($categories as $cid => $cname): ?>
      <option value="<?= (int)$cid ?>"<?= $categoryId === (int)$cid ? ' selected' : '' ?>><?= e($cname) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="is_material" data-auto-submit>
    <option value=""<?= $isMaterial === '' ? ' selected' : '' ?>><?= te('catalog.product.filter_kind_all') ?></option>
    <option value="0"<?= $isMaterial === '0' ? ' selected' : '' ?>><?= te('catalog.product.filter_services') ?></option>
    <option value="1"<?= $isMaterial === '1' ? ' selected' : '' ?>><?= te('catalog.product.filter_materials') ?></option>
  </select>
  <select name="enabled" data-auto-submit>
    <option value=""<?= $enabled === '' ? ' selected' : '' ?>><?= te('core.common.all') ?></option>
    <option value="1"<?= $enabled === '1' ? ' selected' : '' ?>><?= te('catalog.product.filter_active') ?></option>
    <option value="0"<?= $enabled === '0' ? ' selected' : '' ?>><?= te('catalog.product.filter_disabled') ?></option>
  </select>
</form>

<?= $pager ?>

<div class="table-wrap">
  <table class="data">
    <thead>
      <tr>
        <?= ListQuery::sortTh('ID', 'id', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('catalog.col.num'), 'num', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('catalog.col.name'), 'name', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('catalog.col.category'), 'category', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('catalog.col.price'), 'price', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('catalog.col.kind'), 'is_material', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('catalog.col.enabled'), 'enabled', $sort, $dir, $path, $filters) ?>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $p): ?>
      <tr>
        <td class="muted"><?= (int)$p['id'] ?></td>
        <td><?= (int)$p['num'] ?></td>
        <td>
          <a href="/products/<?= (int)$p['id'] ?>/edit"><?= e((string)$p['name']) ?></a>
          <?php if (empty($p['enabled'])): ?>
            <span class="pill off"><?= te('core.common.off') ?></span>
          <?php endif; ?>
        </td>
        <td class="muted"><?= e((string)($p['category_name'] ?? '—')) ?></td>
        <td><?= e((string)$p['price']) ?></td>
        <td><?= !empty($p['is_material']) ? te('catalog.kind.material') : te('catalog.kind.service') ?></td>
        <td><?= !empty($p['enabled']) ? te('core.common.on') : te('core.common.off') ?></td>
        <td>
          <?php if ($canEdit): ?>
            <a href="/products/<?= (int)$p['id'] ?>/edit"><?= te('core.common.edit') ?></a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?>
      <tr><td colspan="8" class="muted"><?= te('catalog.product.empty') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?= $pager ?>
