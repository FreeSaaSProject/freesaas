<?php
declare(strict_types=1);
/** @var array<string,array<string,string>> $modules */
/** @var list<array{id:int,label:string}> $copyFromOptions */
$modules = is_array($modules ?? null) ? $modules : [];
$copyFromOptions = is_array($copyFromOptions ?? null) ? $copyFromOptions : [];
?>
<fieldset class="likespa-lp-modules">
  <legend><?= te('sites.promopages.sec.modules') ?></legend>
  <p class="muted"><?= te('sites.promopages.mod.lede') ?></p>
  <?php if ($copyFromOptions): ?>
  <label><?= te('sites.promopages.mod.copy_from') ?>
    <select name="copy_modules_from" onchange="if(this.value){location.href=this.value;}">
      <option value=""><?= te('sites.promopages.mod.copy_none') ?></option>
      <?php foreach ($copyFromOptions as $opt): ?>
        <option value="<?= e((string)$opt['href']) ?>"><?= e((string)$opt['label']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <?php endif; ?>

  <?php foreach (PromopageModules::catalog() as $mod): ?>
    <?php
      $mid = $mod['id'];
      $modData = is_array($modules[$mid] ?? null) ? $modules[$mid] : [];
      $active = PromopageModules::isActive($modData);
    ?>
    <details class="likespa-lp-mod"<?= $active ? ' open' : '' ?>>
      <summary>
        <code><?= e($mid) ?></code>
        <?= te($mod['title_key']) ?>
        <?php if ($active): ?>
          <span class="muted">#<?= e((string)($modData['NUMBER'] ?? '')) ?></span>
        <?php endif; ?>
      </summary>
      <div class="form-grid likespa-lp-mod-body">
        <?php if (!empty($mod['hint_key'])): ?>
          <p class="muted"><?= te((string)$mod['hint_key']) ?></p>
        <?php endif; ?>
        <label><?= te('sites.promopages.mod.field.number') ?>
          <input name="modules[<?= e($mid) ?>][NUMBER]" value="<?= e((string)($modData['NUMBER'] ?? '')) ?>" placeholder="0">
          <span class="field-hint muted"><?= te('sites.promopages.mod.field.number_hint') ?></span>
        </label>
        <?php
          // Always show fields (at least one grow slot) so NUMBER + content save in one submit.
          $fieldsData = $active ? $modData : ($modData + ['NUMBER' => '1']);
        ?>
        <?php foreach (PromopageModules::visibleFields($mod, $fieldsData) as $field): ?>
          <label><?= e((string)$field['label']) ?>
            <?php if (!empty($field['textarea'])): ?>
              <textarea name="modules[<?= e($mid) ?>][<?= e((string)$field['code']) ?>]" rows="4"><?= e((string)($modData[$field['code']] ?? '')) ?></textarea>
            <?php else: ?>
              <input name="modules[<?= e($mid) ?>][<?= e((string)$field['code']) ?>]" value="<?= e((string)($modData[$field['code']] ?? '')) ?>">
            <?php endif; ?>
          </label>
        <?php endforeach; ?>
      </div>
    </details>
  <?php endforeach; ?>
</fieldset>
