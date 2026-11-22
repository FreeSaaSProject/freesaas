<?php
declare(strict_types=1);
/** @var string $message */
/** @var string $error */
/** @var list<array<string,mixed>> $rules */
/** @var array<string,string> $events */
/** @var list<array<string,mixed>> $presets */
/** @var list<array<string,mixed>> $chats */
$presets = is_array($presets ?? null) ? $presets : [];
$chats = is_array($chats ?? null) ? $chats : [];
?>
<section class="page-head">
  <p class="lede"><?= te('notify.rules.lede') ?></p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<div class="page-actions">
  <a class="btn-primary" href="/notify/rules/new"><?= te('notify.rules.new') ?></a>
  <a class="btn-ghost" href="/notify/send"><?= te('notify.menu.send') ?></a>
</div>

<?php if ($presets !== []): ?>
<form method="post" class="form-grid form-wide notify-presets">
  <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
  <input type="hidden" name="action" value="apply_preset">
  <fieldset>
    <legend><?= te('notify.presets.title') ?></legend>
    <p class="muted"><?= te('notify.presets.lede') ?></p>
    <label><?= te('notify.presets.pack') ?>
      <select name="pack" required>
        <option value=""></option>
        <option value="all_satisfied"><?= te('notify.presets.all_satisfied') ?></option>
        <?php foreach ($presets as $p): ?>
          <option value="<?= e((string)$p['id']) ?>"><?= e((string)$p['label']) ?> (<?= (int)$p['rules'] ?>)</option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php if ($chats !== []): ?>
      <label><?= te('notify.presets.bot_chat') ?>
        <select name="bot_chat_id">
          <option value="0"><?= te('notify.presets.bot_chat_none') ?></option>
          <?php foreach ($chats as $c): ?>
            <option value="<?= (int)($c['id'] ?? $c['ID'] ?? 0) ?>">
              #<?= (int)($c['id'] ?? $c['ID'] ?? 0) ?>
              <?= e((string)($c['title'] ?? $c['TITLE'] ?? '')) ?>
              (<?= e((string)($c['platform'] ?? $c['PLATFORM'] ?? '')) ?>
              <?= e((string)($c['chat_id'] ?? $c['CHAT_ID'] ?? '')) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </label>
    <?php endif; ?>
    <label><?= te('notify.presets.chat_id') ?>
      <input type="text" name="chat_id" autocomplete="off" placeholder="-100…">
    </label>
    <label><?= te('notify.presets.platform') ?>
      <select name="platform">
        <option value=""><?= te('notify.target.platform_default') ?></option>
        <option value="max">max</option>
        <option value="telegram">telegram</option>
      </select>
    </label>
    <label><?= te('notify.presets.staff_ids') ?>
      <input type="text" name="staff_ids" autocomplete="off" placeholder="12, 34">
    </label>
    <label class="check"><input type="checkbox" name="enabled" value="1" checked> <?= te('notify.presets.enabled') ?></label>
    <div class="page-actions page-actions-static">
      <button type="submit" class="btn-secondary"><?= te('notify.presets.apply') ?></button>
    </div>
  </fieldset>
</form>
<?php endif; ?>

<?php if ($rules === []): ?>
  <p class="muted"><?= te('notify.rules.empty') ?></p>
<?php else: ?>
<table class="data-table">
  <thead>
    <tr>
      <th><?= te('notify.col.name') ?></th>
      <th><?= te('notify.col.event') ?></th>
      <th><?= te('notify.col.kind') ?></th>
      <th><?= te('notify.col.targets') ?></th>
      <th><?= te('notify.col.enabled') ?></th>
      <th></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($rules as $r): ?>
    <tr>
      <td><a href="/notify/rules/<?= (int)$r['id'] ?>/edit"><?= e($r['name']) ?></a></td>
      <td><?= e($events[$r['event']] ?? $r['event']) ?></td>
      <td><?= te('notify.kind.' . $r['kind']) ?></td>
      <td><?= (int)count($r['targets']) ?></td>
      <td>
        <form method="post" class="inline-form">
          <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
          <input type="hidden" name="action" value="toggle">
          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <label class="check"><input type="checkbox" name="enabled" value="1"<?= $r['enabled'] ? ' checked' : '' ?> onchange="this.form.submit()"> <?= $r['enabled'] ? te('core.common.on') : te('core.common.off') ?></label>
        </form>
      </td>
      <td class="notify-row-actions">
        <a class="btn-ghost btn-sm" href="/notify/rules/<?= (int)$r['id'] ?>/test"><?= te('notify.rules.test') ?></a>
        <form method="post" class="inline-form" onsubmit="return confirm(<?= tj('notify.rules.confirm_delete') ?>)">
          <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <button class="btn-ghost btn-sm" type="submit"><?= te('core.common.delete') ?></button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
