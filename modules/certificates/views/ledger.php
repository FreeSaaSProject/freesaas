<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var string $q */
/** @var int $idCert */
/** @var int $idEvent */
/** @var int $idOrder */
/** @var bool $canUndo */
/** @var array<string,mixed>|null $flash */
/** @var string $sort */
/** @var string $dir */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */
$path = '/certificates/ledger';
$showAll = !empty($showAll);
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, $showAll);
?>
<?php if (!empty($flash['message'])): ?>
  <div class="flash flash-<?= e((string)($flash['type'] ?? 'ok')) ?>"><?= e((string)$flash['message']) ?></div>
<?php endif; ?>

<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('certificates.list.search_placeholder') ?>" data-filter-debounce>
  <label><?= te('certificates.ledger.col_cert') ?>
    <input type="number" min="0" name="id_cert" value="<?= $idCert > 0 ? (int)$idCert : '' ?>">
  </label>
  <label><?= te('certificates.ledger.col_event') ?>
    <input type="number" min="0" name="id_event" value="<?= $idEvent > 0 ? (int)$idEvent : '' ?>">
  </label>
  <label><?= te('certificates.ledger.col_order') ?>
    <input type="number" min="0" name="id_order" value="<?= $idOrder > 0 ? (int)$idOrder : '' ?>">
  </label>
</form>

<?= $pager ?>

<div class="table-wrap">
  <table class="data">
    <thead>
      <tr>
        <?= ListQuery::sortTh('ID', 'id', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('certificates.ledger.col_dt'), 'dt_create', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('certificates.ledger.col_cert'), 'id_cert', $sort, $dir, $path, $filters) ?>
        <th><?= te('certificates.ledger.col_dir') ?></th>
        <?= ListQuery::sortTh(t('certificates.ledger.col_amount'), 'amount', $sort, $dir, $path, $filters) ?>
        <th><?= te('certificates.ledger.col_reason') ?></th>
        <th><?= te('certificates.ledger.col_event') ?></th>
        <th><?= te('certificates.ledger.col_order') ?></th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $row): ?>
      <tr>
        <td class="muted"><?= (int)$row['id'] ?></td>
        <td><?= (int)$row['dt_create'] > 0 ? e(I18n::formatDate((int)$row['dt_create'], 'datetime')) : '—' ?></td>
        <td><a href="/certificates/<?= (int)$row['id_cert'] ?>/edit">#<?= (int)$row['id_cert'] ?></a></td>
        <td><?= (int)$row['direction'] > 0 ? te('certificates.ledger.credit') : te('certificates.ledger.debit') ?></td>
        <td><?= e(number_format((float)$row['amount'], 2, '.', ' ')) ?></td>
        <td><?= e((string)$row['reason']) ?></td>
        <td><?= (int)$row['id_event'] > 0 ? (int)$row['id_event'] : '—' ?></td>
        <td><?= (int)$row['id_order'] > 0 ? (int)$row['id_order'] : '—' ?></td>
        <td>
          <?php if ($canUndo && (int)$row['direction'] < 0 && (string)$row['reason'] === 'spend'): ?>
            <form method="post" class="inline">
              <input type="hidden" name="undo_id" value="<?= (int)$row['id'] ?>">
              <?php if ($idCert > 0): ?><input type="hidden" name="id_cert" value="<?= (int)$idCert ?>"><?php endif; ?>
              <button type="submit" class="btn-secondary"><?= te('certificates.ledger.undo_btn') ?></button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?>
      <tr><td colspan="9" class="muted"><?= te('certificates.ledger.empty') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?= $pager ?>
