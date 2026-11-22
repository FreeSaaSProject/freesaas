<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var string $q */
/** @var string $message */
/** @var string $error */
?>
<?php if ($message !== ''): ?>
  <p class="flash ok"><?= e($message) ?></p>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <p class="flash err"><?= e($error) ?></p>
<?php endif; ?>

<p class="lede"><?= te('bots.chats.lede') ?></p>

<form method="get" class="filters">
  <label><?= te('bots.chats.filter_q') ?>
    <input type="search" name="q" value="<?= e($q) ?>">
  </label>
  <button type="submit"><?= te('bots.chats.filter') ?></button>
</form>

<details class="card" open>
  <summary><?= te('bots.chats.add') ?></summary>
  <form method="post" class="form-grid">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="save">
    <label><?= te('bots.chats.col.platform') ?>
      <select name="platform">
        <option value="max">Max</option>
        <option value="telegram">Telegram</option>
      </select>
    </label>
    <label><?= te('bots.chats.col.chat_id') ?>
      <input type="text" name="chat_id" required placeholder="-123…">
    </label>
    <label><?= te('bots.chats.col.title') ?>
      <input type="text" name="title">
    </label>
    <label><?= te('bots.chats.col.purpose') ?>
      <input type="text" name="purpose">
    </label>
    <label><?= te('bots.chats.col.description') ?>
      <input type="text" name="description">
    </label>
    <label class="checkbox">
      <input type="checkbox" name="enabled" value="1" checked>
      <?= te('bots.chats.col.enabled') ?>
    </label>
    <button type="submit" class="btn-primary"><?= te('bots.chats.save') ?></button>
  </form>
</details>

<?php if ($items === []): ?>
  <p class="muted"><?= te('bots.chats.empty') ?></p>
<?php else: ?>
  <table class="table">
    <thead>
      <tr>
        <th><?= te('bots.chats.col.id') ?></th>
        <th><?= te('bots.chats.col.platform') ?></th>
        <th><?= te('bots.chats.col.chat_id') ?></th>
        <th><?= te('bots.chats.col.title') ?></th>
        <th><?= te('bots.chats.col.purpose') ?></th>
        <th><?= te('bots.chats.col.enabled') ?></th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $row): ?>
        <tr>
          <td><?= (int)$row['id'] ?></td>
          <td><?= e((string)$row['platform']) ?></td>
          <td><code><?= e((string)$row['chat_id']) ?></code></td>
          <td><?= e((string)$row['title']) ?></td>
          <td><?= e((string)$row['purpose']) ?></td>
          <td><?= !empty($row['enabled']) ? te('bots.chats.yes') : te('bots.chats.no') ?></td>
          <td class="row-actions">
            <form method="post" class="inline">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
              <input type="hidden" name="enabled" value="<?= !empty($row['enabled']) ? '0' : '1' ?>">
              <button type="submit"><?= !empty($row['enabled']) ? te('bots.chats.disable') : te('bots.chats.enable') ?></button>
            </form>
            <form method="post" class="inline" onsubmit="return confirm(<?= json_encode(t('bots.chats.confirm_delete'), JSON_UNESCAPED_UNICODE) ?>);">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
              <button type="submit" class="danger"><?= te('bots.chats.delete') ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
