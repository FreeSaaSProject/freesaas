<?php
declare(strict_types=1);
/**
 * Editable default phrases for visit-sheet fields (clinic or doctor template).
 *
 * @var array{sections:list<array<string,mixed>>} $schema
 * @var array<string,mixed> $fillFields
 * @var string $inputPrefix  e.g. fill[ana_obj]
 */
$fillFields = is_array($fillFields ?? null) ? $fillFields : [];
$prefix = (string)($inputPrefix ?? 'fill');
$fieldMap = DocsSchema::fieldMap($schema);
$any = false;
foreach ($schema['sections'] as $s) {
    foreach ((array)($s['fields'] ?? []) as $f) {
        $fid = (string)($f['id'] ?? '');
        if ($fid === '' || $fid === 'doctor_id' || $fid === 'talk_segments') {
            continue;
        }
        if (!in_array((string)($f['type'] ?? ''), DocsFillTemplates::FILLABLE_TYPES, true)) {
            continue;
        }
        $any = true;
        $name = $prefix . '[' . $fid . ']';
        $value = $fillFields[$fid] ?? null;
        ?>
        <?php if (($f['type'] ?? '') === 'checkbox'): ?>
          <label class="check">
            <input type="hidden" name="<?= e($name) ?>" value="">
            <input type="checkbox" name="<?= e($name) ?>" value="1"<?= (int)$value === 1 ? ' checked' : '' ?>>
            <?= e((string)$f['label']) ?>
          </label>
        <?php elseif (($f['type'] ?? '') === 'textarea'): ?>
          <label><?= e((string)$f['label']) ?>
            <textarea name="<?= e($name) ?>" rows="2"><?= e(is_scalar($value) ? (string)$value : '') ?></textarea>
          </label>
        <?php elseif (($f['type'] ?? '') === 'select'): ?>
          <label><?= e((string)$f['label']) ?>
            <select name="<?= e($name) ?>">
              <option value="">—</option>
              <?php foreach ((array)($f['options'] ?? []) as $o): ?>
                <option value="<?= e((string)$o['value']) ?>"<?= (string)$value === (string)$o['value'] ? ' selected' : '' ?>><?= e((string)$o['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        <?php elseif (($f['type'] ?? '') === 'multiselect'):
            $sel = is_array($value) ? array_map('strval', $value) : [];
            ?>
          <label><?= e((string)$f['label']) ?>
            <select name="<?= e($name) ?>[]" multiple size="<?= min(6, max(2, count((array)($f['options'] ?? [])))) ?>">
              <?php foreach ((array)($f['options'] ?? []) as $o): ?>
                <option value="<?= e((string)$o['value']) ?>"<?= in_array((string)$o['value'], $sel, true) ? ' selected' : '' ?>><?= e((string)$o['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        <?php elseif (($f['type'] ?? '') === 'date'): ?>
          <label><?= e((string)$f['label']) ?>
            <input type="date" name="<?= e($name) ?>" value="<?= e(is_scalar($value) ? (string)$value : '') ?>">
          </label>
        <?php elseif (($f['type'] ?? '') === 'number'): ?>
          <label><?= e((string)$f['label']) ?>
            <input type="number" step="any" name="<?= e($name) ?>" value="<?= e(is_scalar($value) ? (string)$value : '') ?>">
          </label>
        <?php else: ?>
          <label><?= e((string)$f['label']) ?>
            <input type="text" name="<?= e($name) ?>" value="<?= e(is_scalar($value) ? (string)$value : '') ?>" maxlength="1000">
          </label>
        <?php endif; ?>
        <?php
    }
}
if (!$any): ?>
  <p class="muted"><?= te('docs.fill.no_fields') ?></p>
<?php endif; ?>
