<?php
declare(strict_types=1);
/** @var bool $isNew */
/** @var array<string,mixed> $client */
/** @var string $message */
/** @var string $error */
/** @var array<int,string> $statuses */
/** @var list<array{id:int,name:string,login:string}> $staffOptions */
/** @var array<int,string> $tagOptions */
/** @var list<int> $selectedTagIds */
/** @var string $extraHtml */
$cid = (int)($client['id'] ?? 0);
$gender = isset($client['gender']) ? (string)$client['gender'] : '';
$selectedTagIds = $selectedTagIds ?? [];
$tagOptions = $tagOptions ?? [];
$extraHtml = (string)($extraHtml ?? '');
?>
<section class="page-head">
  <h1><?= $isNew ? te('clients.edit.title_new') : te('clients.edit.heading') ?></h1>
  <p class="lede"><?= te('clients.edit.lede') ?></p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid form-wide user-edit-form">
  <h2 class="form-section-title"><?= te('clients.edit.sec_main') ?></h2>
  <label><?= te('clients.edit.full_name') ?>
    <input name="name" value="<?= e((string)($client['name'] ?? '')) ?>" required>
  </label>
  <label><?= te('clients.edit.phone') ?>
    <input name="tel" value="<?= e((string)($client['tel'] ?? '')) ?>" required placeholder="9XXXXXXXXX">
  </label>
  <label><?= te('clients.edit.email') ?>
    <input name="mail" type="email" value="<?= e((string)($client['mail'] ?? '')) ?>">
  </label>
  <label><?= te('clients.edit.gender') ?>
    <select name="gender">
      <option value="">—</option>
      <option value="0"<?= $gender === '0' ? ' selected' : '' ?>><?= te('clients.gender.f') ?></option>
      <option value="1"<?= $gender === '1' ? ' selected' : '' ?>><?= te('clients.gender.m') ?></option>
    </select>
  </label>
  <label><?= te('clients.edit.birthday') ?>
    <input type="date" name="birthday" value="<?= e((string)($client['birthday'] ?? '')) ?>">
  </label>
  <label><?= te('clients.edit.status') ?>
    <select name="status_id">
      <option value="0">—</option>
      <?php foreach ($statuses as $sid => $sname): ?>
        <option value="<?= (int)$sid ?>"<?= (int)($client['status_id'] ?? 0) === (int)$sid ? ' selected' : '' ?>><?= e($sname) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('clients.edit.staff_user') ?>
    <select name="staff_user_id">
      <option value="">—</option>
      <?php foreach ($staffOptions as $u): ?>
        <option value="<?= (int)$u['id'] ?>"<?= (int)($client['staff_user_id'] ?? 0) === (int)$u['id'] ? ' selected' : '' ?>>
          <?= e(($u['name'] ?: $u['login']) . ' (#' . $u['id'] . ')') ?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>
  <label class="check">
    <input type="checkbox" name="enabled" value="1"<?= !empty($client['enabled']) ? ' checked' : '' ?>>
    <?= te('clients.edit.active') ?>
  </label>
  <label class="check">
    <input type="checkbox" name="subscribe" value="1"<?= !empty($client['subscribe']) ? ' checked' : '' ?>>
    <?= te('clients.edit.subscribe') ?>
  </label>
  <label><?= te('clients.edit.site_password') ?>
    <input type="password" name="password" autocomplete="new-password">
  </label>

  <?php if ($tagOptions): ?>
  <h2 class="form-section-title"><?= te('clients.edit.tags') ?></h2>
  <div class="check-grid">
    <?php foreach ($tagOptions as $tid => $tname): ?>
      <label class="check">
        <input type="checkbox" name="tag_ids[]" value="<?= (int)$tid ?>"<?= in_array((int)$tid, $selectedTagIds, true) ? ' checked' : '' ?>>
        <?= e($tname) ?>
      </label>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <h2 class="form-section-title"><?= te('clients.edit.sec_passport') ?></h2>
  <label><?= te('clients.edit.surname') ?>
    <input name="surname" value="<?= e((string)($client['surname'] ?? '')) ?>">
  </label>
  <label><?= te('clients.edit.first_name') ?>
    <input name="pname" value="<?= e((string)($client['pname'] ?? '')) ?>">
  </label>
  <label><?= te('clients.edit.patronymic') ?>
    <input name="patronymic" value="<?= e((string)($client['patronymic'] ?? '')) ?>">
  </label>
  <label><?= te('clients.edit.passport_serie') ?>
    <input name="passport_serie" maxlength="4" value="<?= e((string)($client['passport_serie'] ?? '')) ?>">
  </label>
  <label><?= te('clients.edit.passport_number') ?>
    <input name="passport_number" maxlength="6" value="<?= e((string)($client['passport_number'] ?? '')) ?>">
  </label>
  <label><?= te('clients.edit.passport_issued_by') ?>
    <input name="passport_issued_by" value="<?= e((string)($client['passport_issued_by'] ?? '')) ?>">
  </label>
  <label><?= te('clients.edit.passport_issued_at') ?>
    <input name="passport_issued_at" value="<?= e((string)($client['passport_issued_at'] ?? '')) ?>">
  </label>
  <label><?= te('clients.edit.passport_birthday') ?>
    <input name="passport_birthday" value="<?= e((string)($client['passport_birthday'] ?? '')) ?>">
  </label>
  <label><?= te('clients.edit.address_passport') ?>
    <input name="address_passport" value="<?= e((string)($client['address_passport'] ?? '')) ?>">
  </label>
  <label><?= te('clients.edit.address_fact') ?>
    <input name="address_fact" value="<?= e((string)($client['address_fact'] ?? '')) ?>">
  </label>

  <h2 class="form-section-title"><?= te('clients.edit.sec_extra') ?></h2>
  <label><?= te('clients.edit.notes') ?>
    <textarea name="notes" rows="5"><?= e((string)($client['notes'] ?? '')) ?></textarea>
  </label>

  <?= $extraHtml ?>

  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="<?= $isNew ? '/clients' : '/clients/' . $cid ?>"><?= te('core.common.cancel') ?></a>
  </div>
</form>
