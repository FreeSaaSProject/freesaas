<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var string $kind */
/** @var int $clientId */
/** @var int $eventId */
/** @var string $status */
/** @var list<string> $visibleKinds */
/** @var list<array<string,mixed>> $defs */
/** @var bool $canEdit */
/** @var bool $canPrint */
/** @var string $sort */
/** @var string $dir */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */
/** @var array|null $bulkFlash */
$path = '/documents';
$listId = 'docs_instances';
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, !empty($showAll));
$colSpan = 7 + BulkActions::colSpanExtra($listId);
$actionUrl = ListQuery::url($path, $filters, []);
?>
<section class="page-head">
  <?php if ($canEdit && $defs !== []): ?>
  <div class="page-head-row">
    <div></div>
    <form class="page-actions" method="get" action="/documents/new">
      <?php if ($clientId > 0): ?><input type="hidden" name="client" value="<?= (int)$clientId ?>"><?php endif; ?>
      <?php if ($eventId > 0): ?><input type="hidden" name="event" value="<?= (int)$eventId ?>"><?php endif; ?>
      <select name="def" required>
        <?php foreach ($defs as $d): ?>
          <option value="<?= (int)$d['id'] ?>"><?= e((string)$d['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn-primary" type="submit"><?= te('docs.list.new') ?></button>
    </form>
  </div>
  <?php endif; ?>
</section>

<?php if (!empty($bulkFlash)): ?>
  <?= BulkActions::flashHtml($bulkFlash) ?>
<?php endif; ?>

<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <label><?= te('docs.field.kind') ?>
    <select name="kind">
      <option value=""><?= te('docs.list.any') ?></option>
      <?php foreach ($visibleKinds as $k): ?>
        <option value="<?= e($k) ?>"<?= $kind === $k ? ' selected' : '' ?>><?= e(DocsDefsRepository::kindLabel($k)) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('docs.field.status') ?>
    <select name="status">
      <option value=""><?= te('docs.list.any') ?></option>
      <?php foreach (DocsInstancesRepository::STATUSES as $s): ?>
        <option value="<?= e($s) ?>"<?= $status === $s ? ' selected' : '' ?>><?= te('docs.status.' . $s) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('docs.field.client') ?>
    <input type="number" min="0" name="client" value="<?= $clientId > 0 ? (int)$clientId : '' ?>">
  </label>
  <label><?= te('docs.field.event') ?>
    <input type="number" min="0" name="event" value="<?= $eventId > 0 ? (int)$eventId : '' ?>">
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
        <th><?= te('docs.field.def') ?></th>
        <?= ListQuery::sortTh(t('docs.field.status'), 'status', $sort, $dir, $path, $filters) ?>
        <th><?= te('docs.field.client') ?></th>
        <th><?= te('docs.field.event') ?></th>
        <?= ListQuery::sortTh(t('docs.field.dt_create'), 'dt_create', $sort, $dir, $path, $filters) ?>
        <?= ListQuery::sortTh(t('docs.field.dt_print'), 'dt_print', $sort, $dir, $path, $filters) ?>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $row): ?>
      <tr<?= $row['status'] === 'void' ? ' class="is-muted"' : '' ?>>
        <?= BulkActions::td($listId, (int)$row['id']) ?>
        <td class="muted"><a href="/documents/<?= (int)$row['id'] ?>/edit"><?= (int)$row['id'] ?></a></td>
        <td>
          <?= e((string)$row['def_name']) ?>
          <span class="muted docs-kind-chip"><?= e(DocsDefsRepository::kindLabel((string)$row['kind'])) ?></span>
        </td>
        <td><span class="docs-status docs-status--<?= e((string)$row['status']) ?>"><?= te('docs.status.' . $row['status']) ?></span></td>
        <td>
          <?php if ((int)$row['id_client'] > 0): ?>
            <a href="/clients/<?= (int)$row['id_client'] ?>"><?= e($row['client_name'] !== '' ? (string)$row['client_name'] : ('#' . (int)$row['id_client'])) ?></a>
          <?php else: ?>—<?php endif; ?>
        </td>
        <td>
          <?php if ((int)$row['id_event'] > 0): ?>
            <a href="/calendar/events/<?= (int)$row['id_event'] ?>/edit">#<?= (int)$row['id_event'] ?></a>
          <?php else: ?>—<?php endif; ?>
        </td>
        <td><?= e(I18n::formatDate((int)$row['dt_create'], 'date')) ?></td>
        <td>
          <?php if ((int)$row['dt_print'] > 0): ?>
            <?php if ($canPrint): ?>
              <a href="/documents/<?= (int)$row['id'] ?>/print"><?= e(I18n::formatDate((int)$row['dt_print'], 'date')) ?></a>
            <?php else: ?>
              <?= e(I18n::formatDate((int)$row['dt_print'], 'date')) ?>
            <?php endif; ?>
          <?php else: ?>—<?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?>
      <tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('docs.list.empty') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?= BulkActions::endForm($listId) ?>

<?= $pager ?>
