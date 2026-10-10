<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var string $q */
/** @var string $enabled */
/** @var string $site_host_id */
/** @var array<int,string> $hostOptions */
/** @var bool $canEdit */
/** @var bool $tree */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */
/** @var array|null $bulkFlash */
$path = '/sites/pages';
$listId = 'sites_pages';
$showAll = !empty($showAll);
$tree = !empty($tree);
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, $showAll);
$colSpan = 7 + BulkActions::colSpanExtra($listId);
?>
<section class="page-head">
  <?php if ($canEdit): ?>
  <div class="page-head-row">
    <div></div>
    <div class="page-actions">
      <a class="btn-primary" href="/sites/pages/new"><?= te('core.common.add') ?></a>
    </div>
  </div>
  <?php endif; ?>
  <?php if ($tree): ?>
    <p class="muted"><?= te('sites.pages.tree_hint') ?></p>
  <?php endif; ?>
</section>

<?php if (!empty($bulkFlash)): ?>
  <?= BulkActions::flashHtml($bulkFlash) ?>
<?php endif; ?>

<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs('id', 'desc', (int)$perPage) ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('sites.pages.search_placeholder') ?>" data-filter-debounce>
  <select name="enabled">
    <option value=""<?= $enabled === '' ? ' selected' : '' ?>><?= te('sites.pages.filter.all') ?></option>
    <option value="1"<?= $enabled === '1' ? ' selected' : '' ?>><?= te('sites.pages.filter.enabled') ?></option>
    <option value="0"<?= $enabled === '0' ? ' selected' : '' ?>><?= te('sites.pages.filter.disabled') ?></option>
  </select>
  <?php if (!empty($hostOptions)): ?>
  <select name="site_host_id">
    <?php foreach ($hostOptions as $hid => $hlabel): ?>
      <option value="<?= e((string)$hid) ?>"<?= (string)$site_host_id === (string)$hid ? ' selected' : '' ?>><?= e($hlabel) ?></option>
    <?php endforeach; ?>
  </select>
  <?php endif; ?>
</form>

<?= $pager ?>

<?= BulkActions::beginForm($listId, ListQuery::url($path, $filters, [])) ?>
<?= BulkActions::bar($listId) ?>
<div class="table-wrap">
  <table class="data">
    <thead>
      <tr>
        <?= BulkActions::th($listId) ?>
        <th>ID</th>
        <th><?= te('sites.pages.col.num') ?></th>
        <th><?= te('sites.pages.col.name') ?></th>
        <th><?= te('sites.pages.col.link') ?></th>
        <th><?= te('sites.pages.col.content') ?></th>
        <th><?= te('sites.pages.col.enabled') ?></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $c): ?>
      <?php
        $level = (int)($c['level'] ?? 0);
        $nameClass = !empty($c['has_children']) || !empty($c['is_home']) ? 'likespa-page-name is-strong' : 'likespa-page-name';
      ?>
      <tr>
        <?= BulkActions::td($listId, (int)$c['id']) ?>
        <td class="muted">
          <?php if ($canEdit): ?>
            <a href="/sites/pages/<?= (int)$c['id'] ?>/edit"><?= (int)$c['id'] ?></a>
          <?php else: ?>
            <?= (int)$c['id'] ?>
          <?php endif; ?>
        </td>
        <td><?= (int)$c['num'] ?></td>
        <td>
          <span class="<?= e($nameClass) ?>" style="--depth:<?= (int)$level ?>">
            <?= e((string)$c['name']) ?>
            <?php if (!empty($c['is_home'])): ?>
              <span class="muted">— <?= te('sites.pages.parent.home') ?></span>
            <?php endif; ?>
          </span>
          <?php if ($canEdit && empty($c['is_home'])): ?>
            <a class="btn-ghost likespa-page-add-child" href="/sites/pages/new?pid=<?= (int)$c['id'] ?>" title="<?= te('sites.pages.add_child') ?>">+<?= te('sites.pages.add_child_short') ?></a>
          <?php endif; ?>
        </td>
        <td><code><?= e((string)$c['link']) ?></code></td>
        <td><?= !empty($c['has_content']) ? te('core.common.yes') : '—' ?></td>
        <td><?= !empty($c['enabled']) ? te('core.common.yes') : te('core.common.no') ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?>
      <tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('sites.pages.empty') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?= BulkActions::endForm($listId) ?>

<?= $pager ?>
