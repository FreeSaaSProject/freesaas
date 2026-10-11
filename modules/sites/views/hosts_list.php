<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var string $q */
/** @var string $enabled */
/** @var bool $canEdit */
/** @var string $sort */
/** @var string $dir */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */
/** @var array|null $bulkFlash */
$path = '/sites/hosts';
$listId = 'sites_hosts';
$showAll = !empty($showAll);
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, $showAll);
$colSpan = 8 + BulkActions::colSpanExtra($listId);
?>
<section class="page-head">
  <p class="lede"><?= te('sites.hosts.lede') ?></p>
  <?php if ($canEdit): ?>
  <div class="page-head-row">
    <div></div>
    <div class="page-actions">
      <a class="btn-primary" href="/sites/hosts/new"><?= te('core.common.add') ?></a>
    </div>
  </div>
  <?php endif; ?>
</section>

<?php if (!empty($bulkFlash)): ?>
  <?= BulkActions::flashHtml($bulkFlash) ?>
<?php endif; ?>

<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('sites.hosts.search_placeholder') ?>" data-filter-debounce>
  <select name="enabled">
    <option value=""<?= $enabled === '' ? ' selected' : '' ?>><?= te('sites.hosts.filter.all') ?></option>
    <option value="1"<?= $enabled === '1' ? ' selected' : '' ?>><?= te('sites.hosts.filter.enabled') ?></option>
    <option value="0"<?= $enabled === '0' ? ' selected' : '' ?>><?= te('sites.hosts.filter.disabled') ?></option>
  </select>
</form>

<?= $pager ?>

<div class="table-wrap">
  <table class="data">
    <thead>
      <tr>
        <?= BulkActions::th($listId) ?>
        <?= ListQuery::sortTh(t('sites.hosts.col.host'), 'host', $sort, $dir, $path, $filters) ?>
        <th><?= te('sites.hosts.col.name') ?></th>
        <th><?= te('sites.hosts.col.theme') ?></th>
        <th><?= te('sites.hosts.col.promo') ?></th>
        <th><?= te('sites.hosts.col.catalog') ?></th>
        <?= ListQuery::sortTh(t('sites.hosts.col.enabled'), 'enabled', $sort, $dir, $path, $filters) ?>
        <th><?= te('sites.hosts.col.default') ?></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $row): ?>
      <tr>
        <?= BulkActions::td($listId, (int)$row['id']) ?>
        <td>
          <a href="/sites/hosts/<?= (int)$row['id'] ?>/edit"><?= e((string)$row['host']) ?></a>
        </td>
        <td><?= e((string)($row['name'] ?? '')) ?></td>
        <td><code><?= e((string)($row['theme'] ?? '')) ?></code></td>
        <td class="muted"><?= (int)($row['promo_id'] ?? 0) > 0 ? '#' . (int)$row['promo_id'] : '—' ?></td>
        <td class="muted"><?php
          $roots = isset($row['catalog_root_ids']) && is_array($row['catalog_root_ids'])
            ? array_values(array_filter(array_map('intval', $row['catalog_root_ids'])))
            : (((int)($row['catalog_root_id'] ?? 0) > 0) ? [(int)$row['catalog_root_id']] : []);
          if ($roots === []) {
              echo te('sites.hosts.catalog_all');
          } else {
              echo e('#' . implode(', #', $roots));
          }
        ?></td>
        <td><?= !empty($row['enabled']) ? te('core.common.on') : te('core.common.off') ?></td>
        <td><?= !empty($row['is_default']) ? te('sites.hosts.default_yes') : '' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?>
      <tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('sites.hosts.empty') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?= BulkActions::bar($listId, $path, $filters) ?>
<?= $pager ?>
