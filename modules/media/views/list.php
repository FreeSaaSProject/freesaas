<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var array<string,mixed> $filters */
/** @var array<string,mixed>|null $bulkFlash */
/** @var string $listId */
/** @var int $total */
$filters = is_array($filters ?? null) ? $filters : [];
$listId = (string)($listId ?? 'media_attachments');
$colSpan = 7 + BulkActions::colSpanExtra($listId);
?>
<section class="page-head">
  <h1><?= te('media.list.title') ?></h1>
</section>
<?php if (!empty($bulkFlash) && is_array($bulkFlash)): ?>
<?= BulkActions::flashHtml($bulkFlash) ?>
<?php endif; ?>

<form method="get" class="filters form-grid" action="/media">
  <label><?= te('media.list.owner_table') ?>
    <select name="owner_table">
      <option value="">—</option>
      <?php foreach (MediaConfig::ownerTables() as $ot): ?>
        <option value="<?= e($ot) ?>"<?= ($filters['owner_table'] ?? '') === $ot ? ' selected' : '' ?>><?= e($ot) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('media.list.owner_id') ?>
    <input type="number" min="0" name="owner_id" value="<?= e((string)((int)($filters['owner_id'] ?? 0) ?: '')) ?>">
  </label>
  <label><?= te('media.list.kind') ?>
    <select name="kind">
      <option value="">—</option>
      <?php foreach (MediaConfig::kinds() as $k): ?>
        <option value="<?= e($k) ?>"<?= ($filters['kind'] ?? '') === $k ? ' selected' : '' ?>><?= te('media.kind.' . $k) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('media.list.id_user') ?>
    <input type="number" min="0" name="id_user" value="<?= e((string)((int)($filters['id_user'] ?? 0) ?: '')) ?>">
  </label>
  <label><?= te('media.list.q') ?>
    <input name="q" value="<?= e((string)($filters['q'] ?? '')) ?>">
  </label>
  <div class="page-actions">
    <button class="btn-secondary" type="submit"><?= te('media.list.apply') ?></button>
  </div>
</form>

<?= BulkActions::beginForm($listId, '/media') ?>
<?= BulkActions::bar($listId) ?>
  <table class="data-table">
    <thead>
      <tr>
        <?= BulkActions::th($listId) ?>
        <th><?= te('media.col.id') ?></th>
        <th></th>
        <th><?= te('media.col.dt') ?></th>
        <th><?= te('media.col.owner') ?></th>
        <th><?= te('media.col.kind') ?></th>
        <th><?= te('media.col.name') ?></th>
        <th><?= te('media.col.bytes') ?></th>
      </tr>
    </thead>
    <tbody>
      <?php if ($items === []): ?>
        <tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('media.list.empty') ?></td></tr>
      <?php else: ?>
        <?php foreach ($items as $it): ?>
          <tr>
            <?= BulkActions::td($listId, (int)$it['id']) ?>
            <td><?= (int)$it['id'] ?></td>
            <td>
              <a href="/media/file/<?= (int)$it['id'] ?>" target="_blank" rel="noopener">
                <img src="/media/thumb/<?= (int)$it['id'] ?>" alt="" width="48" height="48" style="object-fit:cover;border-radius:4px">
              </a>
            </td>
            <td><?= e(date('Y-m-d H:i', (int)($it['dt_create'] ?? 0))) ?></td>
            <td>
              <code><?= e((string)$it['owner_table']) ?></code>
              #<?= (int)$it['owner_id'] ?>
              <?php if ((string)$it['owner_table'] === 'base_procedures'): ?>
                · <a href="/procedures/<?= (int)$it['owner_id'] ?>/edit">→</a>
              <?php endif; ?>
            </td>
            <td><?= te('media.kind.' . (string)$it['kind']) ?></td>
            <td><?= e((string)($it['original_name'] ?? '')) ?></td>
            <td><?= (int)($it['bytes'] ?? 0) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
<?= BulkActions::endForm($listId) ?>
<p class="muted"><?= (int)$total ?></p>
