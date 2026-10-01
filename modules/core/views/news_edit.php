<?php
/** @var bool $isNew */
/** @var array $row */
/** @var string $error */
/** @var array $statuses */
/** @var array $users */
/** @var array $folders */
/** @var bool $canDelete */
$type = (int)$row['TYPE'];
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
  <label class="check">
    <input type="checkbox" name="HAS_CHILDREN" value="1"<?= !empty($row['HAS_CHILDREN']) ? ' checked' : '' ?>>
    <?= te('core.news_edit.is_folder') ?>
  </label>
  <?php if ($type === 2): ?>
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
  <?php endif; ?>
  <?php if ($type === 3): ?>
  <label><?= te('core.news_edit.recipient') ?>
    <input name="ACCESS_USER" value="<?= e((string)$row['ACCESS_USER']) ?>">
  </label>
  <?php endif; ?>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="<?= $isNew ? '/news?type=' . $type : '/news/' . (int)($row['ID'] ?? 0) ?>"><?= te('core.common.cancel') ?></a>
  </div>
</form>

<?php if (!$isNew && !empty($canDelete)): ?>
<form method="post" class="danger-zone" action="/news/<?= (int)$row['ID'] ?>" onsubmit="return confirm(<?= tj('core.news_edit.confirm_delete') ?>);">
  <input type="hidden" name="action" value="delete">
  <button class="btn-danger" type="submit"><?= te('core.news_edit.delete') ?></button>
</form>
<?php endif; ?>
