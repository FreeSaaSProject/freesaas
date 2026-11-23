<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var string $q */
/** @var string $enabled */
/** @var bool $canEdit */
$path = '/acquisition/sources';
?>
<section class="page-head">
  <div class="page-head-row">
    <div></div>
    <div class="page-actions">
      <?php if ($canEdit): ?>
        <a class="btn-primary" href="/acquisition/sources/new"><?= te('acquisition.sources.new') ?></a>
      <?php endif; ?>
    </div>
  </div>
</section>

<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('acquisition.sources.col.name') ?>" data-filter-debounce>
  <select name="enabled">
    <option value=""><?= te('acquisition.sources.col.enabled') ?></option>
    <option value="1"<?= $enabled === '1' ? ' selected' : '' ?>><?= te('core.common.yes') ?></option>
    <option value="0"<?= $enabled === '0' ? ' selected' : '' ?>><?= te('core.common.no') ?></option>
  </select>
</form>

<div class="table-wrap">
  <table class="data">
    <thead>
      <tr>
        <th>ID</th>
        <th><?= te('acquisition.sources.col.name') ?></th>
        <th><?= te('acquisition.sources.col.enabled') ?></th>
        <th><?= te('acquisition.sources.col.src_enabled') ?></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $s): ?>
      <tr>
        <td class="muted">
          <?php if ($canEdit): ?>
            <a href="/acquisition/sources/<?= (int)$s['id'] ?>/edit"><?= (int)$s['id'] ?></a>
          <?php else: ?>
            <?= (int)$s['id'] ?>
          <?php endif; ?>
        </td>
        <td><?= e((string)$s['name']) ?></td>
        <td><?= !empty($s['enabled']) ? te('core.common.yes') : te('core.common.no') ?></td>
        <td><?= !empty($s['src_enabled']) ? te('core.common.yes') : te('core.common.no') ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$items): ?>
      <tr><td colspan="4" class="muted"><?= te('core.common.empty') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
