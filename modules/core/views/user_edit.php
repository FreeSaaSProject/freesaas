<?php
/** @var array $user */
/** @var bool $isNew */
/** @var string $message */
/** @var string $error */
/** @var bool $isSuper */
/** @var array<int,string> $adminStatuses */
/** @var array<int,string> $partnerStatuses */
/** @var array<string,array{name:string,keys:list<string>}> $permGroups */
/** @var array<string,int> $userPerms */
/** @var list<array{id:int,login:string,name:string}> $copyUsers */
/** @var string $genPass */
/** @var int $favoritesCount */
/** @var string $extraHtml */
/** @var string $extraAccessHtml */

$isNew = !empty($isNew);
$uid = (int)($user['id'] ?? 0);
$extraHtml = (string)($extraHtml ?? '');
$extraAccessHtml = (string)($extraAccessHtml ?? '');
$color = trim((string)($user['color'] ?? ''));
if ($color === '' || ($color[0] ?? '') !== '#') {
    $colorHex = '#64748b';
} else {
    $colorHex = substr($color, 0, 7);
}
$colorText = trim((string)($user['color_text'] ?? ''));
if ($colorText === '' || ($colorText[0] ?? '') !== '#') {
    $colorTextHex = '#000000';
} else {
    $colorTextHex = substr($colorText, 0, 7);
}

$expireVal = '';
$expireTs = (int)($user['expire'] ?? 0);
if ($expireTs > 0) {
    $expireVal = date('Y-m-d\TH:i', $expireTs);
}
$newsAfterVal = '';
$newsAfterTs = (int)($user['showNewsAfter'] ?? 0);
if ($newsAfterTs > 0) {
    $newsAfterVal = date('Y-m-d\TH:i', $newsAfterTs);
}
?>
<section class="page-head">
  <h1><?= $isNew ? te('core.user_edit.title_new') : te('core.user_edit.heading') ?></h1>
  <?php if (!$isNew): ?>
  <p class="lede"><code><?= e((string)$user['login']) ?></code></p>
  <?php endif; ?>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid form-wide user-edit-form" id="user-edit-form">

  <h2 class="form-section-title"><?= te('core.user_edit.sec_personal') ?></h2>
  <?php if ($isNew): ?>
  <label><?= te('core.user_edit.login') ?>
    <input name="login" value="<?= e((string)($user['login'] ?? '')) ?>" autocomplete="username" placeholder="<?= te('core.user_edit.login_optional') ?>">
  </label>
  <p class="form-hint"><?= te('core.user_edit.login_hint') ?></p>
  <?php endif; ?>
  <label><?= te('core.user_edit.full_name') ?>
    <input name="name" value="<?= e((string)($user['name'] ?? '')) ?>">
  </label>
  <label><?= te('core.user_edit.email') ?>
    <input name="mail" type="email" value="<?= e((string)$user['mail']) ?>">
  </label>
  <label><?= te('core.user_edit.phone') ?>
    <input name="tel" value="<?= e((string)$user['tel']) ?>" placeholder="9XXXXXXXXX">
  </label>
  <?php if (Database::columnExists('base_users', 'num')): ?>
  <label><?= te('core.user_edit.num') ?>
    <input type="number" name="num" min="0" step="1" value="<?= (int)($user['num'] ?? 0) ?>">
  </label>
  <p class="form-hint"><?= te('core.user_edit.num_hint') ?></p>
  <?php endif; ?>
  <label><?= te('core.user_edit.locale') ?>
    <select name="locale">
      <?php foreach (I18n::supported() as $loc): ?>
        <option value="<?= e($loc) ?>" <?= ((string)($user['locale'] ?? I18n::locale()) === $loc) ? 'selected' : '' ?>><?= e(strtoupper($loc)) ?></option>
      <?php endforeach; ?>
    </select>
  </label>

  <?php if ($isNew || $isSuper): ?>
  <h2 class="form-section-title"><?= te('core.user_edit.sec_password') ?></h2>
  <label><?= $isNew ? te('core.user_edit.password') : te('core.user_edit.new_password') ?>
    <div class="input-row">
      <input type="password" name="password" id="password" autocomplete="new-password" placeholder="<?= $isNew ? te('core.user_edit.password_optional') : '' ?>">
      <button class="btn-secondary" type="button" id="gen-pass" data-pass="<?= e($genPass) ?>"><?= te('core.user_edit.generate') ?></button>
    </div>
  </label>
  <?php if ($isNew): ?>
  <p class="form-hint"><?= te('core.user_edit.password_optional_hint') ?></p>
  <?php endif; ?>
  <?php if ($isSuper && !$isNew): ?>
  <label><?= te('core.user_edit.expires') ?>
    <input type="datetime-local" name="expire" value="<?= e($expireVal) ?>">
  </label>
  <p class="form-hint"><?= te('core.user_edit.expires_hint') ?></p>
  <label><?= te('core.user_edit.retries') ?>
    <input type="number" name="retries" min="0" value="<?= (int)($user['retries'] ?? 0) ?>">
  </label>
  <?php endif; ?>
  <?php endif; ?>

  <h2 class="form-section-title"><?= te('core.user_edit.sec_colors') ?></h2>
  <label><?= te('core.user_edit.color_bg') ?>
    <div class="color-row">
      <input type="color" id="color_picker" value="<?= e($colorHex) ?>" aria-label="<?= te('core.user_edit.color_bg_picker') ?>">
      <input name="color" id="color_text_input" value="<?= e((string)($user['color'] ?? '')) ?>" placeholder="#334155">
    </div>
  </label>
  <label><?= te('core.user_edit.color_text') ?>
    <div class="color-row">
      <input type="color" id="color_text_picker" value="<?= e($colorTextHex) ?>" aria-label="<?= te('core.user_edit.color_text_picker') ?>">
      <input name="color_text" id="color_text_value" value="<?= e((string)($user['color_text'] ?? '')) ?>" placeholder="#000000">
    </div>
  </label>

  <?= $extraHtml ?>

  <h2 class="form-section-title"><?= te('core.user_edit.sec_access') ?></h2>
  <label class="check">
    <input type="checkbox" name="enabled" value="1"<?= ((string)($user['enabled'] ?? '1') === '1') ? ' checked' : '' ?>>
    <?= te('core.user_edit.active') ?>
  </label>
  <label class="check">
    <input type="checkbox" name="ASSIST_TEAM" value="1"<?= !empty($user['ASSIST_TEAM']) ? ' checked' : '' ?>>
    <?= te('core.user_edit.assist_team') ?>
  </label>
  <label class="check">
    <input type="checkbox" name="NOT_IN_TEAM" value="1"<?= !empty($user['NOT_IN_TEAM']) ? ' checked' : '' ?>>
    <?= te('core.user_edit.not_in_team') ?>
  </label>
  <label class="check">
    <input type="checkbox" name="IS_HIDDEN" value="1"<?= !empty($user['IS_HIDDEN']) ? ' checked' : '' ?>>
    <?= te('core.user_edit.hidden_mode') ?>
  </label>
  <?= $extraAccessHtml ?>

  <label><?= te('core.user_edit.admin_status') ?>
    <select name="ID_STATUS">
      <?php foreach ($adminStatuses as $sid => $name): ?>
        <option value="<?= (int)$sid ?>"<?= (int)$user['ID_STATUS'] === (int)$sid ? ' selected' : '' ?>><?= e($name) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <?php if ($partnerStatuses): ?>
  <label><?= te('core.user_edit.partner_status') ?>
    <select name="ID_STATUS2">
      <option value="0">—</option>
      <?php foreach ($partnerStatuses as $sid => $name): ?>
        <option value="<?= (int)$sid ?>"<?= (int)($user['ID_STATUS2'] ?? 0) === (int)$sid ? ' selected' : '' ?>><?= e($name) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <?php endif; ?>

  <h2 class="form-section-title"><?= te('core.user_edit.sec_news') ?></h2>
  <label><?= te('core.user_edit.news_after') ?>
    <input type="datetime-local" name="showNewsAfter" value="<?= e($newsAfterVal) ?>">
  </label>
  <label class="check">
    <input type="checkbox" name="clear_favorites" value="1">
    <?= te('core.user_edit.clear_favorites') ?><?= $favoritesCount > 0 ? ' (' . (int)$favoritesCount . ')' : '' ?>
  </label>

  <?php if ($permGroups): ?>
  <h2 class="form-section-title"><?= te('core.user_edit.sec_perms') ?></h2>
  <p class="form-hint"><?= te('core.user_edit.perms_hint') ?></p>
  <?php
    $editBase = $isNew ? '/users/new' : ('/users/' . $uid . '/edit');
  ?>
  <div class="perm-toolbar">
    <a class="btn-ghost" href="<?= e($editBase) ?>?turnon=1"><?= te('core.user_edit.perms_all_on_btn') ?></a>
    <a class="btn-ghost" href="<?= e($editBase) ?>?turnon=0"><?= te('core.user_edit.perms_all_off_btn') ?></a>
  </div>
  <?php if ($copyUsers): ?>
  <label><?= te('core.user_edit.copy_perms') ?>
    <select onchange="if(this.value) location.href=<?= json_encode($editBase, JSON_HEX_TAG | JSON_HEX_APOS) ?>+'?copyFrom='+this.value">
      <option value="">—</option>
      <?php foreach ($copyUsers as $cu): if ((int)$cu['id'] === $uid) continue; ?>
        <option value="<?= (int)$cu['id'] ?>"><?= e(($cu['name'] ?: $cu['login']) . ' (#' . $cu['id'] . ')') ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <?php endif; ?>
  <?php foreach ($permGroups as $modId => $group): ?>
    <fieldset class="check-fieldset">
      <legend><?= e(t((string)$group['name'])) ?></legend>
      <div class="check-grid">
        <?php foreach ($group['keys'] as $key):
          $pid = is_array($key) ? (string)$key['id'] : (string)$key;
          $plabel = is_array($key) ? (string)$key['label'] : (string)$key;
          ?>
          <label class="check" title="<?= e($pid) ?>">
            <input type="hidden" name="permissions[<?= e($pid) ?>]" value="0">
            <input type="checkbox" name="permissions[<?= e($pid) ?>]" value="1"<?= !empty($userPerms[$pid]) ? ' checked' : '' ?>>
            <span><?= e(t($plabel)) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
    </fieldset>
  <?php endforeach; ?>
  <?php endif; ?>

  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="<?= $isNew ? '/users' : ('/users/' . $uid) ?>"><?= te('core.common.cancel') ?></a>
  </div>
</form>

<script>
(function () {
  function bindColor(pickerId, textId) {
    var picker = document.getElementById(pickerId);
    var text = document.getElementById(textId);
    if (!picker || !text) return;
    picker.addEventListener('input', function () { text.value = picker.value; });
    text.addEventListener('input', function () {
      var v = text.value.trim();
      if (/^#[0-9a-fA-F]{6}$/.test(v)) picker.value = v;
    });
  }
  bindColor('color_picker', 'color_text_input');
  bindColor('color_text_picker', 'color_text_value');
  var gen = document.getElementById('gen-pass');
  var pass = document.getElementById('password');
  if (gen && pass) {
    gen.addEventListener('click', function () {
      var p = gen.getAttribute('data-pass') || '';
      pass.type = 'text';
      pass.value = p;
    });
  }
})();
</script>
