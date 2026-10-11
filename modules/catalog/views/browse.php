<?php
declare(strict_types=1);
/** @var int $folderId */
/** @var array<string,mixed>|null $folder */
/** @var list<array{label:string, href?:?string, current?:bool}> $breadcrumbs */
/** @var list<array<string,mixed>> $folders */
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var string $q */
/** @var string $enabled */
/** @var string $isMaterial */
/** @var bool $searchGlobal */
/** @var bool $canEdit */
/** @var bool $canAddCategory */
/** @var bool $canAddProduct */
/** @var list<string> $priceCols */
/** @var string $path */
/** @var string $sort */
/** @var string $dir */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */
/** @var string $message */
$showAll = !empty($showAll);
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, $showAll);
$message = $message ?? '';
$canAddCategory = !empty($canAddCategory);
$canAddProduct = !empty($canAddProduct);
$priceCols = $priceCols ?? [];
$priceLabels = [
    'price' => t('catalog.product.price_n', ['n' => 1]),
    'price2' => t('catalog.product.price_n', ['n' => 2]),
    'price3' => t('catalog.product.price_n', ['n' => 3]),
    'price4' => t('catalog.product.price_n', ['n' => 4]),
    'price5' => t('catalog.product.price_n', ['n' => 5]),
];
$colCount = 4 + ($searchGlobal ? 1 : 0) + count($priceCols) + 1 + ($canEdit ? 1 : 0);
?>
<section class="page-head">
  <?php if ($canAddCategory || $canAddProduct): ?>
  <div class="page-head-row">
    <div></div>
    <div class="page-actions">
      <?php if ($canAddCategory): ?>
        <a class="btn-secondary" href="/categories/new?parent=<?= (int)$folderId ?>">+ <?= te('catalog.browse.add_category') ?></a>
      <?php endif; ?>
      <?php if ($canAddProduct): ?>
        <a class="btn-primary" href="/products/new?category=<?= (int)$folderId ?>">+ <?= te('catalog.browse.add_product') ?></a>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>

<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('catalog.browse.search_placeholder') ?>" data-filter-debounce>
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

<?php if ($folders): ?>
  <h2 class="section-title"><?= te('catalog.browse.folders') ?></h2>
  <div class="folder-list">
    <?php foreach ($folders as $f): ?>
      <?php $isHidden = !empty($f['hidden']); ?>
      <div class="folder-row<?= $isHidden ? ' is-hidden' : '' ?>">
        <a class="folder-item<?= $isHidden ? ' is-hidden' : '' ?>" href="/catalog/<?= (int)$f['id'] ?>">
          <span class="folder-icon" aria-hidden="true">▸</span>
          <span class="folder-name"><?= e((string)$f['name']) ?></span>
          <?php if ($isHidden): ?>
            <span class="pill off"><?= te('catalog.cat.hidden_badge') ?></span>
          <?php endif; ?>
          <span class="muted">
            <?php if ((int)$f['child_count'] > 0): ?>
              <?= t('catalog.browse.n_folders', ['n' => (int)$f['child_count']]) ?>
            <?php endif; ?>
            <?php if ((int)$f['product_count'] > 0): ?>
              <?= ((int)$f['child_count'] > 0 ? ' · ' : '') . t('catalog.browse.n_products', ['n' => (int)$f['product_count']]) ?>
            <?php endif; ?>
          </span>
        </a>
        <?php if ($canEdit): ?>
          <form method="post" class="inline-form" title="<?= te('catalog.cat.visible') ?>">
            <input type="hidden" name="action" value="toggle_category">
            <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
            <label class="switch">
              <input type="checkbox" name="visible" value="1"<?= !$isHidden ? ' checked' : '' ?> onchange="this.form.submit()">
              <span class="switch-slider" aria-hidden="true"></span>
              <span class="visually-hidden"><?= te('catalog.cat.visible') ?></span>
            </label>
          </form>
          <a class="folder-edit" href="/categories/<?= (int)$f['id'] ?>/edit"><?= te('core.common.edit') ?></a>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php elseif ($folderId === 0 && $q === ''): ?>
  <p class="muted"><?= te('catalog.browse.no_folders') ?></p>
<?php endif; ?>

<?php if ($folderId > 0 || $searchGlobal || $items || $q !== ''): ?>
  <?php if ($items || $searchGlobal || $folderId > 0): ?>
  <h2 class="section-title"><?= $searchGlobal ? te('catalog.browse.search_results') : te('catalog.browse.products') ?></h2>

  <?= $pager ?>

  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr>
          <?= ListQuery::sortTh('ID', 'id', $sort, $dir, $path, $filters) ?>
          <?= ListQuery::sortTh(t('catalog.col.num'), 'num', $sort, $dir, $path, $filters) ?>
          <?= ListQuery::sortTh(t('catalog.col.name'), 'name', $sort, $dir, $path, $filters) ?>
          <?php if ($searchGlobal): ?>
            <?= ListQuery::sortTh(t('catalog.col.category'), 'category', $sort, $dir, $path, $filters) ?>
          <?php endif; ?>
          <?php foreach ($priceCols as $pcol): ?>
            <?= ListQuery::sortTh($priceLabels[$pcol] ?? $pcol, $pcol === 'price' ? 'price' : $pcol, $sort, $dir, $path, $filters) ?>
          <?php endforeach; ?>
          <?= ListQuery::sortTh(t('catalog.col.kind'), 'is_material', $sort, $dir, $path, $filters) ?>
          <?php if ($canEdit): ?>
            <th><?= te('catalog.col.enabled') ?></th>
          <?php endif; ?>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($items as $p): ?>
        <tr<?= empty($p['enabled']) ? ' class="is-disabled"' : '' ?>>
          <td class="muted"><?= (int)$p['id'] ?></td>
          <td><?= (int)$p['num'] ?></td>
          <td>
            <a href="/products/<?= (int)$p['id'] ?>/edit"><?= e((string)$p['name']) ?></a>
          </td>
          <?php if ($searchGlobal): ?>
            <td class="muted">
              <?php
                $pcid = (int)($p['category'] ?? 0);
                if ($pcid > 0):
              ?>
                <a href="/catalog/<?= $pcid ?>"><?= e((string)($p['category_name'] ?? ('#' . $pcid))) ?></a>
              <?php else: ?>
                —
              <?php endif; ?>
            </td>
          <?php endif; ?>
          <?php foreach ($priceCols as $pcol): ?>
            <td><?= e((string)($p[$pcol] ?? '0')) ?></td>
          <?php endforeach; ?>
          <td><?= !empty($p['is_material']) ? te('catalog.kind.material') : te('catalog.kind.service') ?></td>
          <?php if ($canEdit): ?>
            <td>
              <form method="post" class="inline-form">
                <input type="hidden" name="action" value="toggle_product">
                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                <label class="switch" title="<?= te('catalog.col.enabled') ?>">
                  <input type="checkbox" name="enabled" value="1"<?= !empty($p['enabled']) ? ' checked' : '' ?> onchange="this.form.submit()">
                  <span class="switch-slider" aria-hidden="true"></span>
                  <span class="visually-hidden"><?= te('catalog.col.enabled') ?></span>
                </label>
              </form>
            </td>
          <?php endif; ?>
          <td>
            <?php if ($canEdit): ?>
              <a href="/products/<?= (int)$p['id'] ?>/edit"><?= te('core.common.edit') ?></a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$items): ?>
        <tr><td colspan="<?= (int)$colCount ?>" class="muted"><?= te('catalog.product.empty') ?></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?= $pager ?>
  <?php endif; ?>
<?php endif; ?>
