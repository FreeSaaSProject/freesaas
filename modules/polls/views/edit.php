<?php
/** @var bool $isNew */
/** @var array<string,mixed> $row */
/** @var list<array<string,mixed>> $children */
/** @var int $elType */
/** @var bool $canEdit */
/** @var bool $hasProcedures */
/** @var string $message */
/** @var string $error */
$id = (int)($row['id'] ?? 0);
$chk = static function (string $key) use ($row): string {
    return !empty($row[$key]) ? ' checked' : '';
};
?>
<section class="page-head">
  <h1><?= $isNew ? te('polls.edit.heading_new') : e((string)($row['name'] ?? '')) ?></h1>
  <p class="muted"><?= e(PollsRepository::elTypeLabel($elType)) ?></p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid form-wide">
  <?php if ($isNew): ?>
    <input type="hidden" name="pid" value="<?= (int)($row['pid'] ?? 0) ?>">
    <input type="hidden" name="el_type" value="<?= (int)$elType ?>">
  <?php endif; ?>

  <fieldset>
    <legend><?= te('polls.edit.section_main') ?></legend>
    <label><?= te('polls.col.name') ?>
      <input name="name" value="<?= e((string)($row['name'] ?? '')) ?>" required>
    </label>
    <label><?= te('polls.col.num') ?>
      <input type="number" name="num" value="<?= e((string)($row['num'] ?? 0)) ?>">
    </label>
    <label class="check">
      <input type="checkbox" name="enabled" value="1"<?= $chk('enabled') ?>>
      <?= te('polls.col.enabled') ?>
    </label>
    <?php if ($elType === PollsRepository::EL_POLL): ?>
      <label><?= te('polls.col.who_can_pass') ?>
        <select name="who_can_pass">
          <?php foreach ([0, 1, 2] as $w): ?>
            <option value="<?= $w ?>"<?= (int)($row['who_can_pass'] ?? 0) === $w ? ' selected' : '' ?>><?= e(PollsRepository::whoCanPassLabel($w)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><?= te('polls.field.bonus_default') ?>
        <input type="number" name="bonus_default" min="0" value="<?= e((string)($row['bonus_default'] ?? 0)) ?>">
      </label>
      <label class="check">
        <input type="checkbox" name="bonus_auto" value="1"<?= $chk('bonus_auto') ?>>
        <?= te('polls.field.bonus_auto') ?>
      </label>
    <?php endif; ?>
  </fieldset>

  <fieldset>
    <legend><?= te('polls.edit.section_descr') ?></legend>
    <label><?= te('polls.field.descr_admin') ?>
      <textarea name="descr_admin" rows="3"><?= e((string)($row['descr_admin'] ?? '')) ?></textarea>
    </label>
    <label><?= te('polls.field.descr_site') ?>
      <textarea name="descr_site" rows="3"><?= e((string)($row['descr_site'] ?? '')) ?></textarea>
    </label>
  </fieldset>

  <?php if ($elType === PollsRepository::EL_QUESTION): ?>
  <fieldset>
    <legend><?= te('polls.edit.section_flags') ?></legend>
    <label class="check">
      <input type="checkbox" name="multy_opts" value="1"<?= $chk('multy_opts') ?>>
      <?= te('polls.field.multy_opts') ?>
    </label>
    <label><?= te('polls.field.req_text_len') ?>
      <input type="number" name="req_text_len" min="0" value="<?= e((string)($row['req_text_len'] ?? 0)) ?>">
    </label>
  </fieldset>
  <?php endif; ?>

  <?php if ($elType === PollsRepository::EL_ANSWER): ?>
  <fieldset>
    <legend><?= te('polls.edit.section_answer') ?></legend>
    <label><?= te('polls.field.next_qid') ?>
      <input type="number" name="next_qid" min="0" value="<?= e((string)($row['next_qid'] ?? 0)) ?>">
    </label>
    <label class="check">
      <input type="checkbox" name="ans_end_poll" value="1"<?= $chk('ans_end_poll') ?>>
      <?= te('polls.field.ans_end_poll') ?>
    </label>
    <label class="check">
      <input type="checkbox" name="negative" value="1"<?= $chk('negative') ?>>
      <?= te('polls.field.negative') ?>
    </label>
    <label><?= te('polls.field.qty') ?>
      <input type="number" name="qty" min="0" value="<?= e((string)($row['qty'] ?? 0)) ?>">
    </label>
    <label><?= te('polls.field.add2dtnext') ?>
      <input type="number" name="add2dtnext" min="0" value="<?= e((string)($row['add2dtnext'] ?? 0)) ?>">
    </label>
    <label><?= te('polls.field.status_on_sel') ?>
      <input type="number" name="status_on_sel" min="0" value="<?= e((string)($row['status_on_sel'] ?? 0)) ?>">
    </label>
  </fieldset>
  <?php endif; ?>

  <?php if ($elType === PollsRepository::EL_POLL): ?>
  <fieldset>
    <legend><?= te('polls.edit.section_flags') ?></legend>
    <label class="check">
      <input type="checkbox" name="store_to_user" value="1"<?= $chk('store_to_user') ?>>
      <?= te('polls.field.store_to_user') ?>
    </label>
    <label class="check">
      <input type="checkbox" name="show_in_tray" value="1"<?= $chk('show_in_tray') ?>>
      <?= te('polls.field.show_in_tray') ?>
    </label>
    <label><?= te('polls.field.tray_code') ?>
      <input name="tray_code" maxlength="10" value="<?= e((string)($row['tray_code'] ?? '')) ?>">
    </label>
    <label class="check">
      <input type="checkbox" name="multy_answer" value="1"<?= $chk('multy_answer') ?>>
      <?= te('polls.field.multy_answer') ?>
    </label>
    <label class="check">
      <input type="checkbox" name="show_history" value="1"<?= $chk('show_history') ?>>
      <?= te('polls.field.show_history') ?>
    </label>
    <label class="check">
      <input type="checkbox" name="not_repass" value="1"<?= $chk('not_repass') ?>>
      <?= te('polls.field.not_repass') ?>
    </label>
    <label class="check">
      <input type="checkbox" name="is_special" value="1"<?= $chk('is_special') ?>>
      <?= te('polls.field.is_special') ?>
    </label>
    <label class="check">
      <input type="checkbox" name="is_special_friend" value="1"<?= $chk('is_special_friend') ?>>
      <?= te('polls.field.is_special_friend') ?>
    </label>
    <label class="check">
      <input type="checkbox" name="is_result" value="1"<?= $chk('is_result') ?>>
      <?= te('polls.field.is_result') ?>
    </label>
  </fieldset>

  <fieldset>
    <legend><?= te('polls.edit.section_init') ?></legend>
    <label class="check">
      <input type="checkbox" name="after_event" value="1"<?= $chk('after_event') ?>>
      <?= te('polls.field.after_event') ?>
    </label>
    <label><?= te('polls.field.after_event_time') ?>
      <input type="number" name="after_event_time" min="0" value="<?= e((string)($row['after_event_time'] ?? 0)) ?>">
    </label>
    <label><?= te('polls.field.after_event_expire') ?>
      <input type="number" name="after_event_expire_time" min="0" value="<?= e((string)($row['after_event_expire_time'] ?? 0)) ?>">
    </label>
    <label class="check">
      <input type="checkbox" name="after_event_stop_rew" value="1"<?= $chk('after_event_stop_rew') ?>>
      <?= te('polls.field.after_event_stop_rew') ?>
    </label>
    <?php if ($hasProcedures): ?>
    <label><?= te('polls.field.types') ?>
      <input name="types" value="<?= e((string)($row['types'] ?? '')) ?>">
    </label>
    <?php else: ?>
    <input type="hidden" name="types" value="<?= e((string)($row['types'] ?? '')) ?>">
    <?php endif; ?>
  </fieldset>
  <?php endif; ?>

  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
    <?php
      $back = '/polls';
      if ((int)($row['pid'] ?? 0) > 0) {
          $back = '/polls/' . (int)$row['pid'] . '/edit';
      }
    ?>
    <a class="btn-secondary" href="<?= e($back) ?>"><?= te('core.common.cancel') ?></a>
  </div>
</form>

<?php if (!$isNew && $elType !== PollsRepository::EL_ANSWER): ?>
<section class="panel" style="margin-top:1.5rem">
  <div class="page-head-row">
    <h2><?= te('polls.edit.children') ?></h2>
    <div class="page-actions">
      <?php if ($elType === PollsRepository::EL_POLL): ?>
        <a class="btn-secondary" href="/polls/new?pid=<?= $id ?>&el_type=1"><?= te('polls.edit.add_child_q') ?></a>
      <?php elseif ($elType === PollsRepository::EL_QUESTION): ?>
        <a class="btn-secondary" href="/polls/new?pid=<?= $id ?>&el_type=2"><?= te('polls.edit.add_child_a') ?></a>
      <?php endif; ?>
    </div>
  </div>
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr>
          <th><?= te('polls.col.id') ?></th>
          <th><?= te('polls.col.el_type') ?></th>
          <th><?= te('polls.col.name') ?></th>
          <th><?= te('polls.col.num') ?></th>
          <th><?= te('polls.col.enabled') ?></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($children as $c): ?>
        <tr>
          <td class="muted"><a href="/polls/<?= (int)$c['id'] ?>/edit"><?= (int)$c['id'] ?></a></td>
          <td><?= e(PollsRepository::elTypeLabel((int)$c['el_type'])) ?></td>
          <td><?= e((string)$c['name']) ?></td>
          <td><?= (int)$c['num'] ?></td>
          <td><?= !empty($c['enabled']) ? te('core.common.yes') : te('core.common.no') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$children): ?>
        <tr><td colspan="5" class="muted"><?= te('polls.list.empty') ?></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>
