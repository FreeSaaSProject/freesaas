<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var string $scope_table */
/** @var int $scope_id */
/** @var string $ret */
$items = is_array($items ?? null) ? $items : [];
$ret = (string)($ret ?? '');
$byKind = [];
foreach ($items as $it) {
    $byKind[(string)$it['kind']] = $it;
}
?>
<form method="post" action="/media/requirements" class="form-grid form-wide media-req-form">
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="scope_table" value="<?= e((string)$scope_table) ?>">
  <input type="hidden" name="scope_id" value="<?= (int)$scope_id ?>">
  <?php if ($ret !== ''): ?><input type="hidden" name="ret" value="<?= e($ret) ?>"><?php endif; ?>
  <p class="muted"><?= te('media.req.hint') ?></p>
  <table class="data-table media-req-table">
    <thead>
      <tr>
        <th><?= te('media.req.enabled') ?></th>
        <th><?= te('media.col.kind') ?></th>
        <th><?= te('media.req.min') ?></th>
        <th><?= te('media.req.label') ?></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach (MediaConfig::kinds() as $k):
          $row = $byKind[$k] ?? null;
          $on = $row !== null && !empty($row['enabled']);
          $min = $row !== null ? max(1, (int)$row['min_count']) : 1;
          $label = $row !== null ? (string)($row['label'] ?? '') : '';
      ?>
        <tr>
          <td>
            <input type="hidden" name="req[<?= e($k) ?>][on]" value="0">
            <label class="check">
              <input type="checkbox" name="req[<?= e($k) ?>][on]" value="1"<?= $on ? ' checked' : '' ?>>
            </label>
          </td>
          <td><?= te('media.kind.' . $k) ?></td>
          <td>
            <input type="number" min="1" max="20" name="req[<?= e($k) ?>][min]" value="<?= $min ?>" style="width:4.5rem">
          </td>
          <td>
            <input type="text" name="req[<?= e($k) ?>][label]" maxlength="512"
                   value="<?= e($label) ?>"
                   placeholder="<?= e(t('media.req.label_ph')) ?>">
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('media.req.save') ?></button>
  </div>
</form>
