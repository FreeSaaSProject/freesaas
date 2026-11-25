<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var array<int,string> $typeOptions */
/** @var list<array{label:string,options:array<int,string>}> $fundOptions */
/** @var bool $canEdit */
/** @var string $error */
?>
<section class="page-head">
  <div class="page-head-row">
    <div></div>
    <div class="page-actions">
      <a class="btn-secondary" href="/funds/rules"><?= te('funds.menu.rules') ?></a>
      <a class="btn-secondary" href="/funds/statement"><?= te('funds.menu.statement') ?></a>
    </div>
  </div>
</section>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>
<p class="muted"><?= te('funds.typemap.hint') ?></p>

<div class="table-wrap"><table class="data funds-table">
<thead><tr>
  <th><?= te('funds.typemap.col.type') ?></th>
  <th><?= te('funds.typemap.col.fund') ?></th>
  <th><?= te('funds.rules.col.enabled') ?></th>
  <?php if ($canEdit): ?><th></th><?php endif; ?>
</tr></thead>
<tbody>
<?php foreach ($items as $r): ?>
<tr class="<?= empty($r['enabled']) ? 'muted' : '' ?>">
  <td><?= e((string)$r['type_name']) ?></td>
  <td><a href="/funds/<?= (int)$r['fund_id'] ?>"><?= e((string)$r['fund_name']) ?></a></td>
  <td><?= !empty($r['enabled']) ? te('core.common.yes') : te('core.common.no') ?></td>
  <?php if ($canEdit): ?>
  <td>
    <form method="post" style="display:inline" onsubmit="return confirm(<?= tj('core.entity.confirm_delete') ?>)">
      <input type="hidden" name="map_action" value="delete">
      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
      <button class="btn-danger" type="submit"><?= te('core.common.delete') ?></button>
    </form>
  </td>
  <?php endif; ?>
</tr>
<?php endforeach; ?>
<?php if (!$items): ?><tr><td colspan="4" class="muted"><?= te('funds.typemap.empty') ?></td></tr><?php endif; ?>
</tbody></table></div>

<?php if ($canEdit): ?>
<section class="funds-dps-section">
  <h2><?= te('funds.typemap.add') ?></h2>
  <form method="post" class="form-grid">
    <input type="hidden" name="map_action" value="save">
    <label><?= te('funds.typemap.col.type') ?>
      <select name="type_id" required>
        <option value="0">—</option>
        <?php foreach ($typeOptions as $tid => $tname): ?>
          <option value="<?= (int)$tid ?>"><?= e($tname) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= te('funds.typemap.col.fund') ?>
      <select name="fund_id" required>
        <option value="0">—</option>
        <?php foreach ($fundOptions as $g): ?>
          <optgroup label="<?= e($g['label']) ?>">
            <?php foreach ($g['options'] as $fid => $fname): ?>
              <option value="<?= (int)$fid ?>"><?= e($fname) ?></option>
            <?php endforeach; ?>
          </optgroup>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="check"><input type="checkbox" name="enabled" value="1" checked> <?= te('funds.rules.col.enabled') ?></label>
    <div class="page-actions"><button class="btn-primary" type="submit"><?= te('core.common.save') ?></button></div>
  </form>
</section>
<section class="funds-dps-section">
  <h2><?= te('funds.backfill.title') ?></h2>
  <p class="muted"><?= te('funds.backfill.hint') ?></p>
  <form method="post" class="form-grid">
    <input type="hidden" name="map_action" value="backfill">
    <label><?= te('funds.backfill.from') ?> <input type="date" name="from"></label>
    <label><?= te('funds.backfill.to') ?> <input type="date" name="to"></label>
    <label class="check"><input type="checkbox" name="dry_run" value="1"> <?= te('funds.rules.run.dry_run') ?></label>
    <div class="page-actions"><button class="btn-secondary" type="submit"><?= te('funds.backfill.button') ?></button></div>
  </form>
</section>
<?php endif; ?>
