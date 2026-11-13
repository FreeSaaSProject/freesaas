<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var string $message */
/** @var string $error */
/** @var bool $hasChildren */
/** @var array<int,string> $parentOptions */
/** @var array<int,string> $hostOptions */
$hostOptions = $hostOptions ?? [];
?>
<section class="page-head">
  <h1><?= $isNew ? te('sites.pages.edit.heading_new') : e((string)($row['name'] ?? '')) ?></h1>
  <?php if (!empty($hasChildren)): ?>
    <p class="muted"><?= te('sites.pages.has_children_hint') ?></p>
  <?php endif; ?>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<?php
/** @var list<array{host_id:int,host:string,locale:string,peer:?array,create_url:string}> $translations */
/** @var array<string,mixed>|null $translationOf */
$translations = is_array($translations ?? null) ? $translations : [];
$translationOf = is_array($translationOf ?? null) ? $translationOf : null;
?>
<?php if ($translationOf !== null): ?>
  <div class="flash flash-warn">
    <?= te('sites.pages.translation.draft_hint', ['name' => (string)$translationOf['name']]) ?>
    <a href="/sites/pages/<?= (int)$translationOf['id'] ?>/edit"><?= te('sites.pages.translation.open_source') ?></a>
  </div>
<?php endif; ?>
<?php if ($translations !== []): ?>
  <section class="sites-translations">
    <h2><?= te('sites.pages.translation.title') ?></h2>
    <p class="muted form-hint"><?= te('sites.pages.translation.help', ['link_rw' => (string)($row['link_rw'] ?? '')]) ?></p>
    <ul>
      <?php foreach ($translations as $tr): ?>
        <li>
          <strong><?= e(I18n::localeLabel((string)$tr['locale'])) ?></strong> · <?= e((string)$tr['host']) ?> —
          <?php if (is_array($tr['peer'])): ?>
            <a href="/sites/pages/<?= (int)$tr['peer']['id'] ?>/edit">#<?= (int)$tr['peer']['id'] ?> <?= e((string)$tr['peer']['name']) ?></a>
            <?php if (empty($tr['peer']['enabled'])): ?><span class="pill"><?= te('sites.pages.translation.disabled') ?></span><?php endif; ?>
          <?php else: ?>
            <span class="muted"><?= te('sites.pages.translation.missing') ?></span>
            <a class="btn-secondary" href="<?= e((string)$tr['create_url']) ?>"><?= te('sites.pages.translation.create') ?></a>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
<?php endif; ?>

<form method="post" class="form-grid form-wide">
  <fieldset>
    <legend><?= te('sites.pages.sec.main') ?></legend>
    <?php if ($hostOptions !== []): ?>
    <label><?= te('sites.pages.field.site_host_id') ?>
      <select name="site_host_id">
        <?php foreach ($hostOptions as $hid => $hlabel): ?>
          <option value="<?= (int)$hid ?>"<?= (int)($row['site_host_id'] ?? 0) === (int)$hid ? ' selected' : '' ?>><?= e($hlabel) ?></option>
        <?php endforeach; ?>
      </select>
      <span class="field-hint muted"><?= te('sites.pages.field.site_host_id_hint') ?></span>
    </label>
    <?php endif; ?>
    <label><?= te('sites.pages.field.name') ?>
      <input name="name" maxlength="255" value="<?= e((string)($row['name'] ?? '')) ?>">
    </label>
    <label><?= te('sites.pages.field.num') ?>
      <input type="number" min="0" name="num" value="<?= e((string)($row['num'] ?? '1')) ?>">
    </label>
    <label><?= te('sites.pages.field.link') ?>
      <input name="link" maxlength="255" value="<?= e((string)($row['link'] ?? '')) ?>" required>
    </label>
    <label><?= te('sites.pages.field.link_rw') ?>
      <input name="link_rw" maxlength="255" value="<?= e((string)($row['link_rw'] ?? '')) ?>">
      <span class="field-hint muted"><?= te('sites.pages.field.link_rw_hint') ?></span>
    </label>
    <label><?= te('sites.pages.field.bg_url') ?>
      <input name="bg_url" maxlength="255" value="<?= e((string)($row['bg_url'] ?? '')) ?>">
    </label>
    <label><?= te('sites.pages.field.parent_to') ?>
      <select name="parent_to">
        <?php foreach ($parentOptions as $pid => $label): ?>
          <option value="<?= (int)$pid ?>"<?= (int)($row['parent_to'] ?? 0) === (int)$pid ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="check"><input type="checkbox" name="enabled" value="1"<?= !empty($row['enabled']) ? ' checked' : '' ?>> <?= te('sites.pages.field.enabled') ?></label>
  </fieldset>

  <fieldset>
    <legend><?= te('sites.pages.sec.seo') ?></legend>
    <label><?= te('sites.pages.field.meta_title') ?>
      <input name="meta_title" maxlength="255" value="<?= e((string)($row['meta_title'] ?? '')) ?>">
      <span class="field-hint muted"><?= te('sites.pages.field.meta_title_hint') ?></span>
    </label>
    <label><?= te('sites.pages.field.meta_descr') ?>
      <textarea name="meta_descr" rows="2" maxlength="512"><?= e((string)($row['meta_descr'] ?? '')) ?></textarea>
      <span class="field-hint muted"><?= te('sites.pages.field.meta_descr_hint') ?></span>
    </label>
  </fieldset>

  <fieldset>
    <legend><?= te('sites.pages.sec.content') ?></legend>
    <label><?= te('sites.pages.field.content') ?>
      <textarea name="content" rows="16"><?= e((string)($row['content'] ?? '')) ?></textarea>
    </label>
  </fieldset>

  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/sites/pages"><?= te('core.common.cancel') ?></a>
  </div>
</form>
<?php if (empty($isNew) && empty($is_new)): ?>
<?= EntityDelete::form('sites_pages', (int)($row['id'] ?? $item['id'] ?? 0)) ?>
<?php endif; ?>

