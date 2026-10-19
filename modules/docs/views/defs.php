<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $defs */
/** @var array<int,int> $versionNums */
?>
<section class="page-head">
  <div class="page-head-row">
    <div></div>
    <div class="page-actions">
      <a class="btn-secondary" href="/documents/defs/import"><?= te('docs.import.title') ?></a>
      <a class="btn-primary" href="/documents/defs/new"><?= te('docs.defs.new') ?></a>
    </div>
  </div>
</section>

<div class="table-wrap">
  <table class="data">
    <thead>
      <tr>
        <th>ID</th>
        <th><?= te('docs.def.name') ?></th>
        <th><?= te('docs.def.code') ?></th>
        <th><?= te('docs.field.kind') ?></th>
        <th><?= te('docs.def.scope') ?></th>
        <th><?= te('docs.def.trigger_mode') ?></th>
        <th><?= te('docs.def.version') ?></th>
        <th><?= te('docs.def.enabled') ?></th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($defs as $d): ?>
      <tr<?= empty($d['enabled']) ? ' class="is-muted"' : '' ?>>
        <td class="muted"><?= (int)$d['id'] ?></td>
        <td><a href="/documents/defs/<?= (int)$d['id'] ?>/edit"><?= e((string)$d['name']) ?></a></td>
        <td><code><?= e((string)$d['code']) ?></code></td>
        <td><?= e(DocsDefsRepository::kindLabel((string)$d['kind'])) ?></td>
        <td><?= te('docs.scope.' . $d['scope']) ?></td>
        <td><?= te('docs.trigger.' . $d['trigger_mode']) ?></td>
        <td>v<?= (int)($versionNums[(int)$d['id']] ?? 0) ?></td>
        <td><?= !empty($d['enabled']) ? te('docs.value.yes') : te('docs.value.no') ?></td>
        <td class="docs-def-actions">
          <a href="/documents/defs/<?= (int)$d['id'] ?>/sample" target="_blank" rel="noopener"><?= te('docs.sample.open') ?></a>
          · <a href="/documents/defs/<?= (int)$d['id'] ?>/export"><?= te('docs.def.export') ?></a>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$defs): ?>
      <tr><td colspan="9" class="muted"><?= te('docs.defs.empty') ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
