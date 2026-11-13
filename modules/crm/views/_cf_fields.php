<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $cfDefs */
/** @var array<int,string> $cfValues */
foreach ($cfDefs as $f):
    $fid = (int)$f['id'];
    $type = (string)$f['field_type'];
    $val = (string)($cfValues[$fid] ?? '');
    $name = 'cf[' . $fid . ']';
?>
  <?php if ($type === 'bool'): ?>
    <label class="check">
      <input type="hidden" name="<?= e($name) ?>" value="0">
      <input type="checkbox" name="<?= e($name) ?>" value="1"<?= $val === '1' ? ' checked' : '' ?>>
      <?= e((string)$f['name']) ?>
    </label>
  <?php elseif ($type === 'enum'): $opts = CrmCustomFieldsRepository::enumOptions($f); ?>
    <label><?= e((string)$f['name']) ?>
      <select name="<?= e($name) ?>">
        <option value="">—</option>
        <?php foreach ($opts as $ov => $ol): ?>
          <option value="<?= e((string)$ov) ?>"<?= $val === (string)$ov ? ' selected' : '' ?>><?= e((string)$ol) ?></option>
        <?php endforeach; ?>
        <?php if ($val !== '' && !isset($opts[$val])): ?>
          <option value="<?= e($val) ?>" selected><?= e($val) ?></option>
        <?php endif; ?>
      </select>
    </label>
  <?php elseif ($type === 'text'): ?>
    <label class="crm-span-2"><?= e((string)$f['name']) ?>
      <textarea name="<?= e($name) ?>" rows="3"><?= e($val) ?></textarea>
    </label>
  <?php elseif ($type === 'number'): ?>
    <label><?= e((string)$f['name']) ?>
      <input name="<?= e($name) ?>" type="number" step="any" value="<?= e($val) ?>">
    </label>
  <?php else: ?>
    <label><?= e((string)$f['name']) ?>
      <input name="<?= e($name) ?>" value="<?= e($val) ?>">
    </label>
  <?php endif; ?>
<?php endforeach; ?>
