<?php
declare(strict_types=1);
/** @var string $prefix form name prefix, e.g. targets[0] */
/** @var array<string,mixed> $row from NotifyRuleForm::targetToRow (or blank) */
$row = is_array($row ?? null) ? $row : [];
$kind = (string)($row['kind'] ?? '');
$channels = (array)($row['channels'] ?? ['bot']);
$strategy = (string)($row['strategy'] ?? 'all');
$platform = (string)($row['platform'] ?? '');
?>
<div class="notify-row form-row-inline">
  <label><?= te('notify.target.kind') ?>
    <select name="<?= e($prefix) ?>[kind]">
      <option value=""></option>
      <?php foreach (NotifyRulesRepository::TARGET_KINDS as $k): ?>
        <option value="<?= e($k) ?>"<?= $kind === $k ? ' selected' : '' ?>><?= te('notify.target.kind.' . $k) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('notify.target.value') ?>
    <input name="<?= e($prefix) ?>[value]" value="<?= e((string)($row['value'] ?? '')) ?>" autocomplete="off">
  </label>
  <label><?= te('notify.target.platform') ?>
    <select name="<?= e($prefix) ?>[platform]">
      <option value=""><?= te('notify.target.platform_default') ?></option>
      <?php foreach (['max', 'telegram'] as $pl): ?>
        <option value="<?= e($pl) ?>"<?= $platform === $pl ? ' selected' : '' ?>><?= e($pl) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <fieldset class="notify-channels">
    <legend><?= te('notify.send.channels') ?></legend>
    <?php foreach (NotifyRulesRepository::CHANNELS as $ch): ?>
      <label class="check"><input type="checkbox" name="<?= e($prefix) ?>[channels][]" value="<?= e($ch) ?>"<?= in_array($ch, $channels, true) ? ' checked' : '' ?>> <?= te('notify.channel.' . $ch) ?></label>
    <?php endforeach; ?>
  </fieldset>
  <label><?= te('notify.target.strategy') ?>
    <select name="<?= e($prefix) ?>[strategy]">
      <?php foreach (NotifyRulesRepository::STRATEGIES as $s): ?>
        <option value="<?= e($s) ?>"<?= $strategy === $s ? ' selected' : '' ?>><?= te('notify.target.strategy.' . $s) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
</div>
