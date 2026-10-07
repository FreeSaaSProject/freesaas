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
$path = '/likespa/redirects';
$listId = 'likespa_redirects';
$showAll = !empty($showAll);
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, $showAll);
$colSpan = 5 + BulkActions::colSpanExtra($listId);
?>
<section class="page-head">
  <?php if ($canEdit): ?>
  <div class="page-head-row">
    <div></div>
    <div class="page-actions">
      <a class="btn-primary" href="/likespa/redirects/new"><?= te('core.common.add') ?></a>
    </div>
  </div>
  <?php endif; ?>
  <p class="lede"><?= te('likespa.redirects.lede') ?></p>
</section>

<?php if (!empty($bulkFlash)): ?>
  <?= BulkActions::flashHtml($bulkFlash) ?>
<?php endif; ?>

<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('likespa.redirects.search_placeholder') ?>" data-filter-debounce>
  <select name="enabled">
    <option value=""<?= $enabled === '' ? ' selected' : '' ?>><?= te('likespa.redirects.filter.all') ?></option>
    <option value="1"<?= $enabled === '1' ? ' selected' : '' ?>><?= te('likespa.redirects.filter.enabled') ?></option>
    <option value="0"<?= $enabled === '0' ? ' selected' : '' ?>><?= te('likespa.redirects.filter.disabled') ?></option>
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
        <?= ListQuery::sortTh(t('likespa.redirects.col.dt'), 'dt_create', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('likespa.redirects.col.url_old'), 'url_old', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('likespa.redirects.col.url_new'), 'url_new', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('likespa.redirects.col.enabled'), 'enabled', $sort, $dir, $path, $filters) ?>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $c): ?>
      <tr>
        <?= BulkActions::td($listId, (int)$c['id']) ?>
        <td class="muted">
          <?php if ($canEdit): ?>
            <a href="/likespa/redirects/<?= (int)$c['id'] ?>/edit"><?= (int)$c['id'] ?></a>
          <?php else: ?>
            <?= (int)$c['id'] ?>
          <?php endif; ?>
        </td>
        <td class="muted">
          <?php if ((int)$c['dt_create'] > 0): ?>
            <?= e(date('d.m.Y H:i', (int)$c['dt_create'])) ?>
          <?php else: ?>
            —
          <?php endif; ?>
        </td>
        <td><code><?= e((string)$c['url_old']) ?></code></td>
        <td><code><?= e((string)$c['url_new']) ?></code></td>
        <td><?= !empty($c['enabled']) ? te('core.common.yes') : te('core.common.no') ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?>
      <tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('likespa.redirects.empty') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?= BulkActions::endForm($listId) ?>

<?= $pager ?>
