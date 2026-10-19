<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var string $q */
/** @var string $status */
/** @var array<string,string> $statusOptions */
/** @var int $idUser */
/** @var int $idPromocode */
/** @var string $freeTicket */
/** @var bool $canEdit */
/** @var string $sort */
/** @var string $dir */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */
/** @var array|null $bulkFlash */
$path = '/orders';
$listId = 'orders';
$showAll = !empty($showAll);
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, $showAll);
$listUrl = ListQuery::url($path, $filters);
$actionUrl = ListQuery::url($path, $filters, []);
$colSpan = 9 + BulkActions::colSpanExtra($listId);
?>
<section class="page-head">
  <div class="page-head-row">
    <div>
      <h1><?= te('orders.menu.list') ?></h1>
    </div>
  </div>
</section>

<?php if (!empty($bulkFlash)): ?>
  <?= BulkActions::flashHtml($bulkFlash) ?>
<?php endif; ?>

<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('orders.list.search_placeholder') ?>" data-filter-debounce>
  <select name="status" data-auto-submit aria-label="<?= te('orders.list.filter_status') ?>">
    <?php foreach ($statusOptions as $val => $labelKey): ?>
      <option value="<?= e($val) ?>"<?= $status === $val ? ' selected' : '' ?>><?= te($labelKey) ?></option>
    <?php endforeach; ?>
  </select>
  <input type="number" min="0" name="id_user" value="<?= $idUser > 0 ? (int)$idUser : '' ?>" placeholder="<?= te('orders.list.client_id') ?>" data-filter-debounce>
  <input type="number" min="0" name="id_promocode" value="<?= $idPromocode > 0 ? (int)$idPromocode : '' ?>" placeholder="<?= te('orders.list.promocode_id') ?>" data-filter-debounce>
  <select name="free_ticket" data-auto-submit aria-label="<?= te('orders.list.free_ticket') ?>">
    <option value=""<?= $freeTicket === '' ? ' selected' : '' ?>><?= te('orders.list.free_ticket') ?>: <?= te('core.common.all') ?></option>
    <option value="1"<?= $freeTicket === '1' ? ' selected' : '' ?>><?= te('core.common.yes') ?></option>
    <option value="0"<?= $freeTicket === '0' ? ' selected' : '' ?>><?= te('core.common.no') ?></option>
  </select>
</form>

<?= $pager ?>

<?= BulkActions::beginForm($listId, $actionUrl) ?>
<?= BulkActions::bar($listId) ?>
<div class="table-wrap">
  <table class="data">
    <thead>
      <tr>
        <?= BulkActions::th($listId) ?>
        <?= ListQuery::sortTh(t('orders.col.id'), 'id', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('orders.col.dt_event'), 'dt_event', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('orders.col.dt_save'), 'dt_save', $sort, $dir, $path, $filters) ?>
        <th><?= te('orders.col.client') ?></th>
        <th><?= te('orders.col.staff') ?></th>
        <?= ListQuery::sortTh(t('orders.col.status'), 'status', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('orders.col.summ_real'), 'summ_real', $sort, $dir, $path, $filters) ?>
        <th><?= te('orders.col.promo') ?></th>
        <th><?= te('orders.col.free_ticket') ?></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $o): ?>
      <?php
        $oid = (int)$o['id'];
        $viewHref = ListQuery::url('/orders/' . $oid, ['ret' => $listUrl]);
        $staffLabel = trim((string)($o['staff_name'] ?? ''));
        if ($staffLabel === '') {
            $staffLabel = (string)($o['staff_login'] ?? '');
        }
        $clientLabel = trim((string)($o['client_name'] ?? ''));
        if ($clientLabel === '' && (int)$o['id_user'] > 0) {
            $clientLabel = '#' . (int)$o['id_user'];
        }
      ?>
      <tr>
        <?= BulkActions::td($listId, $oid) ?>
        <td class="muted">
          <a href="<?= e($viewHref) ?>"><?= $oid ?></a>
          <?php if ((string)($o['uid'] ?? '') !== ''): ?>
            <div class="muted"><?= e((string)$o['uid']) ?></div>
          <?php endif; ?>
        </td>
        <td><?=
          ((int)($o['dt_event'] ?? 0) > 0)
            ? e(I18n::formatDate((int)$o['dt_event'], 'datetime'))
            : '—'
        ?></td>
        <td><?=
          ((int)($o['dt_save'] ?? 0) > 0)
            ? e(I18n::formatDate((int)$o['dt_save'], 'datetime'))
            : '—'
        ?></td>
        <td>
          <?php if (!empty($o['client_untied'])): ?>
            <div class="bad-text"><?= te('orders.view.client_untied') ?></div>
          <?php endif; ?>
          <?php if ((int)$o['id_user'] > 0): ?>
            <?php if (!empty($o['client_auto_linked'])): ?>
              <div class="bad-text"><strong><?= te('orders.view.client_auto_linked', ['id' => (int)$o['id_user']]) ?></strong></div>
            <?php endif; ?>
            <a href="/clients/<?= (int)$o['id_user'] ?>"><?= e($clientLabel) ?></a>
          <?php else: ?>
            <span class="muted"><?= te('orders.view.unauthorized') ?></span>
          <?php endif; ?>
          <?php if ((string)($o['phone'] ?? '') !== ''): ?>
            <div class="muted"><?= e((string)$o['phone']) ?></div>
          <?php endif; ?>
        </td>
        <td><?=
          (int)($o['id_cosmetolog'] ?? 0) > 0
            ? e($staffLabel !== '' ? $staffLabel : ('#' . (int)$o['id_cosmetolog']))
            : '—'
        ?></td>
        <td><?= e(OrdersRepository::statusLabel((string)($o['status'] ?? '0'))) ?></td>
        <td><?= e((string)($o['summ_real'] ?? '0')) ?></td>
        <td>
          <?php if ((int)($o['id_promocode'] ?? 0) > 0): ?>
            #<?= (int)$o['id_promocode'] ?>
            <?php if ((string)($o['text_promocode'] ?? '') !== ''): ?>
              <div class="muted"><?= e((string)$o['text_promocode']) ?></div>
            <?php endif; ?>
          <?php else: ?>
            —
          <?php endif; ?>
        </td>
        <td>
          <?php if (!empty($o['free_ticket'])): ?>
            <span class="pill off"><?= te('orders.col.free_ticket') ?></span>
          <?php else: ?>
            —
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?>
      <tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('orders.list.empty') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?= BulkActions::endForm($listId) ?>

<?= $pager ?>
