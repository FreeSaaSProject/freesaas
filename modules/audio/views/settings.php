<?php
declare(strict_types=1);
/** @var string $message */
/** @var string $error */
/** @var array<string,string> $cfg */
/** @var string $extraHtml */
/** @var bool $canWorkerToken */
/** @var bool $workerTokenConfigured */
/** @var bool $workerTokenJustGenerated */
/** @var string $workerTokenPlain */
/** @var string $workerTokenMasked */
/** @var string $ingestUrl */
$extraHtml = (string)($extraHtml ?? '');
$canWorkerToken = !empty($canWorkerToken);
$workerTokenConfigured = !empty($workerTokenConfigured);
$workerTokenJustGenerated = !empty($workerTokenJustGenerated);
$workerTokenPlain = (string)($workerTokenPlain ?? '');
$workerTokenMasked = (string)($workerTokenMasked ?? '');
$ingestUrl = (string)($ingestUrl ?? '');
?>
<section class="page-head">
  <p class="lede"><?= t('audio.settings.lede') ?></p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="post" class="form-grid">
  <?php require __DIR__ . '/_settings_storage.php'; ?>
  <?= $extraHtml ?>
  <?php if ($canWorkerToken): ?>
    <?php require __DIR__ . '/_settings_worker_token.php'; ?>
  <?php endif; ?>
  <div class="page-actions">
    <button class="btn-primary" type="submit"><?= te('core.common.save') ?></button>
  </div>
</form>

<?php if ($canWorkerToken): ?>
<form method="post" class="actions settings-worker-token-gen" style="margin-top:.75rem"
      onsubmit="return <?= $workerTokenConfigured ? 'confirm(' . tj('audio.settings.worker_token.confirm_regen') . ')' : 'true' ?>;">
  <input type="hidden" name="action" value="worker_token_generate">
  <button class="btn-secondary" type="submit">
    <?= te($workerTokenConfigured ? 'audio.settings.worker_token.btn_regen' : 'audio.settings.worker_token.btn_generate') ?>
  </button>
</form>
<script>
(function () {
  var copied = <?= json_encode(t('audio.settings.worker_token.copied'), JSON_UNESCAPED_UNICODE) ?>;
  var promptLabel = <?= json_encode(t('audio.settings.worker_token.copy_prompt'), JSON_UNESCAPED_UNICODE) ?>;
  document.querySelectorAll('[data-copy-text]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var text = btn.getAttribute('data-copy-text') || '';
      if (!text) return;
      var label = btn.textContent;
      var done = function () {
        btn.textContent = copied;
        setTimeout(function () { btn.textContent = label; }, 1500);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done).catch(function () {
          window.prompt(promptLabel, text);
        });
      } else {
        window.prompt(promptLabel, text);
      }
    });
  });
})();
</script>
<?php endif; ?>
