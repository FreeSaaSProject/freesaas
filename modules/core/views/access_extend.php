<?php
declare(strict_types=1);
/** @var string $field */
/** @var int $find_id */
/** @var int $add_id */
/** @var string $picker_kind */
/** @var array<int,string> $picker_options */
/** @var array<string,mixed>|null $preview */
/** @var string $message */
/** @var string $error */
$field = (string)($field ?? 'ACCESS_GROUP');
$find_id = (int)($find_id ?? 0);
$add_id = (int)($add_id ?? 0);
$picker_options = is_array($picker_options ?? null) ? $picker_options : [];
$preview = is_array($preview ?? null) ? $preview : null;
$message = (string)($message ?? '');
$error = (string)($error ?? '');

$typeLabel = static function (int $type): string {
    return in_array($type, [0, 1, 2, 3], true)
        ? t('core.news.type.' . $type)
        : t('core.news.type_unknown', ['n' => $type]);
};

$labelFor = static function (int $id) use ($picker_options): string {
    if ($id < 1) {
        return '';
    }
    return (string)($picker_options[$id] ?? ('#' . $id));
};
?>
<section class="page-head">
  <p class="lede"><?= te('core.access_extend.lede') ?></p>
  <p class="muted"><?= te('core.access_extend.hint') ?></p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<section class="card-block">
  <form method="post" class="form-grid access-extend-form">
    <input type="hidden" name="action" value="preview">

    <label><?= te('core.access_extend.field') ?>
      <select name="field" id="access-extend-field">
        <option value="ACCESS_GROUP"<?= $field === 'ACCESS_GROUP' ? ' selected' : '' ?>><?= te('core.access_extend.field.group') ?></option>
        <option value="ACCESS_GROUP2"<?= $field === 'ACCESS_GROUP2' ? ' selected' : '' ?>><?= te('core.access_extend.field.group2') ?></option>
        <option value="ACCESS_USER"<?= $field === 'ACCESS_USER' ? ' selected' : '' ?>><?= te('core.access_extend.field.user') ?></option>
      </select>
    </label>

    <label><?= te('core.access_extend.find') ?>
      <select name="find_id" required>
        <option value=""><?= te('core.access_extend.pick') ?></option>
        <?php foreach ($picker_options as $id => $name): ?>
          <option value="<?= (int)$id ?>"<?= $find_id === (int)$id ? ' selected' : '' ?>><?= e((string)$name) ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <label><?= te('core.access_extend.add') ?>
      <select name="add_id" required>
        <option value=""><?= te('core.access_extend.pick') ?></option>
        <?php foreach ($picker_options as $id => $name): ?>
          <option value="<?= (int)$id ?>"<?= $add_id === (int)$id ? ' selected' : '' ?>><?= e((string)$name) ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <p class="actions">
      <button type="submit" class="btn-secondary"><?= te('core.access_extend.btn_preview') ?></button>
    </p>
  </form>
  <p class="muted"><?= te('core.access_extend.field_reload_hint') ?></p>
</section>

<?php if ($preview !== null): ?>
  <?php
    $total = (int)($preview['total'] ?? 0);
    $already = (int)($preview['already'] ?? 0);
    $samples = is_array($preview['samples'] ?? null) ? $preview['samples'] : [];
    $willUpdate = max(0, $total - $already);
  ?>
  <section class="card-block">
    <h2><?= te('core.access_extend.preview_title') ?></h2>
    <p>
      <?= te('core.access_extend.preview_summary', [
          'total' => (string)$total,
          'will' => (string)$willUpdate,
          'already' => (string)$already,
          'find' => $labelFor($find_id),
          'add' => $labelFor($add_id),
      ]) ?>
    </p>

    <?php if ($willUpdate > 0): ?>
      <form method="post" class="actions" onsubmit="return confirm(<?= tj('core.access_extend.confirm') ?>);">
        <input type="hidden" name="action" value="apply">
        <input type="hidden" name="field" value="<?= e($field) ?>">
        <input type="hidden" name="find_id" value="<?= $find_id ?>">
        <input type="hidden" name="add_id" value="<?= $add_id ?>">
        <button type="submit" class="btn-primary"><?= te('core.access_extend.btn_apply', ['n' => (string)$willUpdate]) ?></button>
      </form>
    <?php endif; ?>

    <?php if ($samples): ?>
      <div class="table-wrap" style="margin-top:1rem">
        <table class="data">
          <thead>
            <tr>
              <th><?= te('core.access_extend.col.id') ?></th>
              <th><?= te('core.access_extend.col.type') ?></th>
              <th><?= te('core.access_extend.col.name') ?></th>
              <th><?= te('core.access_extend.col.before') ?></th>
              <th><?= te('core.access_extend.col.after') ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($samples as $row): ?>
              <?php if (!is_array($row)) {
                  continue;
              } ?>
              <tr<?= !empty($row['disabled']) ? ' style="opacity:.55"' : '' ?>>
                <td><a href="/news/<?= (int)$row['id'] ?>"><?= (int)$row['id'] ?></a></td>
                <td><?= e($typeLabel((int)($row['type'] ?? 0))) ?></td>
                <td>
                  <?= e((string)($row['name'] ?? '')) ?>
                  <?php if (!empty($row['disabled'])): ?>
                    <span class="muted">(<?= te('core.access_extend.disabled') ?>)</span>
                  <?php endif; ?>
                </td>
                <td><code><?= e((string)($row['before'] ?? '')) ?></code></td>
                <td><code><?= e((string)($row['after'] ?? '')) ?></code></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($total > count($samples)): ?>
        <p class="muted"><?= te('core.access_extend.preview_truncated', [
            'shown' => (string)count($samples),
            'total' => (string)$total,
        ]) ?></p>
      <?php endif; ?>
    <?php endif; ?>
  </section>
<?php endif; ?>

<script>
(function () {
  var sel = document.getElementById('access-extend-field');
  if (!sel) return;
  sel.addEventListener('change', function () {
    var url = new URL(window.location.href);
    url.searchParams.set('field', sel.value);
    url.searchParams.delete('find_id');
    url.searchParams.delete('add_id');
    window.location.href = url.pathname + url.search;
  });
})();
</script>
