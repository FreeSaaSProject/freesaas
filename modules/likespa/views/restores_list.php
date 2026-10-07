<?php
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var string $q */
/** @var bool $canEdit */
/** @var bool $canSuper */
/** @var string $sort */
/** @var string $dir */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */
/** @var array|null $bulkFlash */
$path = '/likespa/restores';
$listId = 'likespa_restores';
$showAll = !empty($showAll);
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, $showAll);
$colSpan = 8 + BulkActions::colSpanExtra($listId);
$actionUrl = ListQuery::url($path, $filters, []);
$fmtDt = static function ($ts): string {
    $ts = (int)$ts;
    return $ts > 0 ? date('d.m.Y H:i', $ts) : '—';
};
?>
<section class="page-head">
  <?php if ($canEdit): ?>
  <div class="page-head-row">
    <div></div>
    <div class="page-actions">
      <a class="btn-primary" href="/likespa/restores/new"><?= te('core.common.add') ?></a>
    </div>
  </div>
  <?php endif; ?>
</section>

<?php if (!empty($bulkFlash)): ?>
  <?= BulkActions::flashHtml($bulkFlash) ?>
<?php endif; ?>

<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('likespa.restores.list.search_placeholder') ?>" data-filter-debounce>
</form>

<?= $pager ?>

<?= BulkActions::beginForm($listId, $actionUrl) ?>
<?= BulkActions::bar($listId) ?>
<div class="table-wrap">
  <table class="data">
    <thead>
      <tr>
        <?= BulkActions::th($listId) ?>
        <?= ListQuery::sortTh(t('likespa.restores.col.id'), 'id', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('likespa.restores.col.created'), 'created', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('likespa.restores.col.procedure'), 'procedure', $sort, $dir, $path, $filters) ?>
        <th><?= te('likespa.restores.col.client') ?></th>
        <th><?= te('likespa.restores.col.operator') ?></th>
        <th><?= te('likespa.restores.col.admin') ?></th>
        <th><?= te('likespa.restores.col.paid') ?></th>
        <?= ListQuery::sortTh(t('likespa.restores.col.summ'), 'summ', $sort, $dir, $path, $filters) ?>
      </tr>
    </thead>
    <tbody>
    <?php if ($items === []): ?>
      <tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('likespa.restores.list.empty') ?></td></tr>
    <?php else: ?>
      <?php foreach ($items as $r): ?>
        <?php
          $rid = (int)$r['id'];
          $procLine = '#' . (int)$r['id_procedure']
              . ' ' . $fmtDt($r['procedure_dtstart'] ?? 0)
              . ' ' . trim((string)($r['product_name'] ?? ''));
          if (trim((string)($r['reason'] ?? '')) !== '') {
              $procLine .= ' — ' . (string)$r['reason'];
          }
          $dates = '<strong>' . e($fmtDt($r['dt_create'] ?? 0)) . '</strong> ' . e((string)($r['creator_name'] ?? ''));
          if ((int)($r['dt_review'] ?? 0) > 0) {
              $dates .= '<br>' . e($fmtDt($r['dt_review'])) . ' ' . e((string)($r['reviewer_name'] ?? ''));
          }
        ?>
        <tr>
          <?= BulkActions::td($listId, $rid) ?>
          <td class="muted">
            <?php if ($canEdit): ?>
              <a href="/likespa/restores/<?= $rid ?>/edit"><?= $rid ?></a>
            <?php else: ?>
              <?= $rid ?>
            <?php endif; ?>
          </td>
          <td><?= $dates ?></td>
          <td><?= e($procLine) ?></td>
          <td><?= e((string)($r['client_name'] ?? '')) ?></td>
          <td><?= e((string)($r['operator_name'] ?? '')) ?></td>
          <td><?= e((string)($r['admin_name'] ?? '')) ?></td>
          <td><?= e((string)($r['paid_name'] ?? '')) ?></td>
          <td><?= e(number_format((float)($r['summ'] ?? 0), 2, '.', ' ')) ?></td>
        </tr>
      <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?= BulkActions::endForm($listId) ?>

<?= $pager ?>
