<?php
/** @var bool $isNew */
/** @var array $row */
/** @var string $error */
/** @var array $statuses */
/** @var array $users */
/** @var array $folders */
/** @var array<int,string> $staffGroups */
/** @var array<int,string> $partnerGroups */
/** @var bool $canDelete */
/** @var bool $folderLocked */
/** @var int $childCount */
$type = (int)$row['TYPE'];
$folderLocked = !empty($folderLocked);
$childCount = (int)($childCount ?? 0);
$isFolderRow = $folderLocked || !empty($row['HAS_CHILDREN']);
$staffGroups = $staffGroups ?? [];
$partnerGroups = $partnerGroups ?? [];
$accessGroupSel = NewsRepository::parseUserList((string)($row['ACCESS_GROUP'] ?? ''));
$accessGroup2Sel = NewsRepository::parseUserList((string)($row['ACCESS_GROUP2'] ?? ''));
$accessUserSel = NewsRepository::parseUserList((string)($row['ACCESS_USER'] ?? ''));
$newTitleKeys = [
    1 => 'core.news_edit.title_new_1',
    2 => 'core.news_edit.title_new_2',
    3 => 'core.news_edit.title_new_3',
];
?>
<section class="page-head">
  <h1><?= $isNew ? te($newTitleKeys[$type] ?? 'core.news_edit.title_new') : te('core.news_edit.title_edit') ?></h1>
</section>

<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid form-wide">
  <input type="hidden" name="TYPE" value="<?= $type ?>">
  <label><?= te('core.news_edit.name') ?>
    <input name="NAME" required value="<?= e((string)$row['NAME']) ?>">
  </label>
  <label><?= te('core.news_edit.short') ?>
    <input name="SHORT" value="<?= e((string)$row['SHORT']) ?>">
  </label>
  <div class="field-block">
    <span class="field-label"><?= te('core.news_edit.body') ?></span>
    <div class="rte" data-rte>
      <div class="rte-bar" role="toolbar" aria-label="<?= te('core.rte.toolbar') ?>">
        <button type="button" class="rte-btn" data-cmd="bold" title="<?= te('core.rte.bold') ?>"><b>B</b></button>
        <button type="button" class="rte-btn" data-cmd="italic" title="<?= te('core.rte.italic') ?>"><i>I</i></button>
        <button type="button" class="rte-btn" data-cmd="underline" title="<?= te('core.rte.underline') ?>"><u>U</u></button>
        <span class="rte-sep"></span>
        <button type="button" class="rte-btn" data-cmd="insertUnorderedList" title="<?= te('core.rte.ul') ?>">• <?= te('core.rte.list') ?></button>
        <button type="button" class="rte-btn" data-cmd="insertOrderedList" title="<?= te('core.rte.ol') ?>">1. <?= te('core.rte.list') ?></button>
        <button type="button" class="rte-btn" data-cmd="formatBlock" data-value="blockquote" title="<?= te('core.rte.quote') ?>">«»</button>
        <button type="button" class="rte-btn" data-cmd="formatBlock" data-value="h2" title="<?= te('core.rte.heading') ?>">H2</button>
        <span class="rte-sep"></span>
        <button type="button" class="rte-btn" data-cmd="createLink" title="<?= te('core.rte.link_title') ?>"><?= te('core.rte.link') ?></button>
        <button type="button" class="rte-btn" data-cmd="removeFormat" title="<?= te('core.rte.clear_title') ?>"><?= te('core.rte.clear') ?></button>
        <span class="rte-spacer"></span>
        <div class="rte-modes">
          <button type="button" class="rte-mode is-active" data-rte-mode="visual"><?= te('core.rte.mode_visual') ?></button>
          <button type="button" class="rte-mode" data-rte-mode="source"><?= te('core.rte.mode_source') ?></button>
        </div>
      </div>
      <div class="rte-visual" contenteditable="true" spellcheck="true"></div>
      <textarea name="BIG" class="rte-source" rows="12" hidden><?= e((string)$row['BIG']) ?></textarea>
    </div>
  </div>
  <label><?= te('core.news_edit.folder') ?>
    <select name="PID">
      <option value="0"><?= te('core.news_edit.folder_root') ?></option>
      <?php foreach ($folders as $f): ?>
        <option value="<?= (int)$f['ID'] ?>"<?= (int)($row['PID'] ?? 0) === (int)$f['ID'] ? ' selected' : '' ?>>
          <?= e((string)$f['NAME']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>
  <?php if ($folderLocked): ?>
    <input type="hidden" name="HAS_CHILDREN" value="1">
    <label class="check">
      <input type="checkbox" value="1" checked disabled>
      <?= te('core.news_edit.is_folder') ?>
    </label>
    <p class="muted"><?= te('core.news_edit.folder_locked', ['n' => $childCount]) ?></p>
  <?php else: ?>
    <label class="check">
      <input type="checkbox" name="HAS_CHILDREN" value="1"<?= !empty($row['HAS_CHILDREN']) ? ' checked' : '' ?> data-folder-flag>
      <?= te('core.news_edit.is_folder') ?>
    </label>
  <?php endif; ?>
  <label class="check" data-archive-folder-wrap<?= $isFolderRow ? '' : ' hidden' ?>>
    <input type="checkbox" name="IGNORE_FOLDER" value="1"<?= !empty($row['IGNORE_FOLDER']) ? ' checked' : '' ?>>
    <?= te('core.news_edit.archive_folder') ?>
  </label>
  <p class="muted" data-archive-folder-help<?= $isFolderRow ? '' : ' hidden' ?>><?= te('core.news_edit.archive_folder_help') ?></p>

  <div class="field-block" data-ticket-fields<?= ($type === 2 && !$isFolderRow) ? '' : ' hidden' ?>>
    <span class="field-label"><?= te('core.news_edit.ticket_fields') ?></span>
    <label><?= te('core.news_edit.status') ?>
      <select name="STATUS">
        <?php foreach ($statuses as $sid => $st): ?>
          <option value="<?= (int)$sid ?>"<?= (int)$row['STATUS'] === (int)$sid ? ' selected' : '' ?>><?= e($st['NAME']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= te('core.news_edit.curator') ?>
      <select name="ID_CURATOR">
        <option value="0">—</option>
        <?php foreach ($users as $u): ?>
          <option value="<?= (int)$u['id'] ?>"<?= (int)$row['ID_CURATOR'] === (int)$u['id'] ? ' selected' : '' ?>>
            <?= e($u['name'] ?: $u['login']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= te('core.news_edit.responsible') ?></label>
    <fieldset class="resp-pick">
      <input type="hidden" name="users_resp_present" value="1">
      <div class="resp-checks">
        <?php
          $respSel = NewsRepository::parseUserList((string)($row['USERS_RESP'] ?? ''));
          foreach ($users as $u):
        ?>
          <label class="check">
            <input type="checkbox" name="users_resp[]" value="<?= (int)$u['id'] ?>"
              <?= in_array((int)$u['id'], $respSel, true) ? ' checked' : '' ?>>
            <?= UsersRepository::badgeHtml((int)$u['id'], false) ?>
            <span><?= e($u['name'] ?: $u['login']) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
    </fieldset>
    <label><?= te('core.news_edit.deadline') ?>
      <input type="date" name="DT_DEADLINE" value="<?= ((int)$row['DT_DEADLINE'] > 0) ? e(date('Y-m-d', (int)$row['DT_DEADLINE'])) : '' ?>">
    </label>
  </div>

  <fieldset class="resp-pick">
    <legend><?= te('core.news_edit.access_group') ?></legend>
    <p class="muted"><?= te('core.news_edit.access_group_help') ?></p>
    <div class="resp-checks"<?= $type === 3 ? ' hidden' : '' ?> data-access-groups>
      <?php foreach ($staffGroups as $sid => $name): ?>
        <label class="check">
          <input type="checkbox" name="ACCESS_GROUP[]" value="<?= (int)$sid ?>"
            <?= in_array((int)$sid, $accessGroupSel, true) ? ' checked' : '' ?>>
          <span><?= e($name) ?></span>
        </label>
      <?php endforeach; ?>
      <?php if (!$staffGroups): ?>
        <span class="muted">—</span>
      <?php endif; ?>
    </div>
  </fieldset>

  <fieldset class="resp-pick"<?= $type === 3 ? ' hidden' : '' ?> data-access-groups2>
    <legend><?= te('core.news_edit.access_group2') ?></legend>
    <p class="muted"><?= te('core.news_edit.access_group2_help') ?></p>
    <div class="resp-checks">
      <?php foreach ($partnerGroups as $sid => $name): ?>
        <label class="check">
          <input type="checkbox" name="ACCESS_GROUP2[]" value="<?= (int)$sid ?>"
            <?= in_array((int)$sid, $accessGroup2Sel, true) ? ' checked' : '' ?>>
          <span><?= e($name) ?></span>
        </label>
      <?php endforeach; ?>
      <?php if (!$partnerGroups): ?>
        <span class="muted">—</span>
      <?php endif; ?>
    </div>
  </fieldset>

  <fieldset class="resp-pick">
    <legend><?= te('core.news_edit.access_user') ?></legend>
    <p class="muted"><?= te('core.news_edit.access_user_help') ?></p>
    <div class="resp-checks">
      <?php foreach ($users as $u): ?>
        <label class="check">
          <input type="checkbox" name="ACCESS_USER[]" value="<?= (int)$u['id'] ?>"
            <?= in_array((int)$u['id'], $accessUserSel, true) ? ' checked' : '' ?>>
          <?= UsersRepository::badgeHtml((int)$u['id'], false) ?>
          <span><?= e($u['name'] ?: $u['login']) ?></span>
        </label>
      <?php endforeach; ?>
    </div>
  </fieldset>

  <label class="check" data-sign-req-wrap<?= $isFolderRow ? ' hidden' : '' ?>>
    <input type="checkbox" name="SIGN_REQ" value="1"<?= !empty($row['SIGN_REQ']) ? ' checked' : '' ?>>
    <?= te('core.news_edit.sign_req') ?>
  </label>
  <p class="muted" data-sign-req-help<?= $isFolderRow ? ' hidden' : '' ?>><?= te('core.news_edit.sign_req_help') ?></p>

  <?php if (!$isNew): ?>
  <label class="check">
    <input type="checkbox" name="keep_signs" value="1">
    <?= te('core.news_edit.keep_signs') ?>
  </label>
  <?php endif; ?>

  <?php
    $cancelHref = '/news?type=' . $type;
    if (!$isNew && !empty($row['HAS_CHILDREN'])) {
      $parentPid = (int)($row['PID'] ?? 0);
      $cancelHref = '/news?type=' . $type . ($parentPid > 0 ? '&pid=' . $parentPid : '');
    } elseif (!$isNew) {
      $cancelHref = '/news/' . (int)($row['ID'] ?? 0);
    } elseif ((int)($row['PID'] ?? 0) > 0) {
      $cancelHref = '/news?type=' . $type . '&pid=' . (int)$row['PID'];
    }
  ?>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="<?= e($cancelHref) ?>"><?= te('core.common.cancel') ?></a>
  </div>
</form>

<?php if (!$isNew && !empty($canDelete)): ?>
<form method="post" class="danger-zone" action="/news/<?= (int)$row['ID'] ?>" onsubmit="return confirm(<?= tj('core.news_edit.confirm_delete') ?>);">
  <input type="hidden" name="action" value="delete">
  <button class="btn-danger" type="submit"><?= te('core.news_edit.delete') ?></button>
</form>
<?php endif; ?>
<script>
(function () {
  var flag = document.querySelector('[data-folder-flag]');
  var archiveWrap = document.querySelector('[data-archive-folder-wrap]');
  var archiveHelp = document.querySelector('[data-archive-folder-help]');
  var ticket = document.querySelector('[data-ticket-fields]');
  var signWrap = document.querySelector('[data-sign-req-wrap]');
  var signHelp = document.querySelector('[data-sign-req-help]');
  var type = <?= (int)$type ?>;

  function syncFolder() {
    var on = flag ? !!flag.checked : <?= $isFolderRow ? 'true' : 'false' ?>;
    if (archiveWrap) archiveWrap.hidden = !on;
    if (archiveHelp) archiveHelp.hidden = !on;
    if (!on && archiveWrap) {
      var box = archiveWrap.querySelector('input[name="IGNORE_FOLDER"]');
      if (box) box.checked = false;
    }
    if (ticket) ticket.hidden = !(type === 2 && !on);
    if (signWrap) signWrap.hidden = on;
    if (signHelp) signHelp.hidden = on;
    if (on && signWrap) {
      var s = signWrap.querySelector('input[name="SIGN_REQ"]');
      if (s) s.checked = false;
    }
  }
  if (flag) flag.addEventListener('change', syncFolder);
  syncFolder();
})();
</script>
