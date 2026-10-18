<?php
declare(strict_types=1);
/** @var string $message */
/** @var string $error */
/** @var int $ticketId */
/** @var string $ticketTitle */
/** @var int $termsId */
/** @var int $laundryId */
/** @var array<int,string> $accounts */
?>
<section class="page-head">
  <div class="page-head-row">
    <h1><?= te('laundry.menu.settings') ?></h1>
    <div class="page-actions">
      <a class="btn-secondary" href="/laundry/types"><?= te('laundry.menu.types') ?></a>
      <a class="btn-secondary" href="/laundry/accounts"><?= te('laundry.menu.accounts') ?></a>
      <a class="btn-secondary" href="/laundry/inventory"><?= te('laundry.menu.inventory') ?></a>
    </div>
  </div>
</section>
<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid form-wide">
  <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
  <fieldset>
    <legend><?= te('laundry.settings.ticket') ?></legend>
    <label><?= te('laundry.settings.ticket_id') ?>
      <input type="number" min="0" name="ticket_news_id" value="<?= (int)$ticketId ?>" required>
    </label>
    <?php if ($ticketId > 0): ?>
      <p class="muted">
        <?php if ($ticketTitle !== ''): ?>
          <a href="/news/<?= (int)$ticketId ?>"><?= e($ticketTitle) ?></a>
          (#<?= (int)$ticketId ?>)
        <?php else: ?>
          <a href="/news/<?= (int)$ticketId ?>">/news/<?= (int)$ticketId ?></a>
        <?php endif; ?>
      </p>
    <?php endif; ?>
    <p class="muted"><?= te('laundry.settings.ticket_hint') ?></p>
  </fieldset>
  <fieldset>
    <legend><?= te('laundry.settings.accounts') ?></legend>
    <label><?= te('laundry.settings.terms_account') ?>
      <select name="terms_account_id" required>
        <option value="">—</option>
        <?php foreach ($accounts as $aid => $aname): ?>
          <option value="<?= (int)$aid ?>"<?= $termsId === (int)$aid ? ' selected' : '' ?>><?= e($aname) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= te('laundry.settings.laundry_account') ?>
      <select name="laundry_account_id" required>
        <option value="">—</option>
        <?php foreach ($accounts as $aid => $aname): ?>
          <option value="<?= (int)$aid ?>"<?= $laundryId === (int)$aid ? ' selected' : '' ?>><?= e($aname) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </fieldset>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="/laundry"><?= te('core.common.cancel') ?></a>
  </div>
</form>
