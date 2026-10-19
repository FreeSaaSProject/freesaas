<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed>|null $def */
/** @var array<string,mixed> $form */
/** @var list<array<string,mixed>> $versions */
/** @var list<string> $placeholders */
/** @var string $message */
/** @var string $error */
$typeLabels = [];
foreach (DocsSchema::TYPES as $t) {
    $typeLabels[$t] = t('docs.type.' . $t);
}
?>
<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid form-wide docs-def-form">
  <fieldset>
    <legend><?= te('docs.def.sec_meta') ?></legend>
    <label><?= te('docs.def.name') ?>
      <input name="name" required maxlength="255" value="<?= e((string)$form['name']) ?>">
    </label>
    <label><?= te('docs.def.code') ?>
      <input name="code" required pattern="[a-z0-9_\-]{2,64}" value="<?= e((string)$form['code']) ?>">
    </label>
    <label><?= te('docs.field.kind') ?>
      <select name="kind">
        <?php foreach (DocsDefsRepository::KINDS as $k): ?>
          <option value="<?= e($k) ?>"<?= $form['kind'] === $k ? ' selected' : '' ?>><?= e(DocsDefsRepository::kindLabel($k)) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= te('docs.def.scope') ?>
      <select name="scope">
        <?php foreach (DocsDefsRepository::SCOPES as $s): ?>
          <option value="<?= e($s) ?>"<?= $form['scope'] === $s ? ' selected' : '' ?>><?= te('docs.scope.' . $s) ?></option>
        <?php endforeach; ?>
      </select>
      <span class="form-hint"><?= te('docs.def.scope_hint') ?></span>
    </label>
    <label><?= te('docs.def.trigger_mode') ?>
      <select name="trigger_mode">
        <?php foreach (DocsDefsRepository::TRIGGERS as $tr): ?>
          <option value="<?= e($tr) ?>"<?= $form['trigger_mode'] === $tr ? ' selected' : '' ?>><?= te('docs.trigger.' . $tr) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= te('docs.def.trigger_types') ?>
      <input name="trigger_types" value="<?= e((string)$form['trigger_types']) ?>" placeholder="1,2">
    </label>
    <label><?= te('docs.def.trigger_procs') ?>
      <input name="trigger_procs" value="<?= e((string)$form['trigger_procs']) ?>" placeholder="10,20">
    </label>
    <label><?= te('docs.def.expire_days') ?>
      <input type="number" min="0" max="3650" name="expire_days" value="<?= (int)$form['expire_days'] ?>">
    </label>
    <label><?= te('docs.def.num') ?>
      <input type="number" name="num" value="<?= (int)$form['num'] ?>">
    </label>
    <label class="check"><input type="checkbox" name="require_medcard" value="1"<?= !empty($form['require_medcard']) ? ' checked' : '' ?>> <?= te('docs.def.require_medcard') ?></label>
    <label class="check"><input type="checkbox" name="enabled" value="1"<?= !empty($form['enabled']) ? ' checked' : '' ?>> <?= te('docs.def.enabled') ?></label>
  </fieldset>

  <fieldset class="docs-builder-wrap">
    <legend><?= te('docs.def.sec_fields') ?></legend>
    <p class="form-hint"><?= te('docs.def.fields_hint') ?></p>
    <div class="docs-builder" data-docs-builder
         data-types="<?= e((string)json_encode($typeLabels, JSON_UNESCAPED_UNICODE)) ?>"
         data-i18n-required="<?= te('docs.def.f_required') ?>"
         data-i18n-index="<?= te('docs.def.f_index') ?>"
         data-i18n-remove="<?= te('docs.segments.remove') ?>"
         data-i18n-up="<?= te('docs.def.f_up') ?>"
         data-i18n-down="<?= te('docs.def.f_down') ?>"
         data-i18n-empty="<?= te('docs.def.fields_empty') ?>"
         data-i18n-bad-json="<?= te('docs.def.bad_json') ?>"
         data-i18n-bad-id="<?= te('docs.def.err.field_id', ['id' => '']) ?>"
         data-i18n-dup-id="<?= te('docs.def.err.field_dup', ['id' => '']) ?>">
      <div class="docs-builder-list" data-docs-builder-list></div>
      <div class="docs-builder-add">
        <label><?= te('docs.def.f_section') ?>
          <input type="text" data-b-section list="docs-builder-sections" placeholder="<?= te('docs.def.f_section_ph') ?>">
          <datalist id="docs-builder-sections"></datalist>
        </label>
        <label><?= te('docs.def.f_id') ?>
          <input type="text" data-b-id pattern="[a-z][a-z0-9_]{0,63}" placeholder="ana_obj">
        </label>
        <label><?= te('docs.def.f_label') ?>
          <input type="text" data-b-label>
        </label>
        <label><?= te('docs.def.f_type') ?>
          <select data-b-type>
            <?php foreach ($typeLabels as $tk => $tl): ?>
              <option value="<?= e($tk) ?>"><?= e($tl) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="docs-builder-options"><?= te('docs.def.f_options') ?>
          <textarea data-b-options rows="3" placeholder="<?= te('docs.def.f_options_ph') ?>"></textarea>
        </label>
        <label class="check"><input type="checkbox" data-b-required> <?= te('docs.def.f_required') ?></label>
        <label class="check"><input type="checkbox" data-b-index> <?= te('docs.def.f_index') ?></label>
        <button type="button" class="btn-secondary" data-b-add><?= te('docs.def.f_add') ?></button>
      </div>
    </div>
    <details class="docs-builder-json">
      <summary><?= te('docs.def.schema_json') ?></summary>
      <textarea name="schema_json" rows="14" spellcheck="false" data-docs-schema><?= e((string)$form['schema_json']) ?></textarea>
    </details>
  </fieldset>

  <fieldset>
    <legend><?= te('docs.def.sec_template') ?></legend>
    <p class="form-hint"><?= te('docs.def.template_hint') ?></p>
    <div class="docs-placeholders" data-docs-placeholders>
      <?php foreach ($placeholders as $ph): ?>
        <button type="button" class="btn-secondary btn-sm" data-ph="{<?= e($ph) ?>}">{<?= e($ph) ?>}</button>
      <?php endforeach; ?>
    </div>
    <textarea name="print_html" rows="14" spellcheck="false" data-docs-template><?= e((string)$form['print_html']) ?></textarea>
    <label><?= te('docs.def.orientation') ?>
      <select name="orientation">
        <?php foreach (DocsDefsRepository::ORIENTATIONS as $o): ?>
          <option value="<?= e($o) ?>"<?= $form['orientation'] === $o ? ' selected' : '' ?>><?= te('docs.orientation.' . $o) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= te('docs.def.format') ?>
      <select name="format">
        <?php foreach (DocsDefsRepository::FORMATS as $fm): ?>
          <option value="<?= e($fm) ?>"<?= $form['format'] === $fm ? ' selected' : '' ?>><?= e($fm) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= te('docs.def.copies') ?>
      <input type="number" min="1" max="9" name="copies" value="<?= (int)$form['copies'] ?>">
    </label>
  </fieldset>

  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/documents/defs"><?= te('core.common.cancel') ?></a>
    <?php if (!$isNew && $def !== null): ?>
      <a class="btn-secondary" href="/documents/defs/<?= (int)$def['id'] ?>/sample" target="_blank" rel="noopener"><?= te('docs.sample.open') ?></a>
      <a class="btn-secondary" href="/documents/defs/<?= (int)$def['id'] ?>/export"><?= te('docs.def.export') ?></a>
      <a class="btn-secondary" href="/documents?def=<?= (int)$def['id'] ?>"><?= te('docs.def.instances') ?></a>
    <?php endif; ?>
  </div>
</form>

<?php if ($versions !== []): ?>
<section class="docs-versions">
  <h2><?= te('docs.def.versions') ?></h2>
  <ul>
    <?php foreach ($versions as $v): ?>
      <li>v<?= (int)$v['version'] ?> · <?= e(I18n::formatDate((int)$v['dt_create'], 'datetime')) ?>
        · <?= e((string)$v['format']) ?> <?= te('docs.orientation.' . $v['orientation']) ?>
        · <a href="/documents/defs/<?= (int)$def['id'] ?>/sample?version=<?= (int)$v['id'] ?>" target="_blank" rel="noopener"><?= te('docs.sample.open') ?></a></li>
    <?php endforeach; ?>
  </ul>
  <p class="form-hint"><?= te('docs.def.versions_hint') ?></p>
</section>
<?php endif; ?>
