<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var array<string,mixed> $filters */
/** @var callable $scopeEditHref */
$filters = is_array($filters ?? null) ? $filters : [];
$scopeEditHref = is_callable($scopeEditHref ?? null) ? $scopeEditHref : static fn (string $t, int $id): string => '';
?>
<section class="page-head">
  <h1><?= te('media.req.journal_title') ?></h1>
  <p class="muted"><?= te('media.req.journal_hint') ?></p>
</section>
<?php if (($message ?? '') !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>

<form method="get" class="filters form-grid" action="/media/requirements">
  <label><?= te('media.req.scope_table') ?>
    <select name="scope_table">
      <option value="">—</option>
      <?php foreach (MediaConfig::scopeTables() as $st): ?>
        <option value="<?= e($st) ?>"<?= ($filters['scope_table'] ?? '') === $st ? ' selected' : '' ?>><?= e($st) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('media.req.scope_id') ?>
    <input type="number" min="0" name="scope_id" value="<?= e((string)((int)($filters['scope_id'] ?? 0) ?: '')) ?>">
  </label>
  <label><?= te('media.col.kind') ?>
    <select name="kind">
      <option value="">—</option>
      <?php foreach (MediaConfig::kinds() as $k): ?>
        <option value="<?= e($k) ?>"<?= ($filters['kind'] ?? '') === $k ? ' selected' : '' ?>><?= te('media.kind.' . $k) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('media.req.enabled') ?>
    <select name="enabled">
      <option value="">—</option>
      <option value="1"<?= ($filters['enabled'] ?? '') === '1' ? ' selected' : '' ?>><?= te('media.req.enabled_yes') ?></option>
      <option value="0"<?= ($filters['enabled'] ?? '') === '0' ? ' selected' : '' ?>><?= te('media.req.enabled_no') ?></option>
    </select>
  </label>
  <label><?= te('media.list.q') ?>
    <input name="q" value="<?= e((string)($filters['q'] ?? '')) ?>">
  </label>
  <div class="page-actions">
    <button class="btn-secondary" type="submit"><?= te('media.list.apply') ?></button>
  </div>
</form>

<table class="data-table">
  <thead>
    <tr>
      <th><?= te('media.col.id') ?></th>
      <th><?= te('media.req.scope_table') ?></th>
      <th><?= te('media.req.scope_id') ?></th>
      <th><?= te('media.col.kind') ?></th>
      <th><?= te('media.req.min') ?></th>
      <th><?= te('media.req.label') ?></th>
      <th><?= te('media.req.enabled') ?></th>
      <th></th>
    </tr>
  </thead>
  <tbody>
    <?php if ($items === []): ?>
      <tr><td colspan="8" class="muted"><?= te('media.req.journal_empty') ?></td></tr>
    <?php else: ?>
      <?php foreach ($items as $it):
          $href = $scopeEditHref((string)$it['scope_table'], (int)$it['scope_id']);
      ?>
        <tr>
          <td><?= (int)$it['id'] ?></td>
          <td><code><?= e((string)$it['scope_table']) ?></code></td>
          <td>
            <?php if ($href !== ''): ?>
              <a href="<?= e($href) ?>">#<?= (int)$it['scope_id'] ?></a>
            <?php else: ?>
              #<?= (int)$it['scope_id'] ?>
            <?php endif; ?>
          </td>
          <td><?= te('media.kind.' . (string)$it['kind']) ?></td>
          <td><?= (int)$it['min_count'] ?></td>
          <td><?= e((string)($it['label'] ?? '')) ?></td>
          <td><?= !empty($it['enabled']) ? te('media.req.enabled_yes') : te('media.req.enabled_no') ?></td>
          <td>
            <?php if ($href !== ''): ?>
              <a class="btn-secondary btn-sm" href="<?= e($href) ?>"><?= te('media.req.edit_scope') ?></a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    <?php endif; ?>
  </tbody>
</table>
