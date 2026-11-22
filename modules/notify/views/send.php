<?php
declare(strict_types=1);
/** @var string $message */
/** @var string $error */
/** @var list<array<string,mixed>> $results */
?>
<section class="page-head">
  <p class="lede"><?= te('notify.send.lede') ?></p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid">
  <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
  <fieldset>
    <legend><?= te('notify.send.sec.recipient') ?></legend>
    <p class="muted"><?= te('notify.send.recipient_hint') ?></p>
    <label><?= te('notify.send.client_id') ?>
      <input type="number" name="client_id" min="1" autocomplete="off">
    </label>
    <label><?= te('notify.send.user_id') ?>
      <input type="number" name="user_id" min="1" autocomplete="off">
    </label>
    <label><?= te('notify.send.chat_id') ?>
      <input type="text" name="chat_id" autocomplete="off">
    </label>
    <label><?= te('notify.send.platform') ?>
      <select name="platform">
        <option value="max">max</option>
        <option value="telegram">telegram</option>
      </select>
    </label>
  </fieldset>
  <fieldset>
    <legend><?= te('notify.send.sec.message') ?></legend>
    <label><?= te('notify.send.subject') ?>
      <input type="text" name="subject" autocomplete="off">
    </label>
    <label><?= te('notify.send.text') ?>
      <textarea name="text" rows="5" required></textarea>
    </label>
    <fieldset class="notify-channels">
      <legend><?= te('notify.send.channels') ?></legend>
      <?php foreach (['bot', 'email'] as $ch): ?>
        <label class="check"><input type="checkbox" name="channels[]" value="<?= e($ch) ?>"<?= $ch === 'bot' ? ' checked' : '' ?>> <?= te('notify.channel.' . $ch) ?></label>
      <?php endforeach; ?>
    </fieldset>
  </fieldset>
  <div class="page-actions">
    <button type="submit" class="btn-primary"><?= te('notify.send.submit') ?></button>
  </div>
</form>

<?php if (!empty($results)): ?>
  <h3><?= te('notify.send.results') ?></h3>
  <pre class="notify-pre"><?= e(json_encode($results, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '') ?></pre>
<?php endif; ?>
