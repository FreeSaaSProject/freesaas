<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var string $q */
/** @var bool $canEdit */
/** @var string $sort */
/** @var string $dir */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */
/** @var array|null $bulkFlash */
$path = '/client-tags';
$listId = 'client_tags';
$showAll = !empty($showAll);
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, $showAll);
$colSpan = 4 + BulkActions::colSpanExtra($listId);
$actionUrl = ListQuery::url($path, $filters, []);
?>
<section class="page-head">
  <div class="page-head-row">
    <div>
      <p class="lede"><?= t('clients.tag.lede', ['total' => (int)$total]) ?></p>
    </div>
    <?php if ($canEdit): ?>
    <div class="page-actions">
      <a class="btn-primary" href="/client-tags/new"><?= te('core.common.add') ?></a>
    </div>
    <?php endif; ?>
  </div>
</section>

<?php if (!empty($bulkFlash)): ?>
  <?= BulkActions::flashHtml($bulkFlash) ?>
<?php endif; ?>

<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('clients.tag.search_placeholder') ?>" data-filter-debounce>
</form>

<?= $pager ?>

<?= BulkActions::beginForm($listId, $actionUrl) ?>
<?= BulkActions::bar($listId) ?>
<div class="table-wrap">
  <table class="data">
    <thead>
      <tr>
        <?= BulkActions::th($listId) ?>
        <?= ListQuery::sortTh('ID', 'id', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('clients.tag.name'), 'name', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('clients.tag.created'), 'dt_create', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('clients.tag.enabled'), 'enabled', $sort, $dir, $path, $filters) ?>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $tag): ?>
      <tr<?= empty($tag['enabled']) ? ' class="is-disabled"' : '' ?>>
        <?= BulkActions::td($listId, (int)$tag['id']) ?>
        <td class="muted">
          <?php if ($canEdit): ?>
            <a href="/client-tags/<?= (int)$tag['id'] ?>/edit"><?= (int)$tag['id'] ?></a>
          <?php else: ?>
            <?= (int)$tag['id'] ?>
          <?php endif; ?>
        </td>
        <td>
          <?= e((string)$tag['name']) ?>
          <?php if (empty($tag['enabled'])): ?>
            <span class="pill off"><?= te('core.common.off') ?></span>
          <?php endif; ?>
        </td>
        <td class="muted">
          <?php
            $dt = (int)($tag['dt_create'] ?? 0);
            echo $dt > 0 ? e(I18n::formatDate($dt, 'datetime')) : '—';
          ?>
        </td>
        <td><?= !empty($tag['enabled']) ? te('core.common.yes') : te('core.common.no') ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?>
      <tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('clients.tag.empty') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?= BulkActions::endForm($listId) ?>

<?= $pager ?>
