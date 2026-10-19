<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $packs */
/** @var string $packId */
/** @var list<array<string,mixed>> $templates */
/** @var list<array<string,mixed>> $results */
/** @var array<string,int> $counts */
/** @var string $error */
/** @var array<string,bool> $opts */
$optionsHtml = static function (array $opts): string {
    $out = '';
    foreach (['keep_ids', 'update', 'enable'] as $k) {
        $out .= '<label class="check"><input type="checkbox" name="' . $k . '" value="1"' . (!empty($opts[$k]) ? ' checked' : '') . '> '
            . te('docs.import.opt.' . $k) . '</label>';
    }
    return $out;
};
?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>
<?php if ($results !== []): ?>
  <div class="flash flash-ok"><?= te('docs.import.done', $counts) ?></div>
  <ul class="docs-import-results">
    <?php foreach ($results as $r): ?>
      <li><span class="docs-status docs-status--<?= $r['status'] === 'skipped' ? 'void' : 'ready' ?>"><?= te('docs.import.status.' . $r['status']) ?></span>
        <a href="/documents/defs/<?= (int)$r['id'] ?>/edit"><?= e((string)$r['name']) ?></a> <code><?= e((string)$r['code']) ?></code></li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>

<section class="docs-import-block">
  <h2><?= te('docs.import.library') ?></h2>
  <p class="form-hint"><?= te('docs.import.library_hint') ?></p>
  <?php if ($packs === []): ?>
    <p class="muted"><?= te('docs.import.no_packs') ?></p>
  <?php else: ?>
    <form method="get" class="filters">
      <label><?= te('docs.import.pack') ?>
        <select name="pack" onchange="this.form.submit()">
          <?php foreach ($packs as $p): ?>
            <option value="<?= e((string)$p['id']) ?>"<?= $p['id'] === $packId ? ' selected' : '' ?>><?= e((string)$p['name']) ?> (<?= (int)$p['count'] ?>)</option>
          <?php endforeach; ?>
        </select>
      </label>
    </form>
    <form method="post" data-docs-import>
      <input type="hidden" name="pack" value="<?= e($packId) ?>">
      <div class="table-wrap">
        <table class="data">
          <thead>
            <tr>
              <th><input type="checkbox" data-docs-check-all aria-label="<?= te('docs.import.select_all') ?>"></th>
              <th><?= te('docs.def.name') ?></th>
              <th><?= te('docs.field.kind') ?></th>
              <th><?= te('docs.import.legacy_trigger') ?></th>
              <th></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($templates as $tpl): ?>
            <tr<?= $tpl['exists'] ? ' class="is-muted"' : '' ?>>
              <td><input type="checkbox" name="files[]" value="<?= e((string)$tpl['file']) ?>"<?= $tpl['exists'] ? '' : ' checked' ?>></td>
              <td><?= e((string)$tpl['name']) ?> <code class="muted"><?= e((string)$tpl['code']) ?></code>
                <?php if ($tpl['exists']): ?><span class="muted">· <?= te('docs.import.exists') ?></span><?php endif; ?></td>
              <td><?= e(DocsDefsRepository::kindLabel((string)$tpl['kind'])) ?></td>
              <td class="muted"><?= e((string)$tpl['legacy_trigger']) ?></td>
              <td><a href="/documents/defs/import/sample?pack=<?= e(rawurlencode($packId)) ?>&amp;file=<?= e(rawurlencode((string)$tpl['file'])) ?>" target="_blank" rel="noopener"><?= te('docs.sample.open') ?></a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="docs-import-opts"><?= $optionsHtml($opts) ?></div>
      <div class="page-actions">
        <button class="btn-primary" type="submit" name="action" value="import_pack"><?= te('docs.import.do') ?></button>
      </div>
    </form>
  <?php endif; ?>
</section>

<section class="docs-import-block">
  <h2><?= te('docs.import.upload') ?></h2>
  <p class="form-hint"><?= te('docs.import.upload_hint') ?></p>
  <form method="post" enctype="multipart/form-data" class="form-grid">
    <input type="file" name="file" accept=".json,application/json" required>
    <div class="docs-import-opts"><?= $optionsHtml($opts) ?></div>
    <div class="page-actions">
      <button class="btn-secondary" type="submit" name="action" value="upload"><?= te('docs.import.do_upload') ?></button>
    </div>
  </form>
</section>
