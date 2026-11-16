<?php
declare(strict_types=1);
/** @var array<string,mixed> $client */
/** @var string $flash */
?>
<h2><?= te('client_portal.section.profile') ?></h2>
<?php if ($flash !== ''): ?>
  <p class="flash ok"><?= e($flash) ?></p>
<?php endif; ?>
<form method="post" class="form">
  <?= Csrf::field() ?>
  <label><?= te('client_portal.profile.name') ?>
    <input type="text" name="name" value="<?= e((string)($client['name'] ?? '')) ?>" required>
  </label>
  <label><?= te('client_portal.profile.tel') ?>
    <input type="tel" name="tel" value="<?= e((string)($client['tel'] ?? '')) ?>" required>
  </label>
  <label><?= te('client_portal.profile.mail') ?>
    <input type="email" name="mail" value="<?= e((string)($client['mail'] ?? '')) ?>">
  </label>
  <label><?= te('client_portal.profile.birthday') ?>
    <input type="date" name="birthday" value="<?= e((string)($client['birthday'] ?? '')) ?>">
  </label>
  <label><?= te('client_portal.profile.gender') ?>
    <select name="gender">
      <option value="">—</option>
      <option value="0"<?= (string)($client['gender'] ?? '') === '0' ? ' selected' : '' ?>><?= te('clients.gender.f') ?></option>
      <option value="1"<?= (string)($client['gender'] ?? '') === '1' ? ' selected' : '' ?>><?= te('clients.gender.m') ?></option>
    </select>
  </label>
  <label><?= te('client_portal.profile.password_new') ?>
    <input type="password" name="password_new" autocomplete="new-password">
  </label>
  <p class="muted"><?= te('client_portal.profile.password_hint') ?></p>
  <p><button type="submit" class="btn"><?= te('client_portal.profile.save') ?></button></p>
</form>
