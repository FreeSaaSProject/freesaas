<?php
declare(strict_types=1);
/** @var array<string,mixed> $cfg */
/** @var bool $canSave */
/** @var string $message */
/** @var string $error */
/** @var list<array{id:string,label:string}> $freezeOptions */
$cfg = is_array($cfg ?? null) ? $cfg : [];
$canSave = !empty($canSave);
$freezeOptions = is_array($freezeOptions ?? null) ? $freezeOptions : [];
$frozenSet = array_fill_keys(is_array($cfg['frozen_modules'] ?? null) ? $cfg['frozen_modules'] : [], true);
$message = (string)($message ?? '');
$error = (string)($error ?? '');
$channel = (string)($cfg['channel'] ?? 'main');
?>
<section class="page-head">
  <p class="lede"><?= te('updates.settings.lede') ?></p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid" style="max-width:36rem">
  <label><?= te('updates.settings.repo') ?>
    <input type="text" name="repo" value="<?= e((string)($cfg['repo'] ?? '')) ?>"
           placeholder="owner/repo" <?= $canSave ? '' : 'readonly' ?> required>
  </label>
  <label><?= te('updates.settings.channel') ?>
    <select name="channel" <?= $canSave ? '' : 'disabled' ?>>
      <option value="main"<?= $channel === 'main' ? ' selected' : '' ?>><?= te('updates.settings.channel_main') ?></option>
      <option value="stable"<?= $channel === 'stable' ? ' selected' : '' ?>><?= te('updates.settings.channel_stable') ?></option>
      <option value="latest"<?= $channel === 'latest' ? ' selected' : '' ?>><?= te('updates.settings.channel_latest') ?></option>
    </select>
  </label>
  <p class="muted form-hint"><?= te('updates.settings.channel_help') ?></p>
  <label><?= te('updates.settings.ref') ?>
    <input type="text" name="ref" value="<?= e((string)($cfg['ref'] ?? '')) ?>"
           placeholder="<?= te('updates.settings.ref_ph') ?>" <?= $canSave ? '' : 'readonly' ?>>
  </label>
  <label><?= te('updates.settings.asset') ?>
    <input type="text" name="asset" value="<?= e((string)($cfg['asset'] ?? '')) ?>"
           placeholder="freesaas-0.3.3.zip" <?= $canSave ? '' : 'readonly' ?>>
  </label>
  <label><?= te('updates.settings.token') ?>
    <input type="password" name="token" value="" autocomplete="new-password"
           placeholder="<?= !empty($cfg['token_set']) ? te('updates.settings.token_kept') : te('updates.settings.token_ph') ?>"
           <?= $canSave ? '' : 'readonly' ?>>
  </label>
  <?php if ($canSave && !empty($cfg['token_set'])): ?>
  <label class="check">
    <input type="checkbox" name="clear_token" value="1">
    <?= te('updates.settings.clear_token') ?>
  </label>
  <?php endif; ?>
  <label class="check">
    <input type="checkbox" name="db_backup_before_migrate" value="1"
      <?= !empty($cfg['db_backup_before_migrate']) ? ' checked' : '' ?>
      <?= $canSave ? '' : 'disabled' ?>>
    <?= te('updates.settings.db_backup') ?>
  </label>
  <p class="muted form-hint"><?= te('updates.settings.db_backup_help') ?></p>
  <fieldset class="form-fieldset upd-freeze">
    <legend><?= te('updates.settings.frozen') ?></legend>
    <p class="muted form-hint"><?= te('updates.settings.frozen_help') ?></p>
    <input type="hidden" name="frozen_modules_present" value="1">
    <div class="upd-freeze-grid">
      <?php foreach ($freezeOptions as $opt): ?>
      <label class="check">
        <input type="checkbox" name="frozen_modules[]" value="<?= e($opt['id']) ?>"
          <?= isset($frozenSet[$opt['id']]) ? ' checked' : '' ?>
          <?= $canSave ? '' : 'disabled' ?>>
        <?= e($opt['label']) ?> <code class="muted"><?= e($opt['id']) ?></code>
      </label>
      <?php endforeach; ?>
    </div>
  </fieldset>
  <input type="hidden" name="provider" value="github">
  <div class="page-actions">
    <?php if ($canSave): ?>
      <button type="submit" class="btn-primary"><?= te('core.common.save') ?></button>
    <?php endif; ?>
    <a class="btn-secondary" href="/updates"><?= te('updates.settings.back') ?></a>
  </div>
</form>

<?php
/** @var list<array<string,mixed>> $sources */
/** @var list<string> $themeIds */
$sources = is_array($sources ?? null) ? $sources : [];
$themeIds = is_array($themeIds ?? null) ? $themeIds : [];
$sourceForm = static function (array $s, bool $isNew) use ($canSave): string {
    $ro = $canSave ? '' : ' readonly';
    ob_start(); ?>
  <form method="post" class="form-grid upd-source-form">
    <input type="hidden" name="action" value="source_save">
    <?php if ($isNew): ?>
      <label><?= te('updates.sources.kind') ?>
        <select name="kind" <?= $canSave ? '' : 'disabled' ?>>
          <option value="module"><?= te('updates.sources.kind_module') ?></option>
          <option value="theme"><?= te('updates.sources.kind_theme') ?></option>
        </select>
      </label>
      <label><?= te('updates.sources.id') ?>
        <input type="text" name="id" list="upd-source-ids" required pattern="[a-z0-9][a-z0-9_-]*" placeholder="freesaas_site"<?= $ro ?>>
      </label>
    <?php else: ?>
      <input type="hidden" name="kind" value="<?= e((string)$s['kind']) ?>">
      <input type="hidden" name="id" value="<?= e((string)$s['id']) ?>">
    <?php endif; ?>
    <input type="hidden" name="provider" value="<?= e((string)($s['provider'] ?? 'github')) ?>">
    <label><?= te('updates.settings.repo') ?>
      <input type="text" name="repo" value="<?= e((string)($s['repo'] ?? '')) ?>" placeholder="owner/repo" required<?= $ro ?>>
    </label>
    <label><?= te('updates.settings.channel') ?>
      <input type="text" name="channel" value="<?= e((string)($s['channel'] ?? 'main')) ?>" list="upd-source-channels"<?= $ro ?>>
    </label>
    <label><?= te('updates.sources.path_prefix') ?>
      <input type="text" name="path_prefix" value="<?= e((string)($s['path_prefix'] ?? '')) ?>" placeholder="modules/my_module"<?= $ro ?>>
      <span class="field-hint muted"><?= te('updates.sources.path_prefix_help') ?></span>
    </label>
    <label><?= te('updates.settings.ref') ?>
      <input type="text" name="ref" value="<?= e((string)($s['ref'] ?? '')) ?>" placeholder="<?= te('updates.settings.ref_ph') ?>"<?= $ro ?>>
    </label>
    <label><?= te('updates.settings.token') ?>
      <input type="password" name="token" value="" autocomplete="new-password"
             placeholder="<?= !empty($s['token_set']) ? te('updates.settings.token_kept') : te('updates.sources.token_ph') ?>"<?= $ro ?>>
    </label>
    <?php if ($canSave && !empty($s['token_set'])): ?>
      <label class="check"><input type="checkbox" name="clear_token" value="1"> <?= te('updates.settings.clear_token') ?></label>
    <?php endif; ?>
    <?php if ($canSave): ?>
      <div class="page-actions">
        <button type="submit" class="btn-primary"><?= te($isNew ? 'updates.sources.add' : 'updates.sources.save') ?></button>
      </div>
    <?php endif; ?>
  </form>
    <?php
    return (string)ob_get_clean();
};
?>
<section class="upd-sources" id="sources">
  <h2><?= te('updates.sources.title') ?></h2>
  <p class="muted form-hint"><?= te('updates.sources.help') ?></p>
  <datalist id="upd-source-channels"><option value="main"><option value="stable"><option value="latest"></datalist>
  <datalist id="upd-source-ids">
    <?php foreach ($freezeOptions as $opt): ?><option value="<?= e($opt['id']) ?>"><?php endforeach; ?>
    <?php foreach ($themeIds as $tid): ?><option value="<?= e((string)$tid) ?>"><?php endforeach; ?>
  </datalist>

  <?php if ($sources === []): ?>
    <p class="muted"><?= te('updates.sources.empty') ?></p>
  <?php endif; ?>
  <?php foreach ($sources as $s): ?>
    <fieldset class="form-fieldset upd-source">
      <legend>
        <?= e(UpdateEngine::packageLabel((string)$s['package'])) ?>
        <code class="muted"><?= e((string)$s['package']) ?></code>
        <span class="pill"><?= (string)$s['kind'] === 'theme' ? te('updates.sources.kind_theme') : te('updates.sources.kind_module') ?></span>
      </legend>
      <?= $sourceForm($s, false) ?>
      <?php if ($canSave): ?>
        <form method="post" class="upd-source-delete" onsubmit="return confirm(<?= tj('updates.sources.delete_confirm') ?>);">
          <input type="hidden" name="action" value="source_delete">
          <input type="hidden" name="kind" value="<?= e((string)$s['kind']) ?>">
          <input type="hidden" name="id" value="<?= e((string)$s['id']) ?>">
          <button type="submit" class="btn-secondary"><?= te('updates.sources.delete') ?></button>
        </form>
      <?php endif; ?>
    </fieldset>
  <?php endforeach; ?>

  <?php if ($canSave): ?>
    <details class="upd-source-new"<?= $sources === [] ? ' open' : '' ?>>
      <summary><?= te('updates.sources.add_title') ?></summary>
      <?= $sourceForm(['provider' => 'github', 'channel' => 'main'], true) ?>
    </details>
  <?php endif; ?>
</section>
