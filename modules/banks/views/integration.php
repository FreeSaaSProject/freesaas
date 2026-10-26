<?php
declare(strict_types=1);
/** @var array<string,mixed> $int */
/** @var array<string,array{path:string,exists:bool,readable:bool,bytes:int}> $mtls */
/** @var array<string,mixed>|null $probe */
/** @var list<array<string,mixed>> $related */
/** @var string $redirectUri */
/** @var bool $canEdit */
/** @var bool $canConnect */
/** @var string $message */
/** @var string $error */
$id = (int)$int['id'];
$env = (string)($int['env'] ?? 'prod');
$isSandbox = $env === 'sandbox';
?>
<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<section class="page-head">
  <div class="page-head-row">
    <div>
      <h1><?= e((string)($int['label'] ?? '')) ?></h1>
      <p class="muted">
        <span class="bank-chip"><?= e($env) ?></span>
        · <?= e((string)($int['status'] ?? '')) ?>
        · #<?= $id ?>
      </p>
      <p class="muted"><?= $isSandbox ? te('banks.int.sandbox_hint') : te('banks.int.prod_hint') ?></p>
    </div>
    <div class="page-actions">
      <a class="btn-secondary" href="/banks"><?= te('banks.setup.back_hub') ?></a>
      <?php if ($canConnect): ?>
        <a class="btn-primary" href="/banks/oauth/alfa_ul/start?integration_id=<?= $id ?>"><?= te('banks.list.connect') ?></a>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php if ($canEdit): ?>
<form method="post" enctype="multipart/form-data" class="form-grid">
  <input type="hidden" name="action" value="save">
  <label><?= te('banks.int.label') ?>
    <input name="label" value="<?= e((string)($int['label'] ?? '')) ?>">
  </label>
  <label><?= te('banks.settings.client_id') ?>
    <input name="client_id" value="<?= e((string)($int['client_id'] ?? '')) ?>">
  </label>
  <label><?= te('banks.settings.client_secret') ?>
    <input type="password" name="client_secret" value="" autocomplete="new-password"
           placeholder="<?= e((string)($int['client_secret_masked'] ?? te('banks.settings.secret_keep'))) ?>">
  </label>
  <label><?= te('banks.settings.alfa_ul_api_key') ?>
    <input type="password" name="api_key" value="" autocomplete="new-password"
           placeholder="<?= e((string)($int['api_key_masked'] ?? te('banks.settings.secret_keep'))) ?>">
  </label>
  <label><?= te('banks.settings.scope') ?>
    <input name="scope" value="<?= e((string)($int['scope'] ?? 'openid customer transactions profile email')) ?>">
    <span class="muted"><?= te('banks.settings.alfa_ul_scope_hint') ?></span>
  </label>
  <label><?= te('banks.settings.redirect_uri') ?>
    <input name="redirect_uri" value="<?= e((string)(($int['redirect_uri'] ?? '') !== '' ? $int['redirect_uri'] : $redirectUri)) ?>"
           class="banks-redirect-uri" onclick="this.select()">
  </label>
  <?php foreach (['cert' => te('banks.settings.mtls_cert'), 'key' => te('banks.settings.mtls_key'), 'ca' => te('banks.settings.mtls_ca')] as $kind => $lab):
    $st = $mtls[$kind] ?? [];
    ?>
    <label><?= e($lab) ?>
      <input type="file" name="mtls_<?= e($kind) ?>_file" accept=".cer,.crt,.pem,.key">
      <?php if (!empty($st['readable'])): ?>
        <span class="muted"><?= te('banks.settings.mtls_on_disk') ?>: <?= e(basename((string)$st['path'])) ?> (<?= (int)$st['bytes'] ?>)</span>
      <?php else: ?>
        <span class="muted"><?= te('banks.settings.mtls_missing') ?></span>
      <?php endif; ?>
    </label>
  <?php endforeach; ?>
  <label><?= te('banks.settings.mtls_pass') ?>
    <input type="password" name="mtls_pass" value="" autocomplete="new-password">
  </label>
  <label><?= te('banks.int.status') ?>
    <select name="status">
      <?php foreach (['draft', 'active', 'disabled'] as $stOpt): ?>
        <option value="<?= e($stOpt) ?>"<?= ($int['status'] ?? '') === $stOpt ? ' selected' : '' ?>><?= e($stOpt) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <div class="page-actions">
    <button type="submit" class="btn-primary"><?= te('core.common.save') ?></button>
  </div>
</form>

<?php if ($isSandbox): ?>
<form method="post" class="page-actions" style="margin-top:1rem">
  <input type="hidden" name="action" value="probe_scopes">
  <button type="submit" class="btn-secondary"><?= te('banks.int.probe_scopes') ?></button>
  <span class="muted"><?= te('banks.int.probe_scopes_hint') ?></span>
</form>
<?php endif; ?>
<?php endif; ?>

<?php if (is_array($probe) && !empty($probe['results'])): ?>
<section class="banks-hub-common" style="margin-top:1rem">
  <h2><?= te('banks.int.probe_results') ?></h2>
  <p class="muted"><?= e(date('d.m.Y H:i', (int)($probe['at'] ?? 0))) ?></p>
  <ul>
    <?php foreach ($probe['results'] as $r): ?>
      <li>
        <code><?= e((string)($r['scope'] ?? '')) ?></code>
        —
        <?php if (!empty($r['ok'])): ?>
          <span class="bank-chip"><?= te('banks.int.probe_ok') ?></span>
        <?php else: ?>
          <span class="bank-chip bank-chip-warn">HTTP <?= (int)($r['http'] ?? 0) ?></span>
          <span class="muted"><?= e((string)($r['error'] ?? '')) ?></span>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
  <p class="muted"><?= te('banks.int.probe_portal_hint') ?></p>
</section>
<?php endif; ?>

<section class="banks-hub-common" style="margin-top:1rem">
  <h2><?= te('banks.int.connections') ?></h2>
  <?php if ($related === []): ?>
    <p class="muted"><?= te('banks.int.no_connections') ?></p>
  <?php else: ?>
    <ul>
      <?php foreach ($related as $c): ?>
        <li>
          <a href="/banks/connections/<?= (int)$c['id'] ?>">#<?= (int)$c['id'] ?></a>
          · <?= e((string)($c['label'] ?? '')) ?>
          · <?= e(t('banks.status.' . (string)($c['status'] ?? 'pending'))) ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
