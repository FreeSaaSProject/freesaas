<?php
declare(strict_types=1);
/** @var array $study */
/** @var array|null $progress */
/** @var list<array> $results */
/** @var bool $canEdit */
/** @var string $message */
/** @var string $error */
$study = is_array($study ?? null) ? $study : [];
$isCyclic = !empty($study['IS_CYCLIC']);
$sid = (int)($study['ID'] ?? 0);
?>
<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<section class="page-head">
  <h2><?= e((string)($study['TITLE'] ?? '')) ?></h2>
  <?php if ($progress && !$isCyclic): ?>
    <p><?= te('audio_llm.progress', [
        'done' => (string)(int)$progress['done'],
        'total' => (string)(int)$progress['total'],
        'percent' => (string)(int)$progress['percent'],
    ]) ?> · <?= te('audio_llm.status.' . (int)($study['STATUS'] ?? 0)) ?></p>
  <?php endif; ?>
</section>

<?php if ($isCyclic && $canEdit): ?>
<form method="post" class="form-grid">
  <?= NavContext::hiddenField() ?>
  <input type="hidden" name="action" value="save_cyclic">
  <label><?= te('audio_llm.field.title') ?>
    <input type="text" name="title" value="<?= e((string)$study['TITLE']) ?>">
  </label>
  <label><?= te('audio_llm.field.prompt') ?>
    <textarea name="prompt" rows="8" required><?= e((string)$study['PROMPT']) ?></textarea>
  </label>
  <label class="check"><input type="checkbox" name="cyclic_enabled" value="1"<?= !empty($study['CYCLIC_ENABLED']) ? ' checked' : '' ?>> <?= te('audio_llm.field.cyclic_enabled') ?></label>
  <label><?= te('audio_llm.field.trigger_good') ?>
    <input type="number" name="trigger_good_id" value="<?= (int)$study['TRIGGER_GOOD_ID'] ?>">
  </label>
  <label><?= te('audio_llm.field.amount_pos') ?>
    <input type="number" name="amount_positive" value="<?= (int)$study['AMOUNT_POSITIVE'] ?>">
  </label>
  <label><?= te('audio_llm.field.amount_neg') ?>
    <input type="number" name="amount_negative" value="<?= (int)$study['AMOUNT_NEGATIVE'] ?>">
  </label>
  <button type="submit" class="btn"><?= te('audio_llm.field.save') ?></button>
</form>
<?php else: ?>
  <p><strong><?= te('audio_llm.field.prompt') ?>:</strong></p>
  <pre class="box"><?= e((string)($study['PROMPT'] ?? '')) ?></pre>
<?php endif; ?>

<?php if (!$isCyclic && $canEdit): ?>
  <form method="post" class="inline-actions">
    <?= NavContext::hiddenField() ?>
    <input type="hidden" name="limit" value="5">
    <button class="btn" name="action" value="process"><?= te('audio_llm.action.process') ?></button>
    <button class="btn" name="action" value="process_all"><?= te('audio_llm.action.process_all') ?></button>
    <button class="btn btn-secondary" name="action" value="reset"><?= te('audio_llm.action.reset') ?></button>
    <button class="btn btn-secondary" name="action" value="summary"><?= te('audio_llm.action.summary') ?></button>
  </form>
<?php endif; ?>

<?php if (trim((string)($study['LLM_SUMMARY'] ?? '')) !== ''): ?>
  <h3><?= te('audio_llm.summary') ?></h3>
  <div class="box"><?= e_nl((string)$study['LLM_SUMMARY']) ?></div>
<?php endif; ?>

<?php if (!$isCyclic): ?>
  <h3><?= te('audio_llm.results') ?></h3>
  <?php if ($results === []): ?>
    <p class="muted">—</p>
  <?php else: ?>
  <table class="data-table">
    <thead>
      <tr>
        <th><?= te('audio_llm.col.record') ?></th>
        <th><?= te('audio_llm.col.status') ?></th>
        <th><?= te('audio_llm.col.result') ?></th>
        <th><?= te('audio_llm.col.error') ?></th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($results as $r):
        $rid = (int)$r['ID_RECORD'];
        $rst = (int)$r['STATUS'];
        $canRetry = $canEdit && ($rst === 3 || ($rst === 2 && trim((string)$r['RESULT_TEXT']) === ''));
        ?>
      <tr>
        <td><a href="/audio/<?= $rid ?>"><?= $rid ?></a></td>
        <td><?= te('audio_llm.result.' . $rst) ?></td>
        <td><pre class="compact"><?= e((string)$r['RESULT_TEXT']) ?></pre></td>
        <td class="muted"><?= e((string)$r['ERROR_TEXT']) ?></td>
        <td>
          <?php if ($canRetry): ?>
            <form method="post" class="inline">
              <?= NavContext::hiddenField() ?>
              <input type="hidden" name="action" value="retry">
              <input type="hidden" name="record_id" value="<?= $rid ?>">
              <button type="submit" class="btn btn-sm"><?= te('audio_llm.action.retry') ?></button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
<?php endif; ?>

<?php if ($canEdit): ?>
  <form method="post" onsubmit="return confirm(<?= tj('audio_llm.action.delete') ?>);">
    <?= NavContext::hiddenField() ?>
    <button class="btn btn-danger" name="action" value="delete"><?= te('audio_llm.action.delete') ?></button>
  </form>
<?php endif; ?>
