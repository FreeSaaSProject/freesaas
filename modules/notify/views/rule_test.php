<?php
declare(strict_types=1);
/** @var array<string,mixed> $rule */
/** @var array<string,mixed> $def */
/** @var array<string,mixed>|null $result */
/** @var array<string,string> $sample */
?>
<section class="page-head">
  <p class="lede"><?= e((string)$rule['name']) ?> · <code><?= e((string)$rule['event']) ?></code></p>
</section>

<form method="post" class="form-grid">
  <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
  <fieldset>
    <legend><?= te('notify.rule.test_ctx') ?></legend>
    <?php foreach ($sample as $k => $v): ?>
      <label><?= e($k) ?>
        <input name="ctx[<?= e($k) ?>]" value="<?= e($v) ?>" autocomplete="off">
      </label>
    <?php endforeach; ?>
    <label><?= te('notify.send.client_id') ?>
      <input type="number" name="client_id" value="" min="1" autocomplete="off">
    </label>
    <label><?= te('notify.send.user_id') ?>
      <input type="number" name="user_id" value="" min="1" autocomplete="off">
    </label>
  </fieldset>
  <div class="page-actions">
    <button type="submit" class="btn-primary"><?= te('notify.rule.test_run') ?></button>
    <a class="btn-ghost" href="/notify/rules/<?= (int)$rule['id'] ?>/edit"><?= te('core.common.edit') ?></a>
  </div>
</form>

<?php if (is_array($result)): ?>
  <div class="flash <?= !empty($result['matched']) ? 'flash-ok' : 'flash-warn' ?>">
    <?= !empty($result['matched']) ? te('notify.rule.test_matched') : te('notify.rule.test_unmatched') ?>
  </div>
  <h3><?= te('notify.rule.test_text') ?></h3>
  <pre class="notify-pre"><?= e((string)($result['text'] ?? '')) ?></pre>
  <h3><?= te('notify.rule.test_recipients') ?></h3>
  <pre class="notify-pre"><?= e(json_encode($result['results'] ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '') ?></pre>
<?php endif; ?>
