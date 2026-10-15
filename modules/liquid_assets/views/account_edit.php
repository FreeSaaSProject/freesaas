<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var array<int,string> $fundOptions */
/** @var array<int,string> $userOptions */
/** @var bool $fundsOn */
/** @var string $message */
/** @var string $error */
?>
<section class="page-head">
  <div class="page-head-row">
    <div></div>
    <div class="page-actions">
      <a class="btn-secondary" href="/liquid-assets/accounts"><?= te('liquid_assets.menu.accounts') ?></a>
    </div>
  </div>
</section>
<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="form-card liquid-assets-edit">
  <p class="muted"><?= te('liquid_assets.accounts.token_info') ?></p>
  <label>
    <span><?= te('liquid_assets.accounts.fund') ?></span>
    <select name="fund_id" <?= $fundsOn ? '' : 'disabled' ?>>
      <?php foreach ($fundOptions as $fid => $fname): ?>
      <option value="<?= (int)$fid ?>" <?= (int)$row['fund_id'] === (int)$fid ? 'selected' : '' ?>><?= e($fname) ?></option>
      <?php endforeach; ?>
    </select>
    <?php if (!$fundsOn): ?>
    <input type="hidden" name="fund_id" value="0">
    <small class="muted"><?= te('liquid_assets.sync.funds_off') ?></small>
    <?php endif; ?>
  </label>
  <label>
    <span><?= te('liquid_assets.accounts.user') ?></span>
    <select name="user_id">
      <?php foreach ($userOptions as $uid => $uname): ?>
      <option value="<?= (int)$uid ?>" <?= (int)$row['user_id'] === (int)$uid ? 'selected' : '' ?>><?= e($uname) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>
    <span><?= te('liquid_assets.accounts.token') ?>
      <?php if (!$isNew): ?>
      <small class="muted">(<?= te('liquid_assets.accounts.token_keep') ?>)</small>
      <?php endif; ?>
    </span>
    <input type="password" name="token" value="" autocomplete="new-password"
           <?= $isNew ? 'required' : '' ?>
           placeholder="<?= $isNew ? '' : e(TinvestAccountsRepository::maskToken((string)$row['token'])) ?>">
  </label>
  <div class="form-actions">
    <button type="submit" class="btn-primary"><?= te('core.common.save') ?></button>
  </div>
</form>

<?php if (empty($isNew) && empty($is_new)): ?>
<?= EntityDelete::form('liquid_assets_accounts', (int)($row['id'] ?? 0)) ?>
<?php endif; ?>

