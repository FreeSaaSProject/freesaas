<?php
declare(strict_types=1);
/** @var array<string,mixed> $rule */
/** @var array<string,mixed>|null $def */
/** @var array<string,string> $events */
/** @var string $error */
$conditions = is_array($rule['conditions'] ?? null) ? $rule['conditions'] : [];
$targets = is_array($rule['targets'] ?? null) ? $rule['targets'] : [];
$stages = is_array($rule['stages'] ?? null) ? $rule['stages'] : [];
while (count($conditions) < 1) {
    $conditions[] = ['field' => '', 'op' => 'eq', 'value' => ''];
}
while (count($targets) < 1) {
    $targets[] = ['kind' => 'subject', 'value' => 'operator', 'channels' => ['bot'], 'strategy' => 'all', 'platform' => ''];
}
$fieldOpts = is_array($def['fields'] ?? null) ? $def['fields'] : [];
?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid form-wide">
  <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
  <input type="hidden" name="action" value="save">

  <fieldset>
    <legend><?= te('notify.rule.sec.main') ?></legend>
    <label><?= te('notify.rule.name') ?>
      <input type="text" name="name" required value="<?= e((string)$rule['name']) ?>" autocomplete="off">
    </label>
    <label><?= te('notify.rule.event') ?>
      <select name="event" required>
        <option value=""></option>
        <?php foreach ($events as $eid => $elabel): ?>
          <option value="<?= e($eid) ?>"<?= (string)$rule['event'] === $eid ? ' selected' : '' ?>><?= e($elabel) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><?= te('notify.rule.kind') ?>
      <select name="kind">
        <option value="notify"<?= ($rule['kind'] ?? '') === 'notify' ? ' selected' : '' ?>><?= te('notify.kind.notify') ?></option>
        <option value="confirm"<?= ($rule['kind'] ?? '') === 'confirm' ? ' selected' : '' ?>><?= te('notify.kind.confirm') ?></option>
      </select>
    </label>
    <label><?= te('notify.rule.sort') ?>
      <input type="number" name="sort" value="<?= (int)($rule['sort'] ?? 100) ?>">
    </label>
    <label class="check"><input type="checkbox" name="enabled" value="1"<?= !empty($rule['enabled']) ? ' checked' : '' ?>> <?= te('notify.rule.enabled') ?></label>
    <label class="check"><input type="checkbox" name="stop_on_match" value="1"<?= !empty($rule['stop_on_match']) ? ' checked' : '' ?>> <?= te('notify.rule.stop') ?></label>
  </fieldset>

  <fieldset>
    <legend><?= te('notify.rule.quiet') ?></legend>
    <div class="form-row-inline">
      <label><?= te('notify.settings.quiet_from') ?>
        <input type="text" name="quiet_from" placeholder="22:00" value="<?= e((string)($rule['quiet_from'] ?? '')) ?>" autocomplete="off">
      </label>
      <label><?= te('notify.settings.quiet_to') ?>
        <input type="text" name="quiet_to" placeholder="08:00" value="<?= e((string)($rule['quiet_to'] ?? '')) ?>" autocomplete="off">
      </label>
    </div>
  </fieldset>

  <fieldset>
    <legend><?= te('notify.rule.template') ?></legend>
    <label><?= te('notify.rule.template') ?>
      <textarea name="template" rows="5"><?= e((string)($rule['template'] ?? '')) ?></textarea>
    </label>
    <p class="muted"><?= te('notify.rule.template_hint') ?></p>
  </fieldset>

  <fieldset>
    <legend><?= te('notify.rule.conditions') ?></legend>
    <p class="muted"><?= te('notify.rule.conditions_hint') ?></p>
    <?php foreach ($conditions as $i => $c): ?>
      <div class="notify-row form-row-inline">
        <label><?= te('notify.rule.field') ?>
          <?php if ($fieldOpts !== []): ?>
            <select name="conditions[<?= (int)$i ?>][field]">
              <option value=""></option>
              <?php foreach ($fieldOpts as $f):
                  $fid = (string)($f['id'] ?? '');
                  if ($fid === '') {
                      continue;
                  }
                  $flabel = (string)($f['label'] ?? $fid);
                  $flabel = strpos($flabel, '.') !== false ? t($flabel) : $flabel;
              ?>
                <option value="<?= e($fid) ?>"<?= (($c['field'] ?? '') === $fid) ? ' selected' : '' ?>><?= e($flabel) ?></option>
              <?php endforeach; ?>
            </select>
          <?php else: ?>
            <input name="conditions[<?= (int)$i ?>][field]" value="<?= e((string)($c['field'] ?? '')) ?>" autocomplete="off">
          <?php endif; ?>
        </label>
        <label><?= te('notify.rule.op') ?>
          <select name="conditions[<?= (int)$i ?>][op]">
            <?php foreach (NotifyCatalog::OPS as $op): ?>
              <option value="<?= e($op) ?>"<?= (($c['op'] ?? 'eq') === $op) ? ' selected' : '' ?>><?= e($op) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label><?= te('notify.rule.value') ?>
          <input name="conditions[<?= (int)$i ?>][value]" value="<?= e(is_array($c['value'] ?? null) ? implode(',', $c['value']) : (string)($c['value'] ?? '')) ?>" autocomplete="off">
        </label>
      </div>
    <?php endforeach; ?>
  </fieldset>

  <fieldset>
    <legend><?= te('notify.rule.targets') ?></legend>
    <p class="muted"><?= te('notify.rule.targets_hint') ?></p>
    <?php foreach ($targets as $i => $t): ?>
      <?php
        $row = class_exists('NotifyRuleForm', false) ? NotifyRuleForm::targetToRow($t) : $t;
        $prefix = 'targets[' . (int)$i . ']';
        require base_path('modules/notify/views/_target_row.php');
      ?>
    <?php endforeach; ?>
  </fieldset>

  <fieldset>
    <legend><?= te('notify.rule.stages') ?></legend>
    <p class="muted"><?= te('notify.rule.stages_hint') ?></p>
    <?php
    $stages = $stages !== [] ? $stages : [['key' => 'call', 'anchor' => 'now', 'offset_sec' => 0, 'retry_sec' => 0, 'max_attempts' => 1, 'expire_sec' => 86400, 'outcomes' => ['confirmed', 'no_answer', 'client_cancelled'], 'template' => '']];
    foreach ($stages as $i => $st):
        $offsetMin = (int)round(((int)($st['offset_sec'] ?? 0)) / 60);
        $retryMin = (int)round(((int)($st['retry_sec'] ?? 0)) / 60);
        $expireMin = (int)round(((int)($st['expire_sec'] ?? 86400)) / 60);
    ?>
      <div class="notify-row form-row-inline">
        <label><?= te('notify.rule.stage_key') ?>
          <input name="stages[<?= (int)$i ?>][key]" value="<?= e((string)($st['key'] ?? '')) ?>" autocomplete="off">
        </label>
        <label><?= te('notify.rule.stage_anchor') ?>
          <select name="stages[<?= (int)$i ?>][anchor]">
            <option value="now"<?= (($st['anchor'] ?? '') === 'now') ? ' selected' : '' ?>>now</option>
            <option value="event"<?= (($st['anchor'] ?? '') === 'event') ? ' selected' : '' ?>>event</option>
          </select>
        </label>
        <label><?= te('notify.rule.stage_offset_min') ?>
          <input type="number" name="stages[<?= (int)$i ?>][offset_min]" value="<?= $offsetMin ?>">
        </label>
        <label><?= te('notify.rule.stage_retry_min') ?>
          <input type="number" name="stages[<?= (int)$i ?>][retry_min]" value="<?= $retryMin ?>">
        </label>
        <label><?= te('notify.rule.stage_max') ?>
          <input type="number" name="stages[<?= (int)$i ?>][max_attempts]" value="<?= (int)($st['max_attempts'] ?? 1) ?>">
        </label>
        <label><?= te('notify.rule.stage_expire_min') ?>
          <input type="number" name="stages[<?= (int)$i ?>][expire_min]" value="<?= $expireMin ?>">
        </label>
        <label><?= te('notify.rule.stage_outcomes') ?>
          <input name="stages[<?= (int)$i ?>][outcomes]" value="<?= e(implode(',', (array)($st['outcomes'] ?? []))) ?>" autocomplete="off">
        </label>
        <label><?= te('notify.rule.stage_template') ?>
          <input name="stages[<?= (int)$i ?>][template]" value="<?= e((string)($st['template'] ?? '')) ?>" autocomplete="off">
        </label>
      </div>
    <?php endforeach; ?>
  </fieldset>

  <div class="page-actions">
    <button type="submit" class="btn-primary"><?= te('core.common.save') ?></button>
    <a class="btn-ghost" href="/notify/rules"><?= te('core.common.cancel') ?></a>
  </div>
</form>
