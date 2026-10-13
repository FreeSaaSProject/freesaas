<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var string $q */
/** @var int $idBuyer */
/** @var int $idHolder */
/** @var int $kind */
/** @var int|null $expired */
/** @var int $moneyLeft */
/** @var bool $canEdit */
/** @var string $sort */
/** @var string $dir */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */
/** @var array|null $bulkFlash */
$path = '/certificates';
$listId = 'certificates';
$showAll = !empty($showAll);
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, $showAll);
$colSpan = 9 + BulkActions::colSpanExtra($listId);
$actionUrl = ListQuery::url($path, $filters, []);
?>
<section class="page-head">
  <?php if ($canEdit): ?>
  <div class="page-head-row">
    <div></div>
    <div class="page-actions">
      <a class="btn-primary" href="/certificates/new"><?= te('certificates.list.new') ?></a>
    </div>
  </div>
  <?php endif; ?>
</section>

<?php if (!empty($bulkFlash)): ?>
  <?= BulkActions::flashHtml($bulkFlash) ?>
<?php endif; ?>

<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('certificates.list.search_placeholder') ?>" data-filter-debounce>
  <label><?= te('certificates.field.id_buyer') ?>
    <input type="number" min="0" name="id_buyer" value="<?= $idBuyer > 0 ? (int)$idBuyer : '' ?>">
  </label>
  <label><?= te('certificates.field.id_holder') ?>
    <input type="number" min="0" name="id_holder" value="<?= $idHolder > 0 ? (int)$idHolder : '' ?>">
  </label>
  <label><?= te('certificates.list.filter_kind') ?>
    <select name="kind">
      <option value="0"><?= te('certificates.list.expired_any') ?></option>
      <option value="1"<?= $kind === 1 ? ' selected' : '' ?>><?= te('certificates.kind.money') ?></option>
      <option value="2"<?= $kind === 2 ? ' selected' : '' ?>><?= te('certificates.kind.abon') ?></option>
    </select>
  </label>
  <label><?= te('certificates.list.filter_expired') ?>
    <select name="expired">
      <option value=""><?= te('certificates.list.expired_any') ?></option>
      <option value="2"<?= $expired === 2 ? ' selected' : '' ?>><?= te('certificates.list.expired_no') ?></option>
      <option value="1"<?= $expired === 1 ? ' selected' : '' ?>><?= te('certificates.list.expired_yes') ?></option>
      <option value="3"<?= $expired === 3 ? ' selected' : '' ?>><?= te('certificates.list.expired_overdue') ?></option>
    </select>
  </label>
  <label class="check">
    <input type="checkbox" name="money_left" value="1"<?= $moneyLeft ? ' checked' : '' ?>>
    <?= te('certificates.list.money_left') ?>
  </label>
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
        <?= ListQuery::sortTh(t('certificates.list.col_code'), 'code', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('certificates.list.col_kind'), 'kind', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('certificates.list.col_face'), 'face_value', $sort, $dir, $path, $filters) ?>
        <th><?= te('certificates.list.col_used') ?></th>
        <th><?= te('certificates.list.col_balance') ?></th>
        <th><?= te('certificates.list.col_holder') ?></th>
        <?= ListQuery::sortTh(t('certificates.list.col_expire'), 'dt_use_expire', $sort, $dir, $path, $filters) ?>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $row): ?>
      <tr<?= !empty($row['expired']) ? ' class="is-muted"' : '' ?>>
        <?= BulkActions::td($listId, (int)$row['id']) ?>
        <td class="muted">
          <?php if ($canEdit): ?>
            <a href="/certificates/<?= (int)$row['id'] ?>/edit"><?= (int)$row['id'] ?></a>
          <?php else: ?>
            <?= (int)$row['id'] ?>
          <?php endif; ?>
        </td>
        <td><code><?= e((string)$row['code']) ?></code></td>
        <td><?= e(CertificatesRepository::kindLabel((int)$row['kind'])) ?></td>
        <td><?= e(number_format((float)$row['face_value'], 0, '.', ' ')) ?></td>
        <td><?= e(number_format((float)$row['used_amount'], 0, '.', ' ')) ?></td>
        <td><?= e(number_format((float)$row['balance'], 0, '.', ' ')) ?></td>
        <td>
          <?php if ((int)$row['id_holder'] > 0): ?>
            <a href="/clients/<?= (int)$row['id_holder'] ?>"><?= (int)$row['id_holder'] ?></a>
          <?php else: ?>
            —
          <?php endif; ?>
        </td>
        <td><?= (int)$row['dt_use_expire'] > 0 ? e(I18n::formatDate((int)$row['dt_use_expire'], 'date')) : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?>
      <tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('certificates.list.empty') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?= BulkActions::endForm($listId) ?>

<?= $pager ?>
