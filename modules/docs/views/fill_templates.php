<?php
declare(strict_types=1);
/** @var list<array{id:int,code:string,name:string}> $defs */
/** @var int $defId */
/** @var array<string,mixed>|null $def */
/** @var array{sections:list<array<string,mixed>>} $schema */
/** @var array<string,mixed> $fillFields */
/** @var bool $enabled */
/** @var int $adminId */
/** @var bool $isClinic */
/** @var string $message */
/** @var string $error */
?>
<section class="page-head">
  <h1><?= te($isClinic ? 'docs.fill.title_clinic' : 'docs.fill.title') ?></h1>
  <p class="muted"><?= te($isClinic ? 'docs.fill.hint_clinic' : 'docs.fill.hint_doctor') ?></p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<?php if ($defs === []): ?>
  <p class="muted"><?= te('docs.fill.no_defs') ?></p>
<?php else: ?>
<form method="get" class="form-inline docs-fill-pick" style="margin-bottom:1rem">
  <label><?= te('docs.fill.pick_def') ?>
    <select name="def" onchange="this.form.submit()">
      <?php foreach ($defs as $d): ?>
        <option value="<?= (int)$d['id'] ?>"<?= $defId === (int)$d['id'] ? ' selected' : '' ?>><?= e($d['name']) ?> (<?= e($d['code']) ?>)</option>
      <?php endforeach; ?>
    </select>
  </label>
  <?php if ($isClinic): ?><input type="hidden" name="admin" value="0"><?php endif; ?>
</form>

<?php if ($def !== null): ?>
<form method="post" class="form-grid form-wide">
  <input type="hidden" name="def" value="<?= (int)$defId ?>">
  <?php if ($isClinic): ?><input type="hidden" name="admin" value="0"><?php endif; ?>
  <fieldset>
    <legend><?= te('docs.fill.sec_fields') ?></legend>
    <p class="form-hint"><?= te('docs.fill.fields_hint') ?></p>
    <?php
    $inputPrefix = 'fill';
    require __DIR__ . '/_fill_template_fields.php';
    ?>
    <label class="check">
      <input type="checkbox" name="enabled" value="1"<?= $enabled ? ' checked' : '' ?>>
      <?= te('docs.fill.enabled') ?>
    </label>
  </fieldset>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/documents"><?= te('core.common.cancel') ?></a>
  </div>
</form>
<?php endif; ?>
<?php endif; ?>
