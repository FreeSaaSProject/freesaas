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
$path = '/sites/promopages';
$listId = 'sites_promopages';
$showAll = !empty($showAll);
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, $showAll);
$colSpan = 6 + BulkActions::colSpanExtra($listId);
?>
<section class="page-head">
  <?php if ($canEdit): ?>
  <div class="page-head-row">
    <div></div>
    <div class="page-actions">
      <a class="btn-primary" href="/sites/promopages/new"><?= te('core.common.add') ?></a>
    </div>
  </div>
  <?php endif; ?>
</section>

<?php if (!empty($bulkFlash)): ?>
  <?= BulkActions::flashHtml($bulkFlash) ?>
<?php endif; ?>

<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('sites.promopages.search_placeholder') ?>" data-filter-debounce>
  <select name="enabled">
    <option value=""<?= $enabled === '' ? ' selected' : '' ?>><?= te('sites.promopages.filter.all') ?></option>
    <option value="1"<?= $enabled === '1' ? ' selected' : '' ?>><?= te('sites.promopages.filter.enabled') ?></option>
    <option value="0"<?= $enabled === '0' ? ' selected' : '' ?>><?= te('sites.promopages.filter.disabled') ?></option>
  </select>
</form>

<?= $pager ?>

<?= BulkActions::beginForm($listId, ListQuery::url($path, $filters, [])) ?>
<?= BulkActions::bar($listId) ?>
<div class="table-wrap">
  <table class="data">
    <thead>
      <tr>
        <?= BulkActions::th($listId) ?>
        <?= ListQuery::sortTh('ID', 'id', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('sites.promopages.col.code'), 'code', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('sites.promopages.col.descr'), 'descr', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('sites.promopages.col.parent'), 'parent', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('sites.promopages.col.enabled'), 'enabled', $sort, $dir, $path, $filters) ?>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $c): ?>
      <?php
        $url = PromopagesRepository::publicUrl((string)$c['code']);
      ?>
      <tr>
        <?= BulkActions::td($listId, (int)$c['id']) ?>
        <td class="muted">
          <?php if ($canEdit): ?>
            <a href="/sites/promopages/<?= (int)$c['id'] ?>/edit"><?= (int)$c['id'] ?></a>
          <?php else: ?>
            <?= (int)$c['id'] ?>
          <?php endif; ?>
        </td>
        <td>
          <code><?= e((string)$c['code']) ?></code>
          <?php if ($url !== ''): ?>
            <a class="muted" href="<?= e($url) ?>" target="_blank" rel="noopener">↗</a>
          <?php endif; ?>
        </td>
        <td><?= e((string)$c['descr']) ?></td>
        <td class="muted"><?= (int)$c['id_parent'] > 0 ? '#' . (int)$c['id_parent'] : '—' ?></td>
        <td><?= !empty($c['enabled']) ? te('core.common.yes') : te('core.common.no') ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?>
      <tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('sites.promopages.empty') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?= BulkActions::endForm($listId) ?>

<?= $pager ?>
