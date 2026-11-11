<?php
declare(strict_types=1);
/**
 * @var bool $tablesReady
 * @var bool $pullEnabled
 * @var bool $pushEnabled
 * @var int $pushEveryMin
 * @var array{active_pull:int,active_push:int} $counts
 * @var int $pending
 * @var list<array<string,mixed>> $workers
 * @var array<string,mixed>|null $once
 */
$workers = is_array($workers ?? null) ? $workers : [];
$once = is_array($once ?? null) ? $once : null;
$fmtTime = static function (int $ts): string {
    return $ts > 0 ? date('d.m H:i:s', $ts) : '—';
};
?>
<input type="hidden" name="asr_dispatch_save" value="1">

<?php if ($once !== null && ($once['kind'] ?? '') === 'secret'): ?>
  <div class="flash flash-warn">
    <p><strong><?= te('audio_pipeline.asr.secret_once', ['name' => (string)($once['name'] ?? '')]) ?></strong></p>
    <p><code style="user-select:all;word-break:break-all"><?= e((string)($once['secret'] ?? '')) ?></code></p>
    <p class="muted small"><?= te(($once['field'] ?? '') === 'secret_next'
        ? 'audio_pipeline.asr.secret_next_hint'
        : 'audio_pipeline.asr.secret_hint') ?></p>
  </div>
<?php elseif ($once !== null && isset($once['text'])): ?>
  <div class="flash <?= ($once['kind'] ?? '') === 'ok' ? 'flash-ok' : 'flash-warn' ?>"><?= e((string)$once['text']) ?></div>
<?php endif; ?>

<fieldset class="form-fieldset">
  <legend><?= te('audio_pipeline.asr.channels') ?></legend>
  <p class="muted"><?= te('audio_pipeline.asr.channels_hint') ?></p>
  <label class="checkbox">
    <input type="checkbox" name="asr_pull_enabled" value="1"<?= $pullEnabled ? ' checked' : '' ?>>
    <?= te('audio_pipeline.asr.pull') ?>
  </label>
  <p class="muted small"><?= te('audio_pipeline.asr.pull_hint') ?></p>
  <label class="checkbox">
    <input type="checkbox" name="asr_push_enabled" value="1"<?= $pushEnabled ? ' checked' : '' ?><?= $tablesReady ? '' : ' disabled' ?>>
    <?= te('audio_pipeline.asr.push') ?>
  </label>
  <p class="muted small"><?= te('audio_pipeline.asr.push_hint') ?></p>
  <div class="form-row-inline">
    <label><?= te('audio_pipeline.asr.every_min') ?>
      <input type="number" min="1" max="60" name="asr_push_every_min" value="<?= (int)($pushEveryMin ?? 2) ?>" style="width:5em">
    </label>
  </div>
  <p class="muted small"><?= te('audio_pipeline.asr.every_min_hint') ?></p>
  <?php if ($tablesReady): ?>
    <p class="muted small"><?= te('audio_pipeline.asr.queue_state', [
        'pending' => (int)$pending,
        'pull' => (int)($counts['active_pull'] ?? 0),
        'push' => (int)($counts['active_push'] ?? 0),
    ]) ?></p>
  <?php else: ?>
    <p class="muted small"><?= te('audio_pipeline.asr.tables_missing') ?></p>
  <?php endif; ?>
</fieldset>

<?php if ($tablesReady): ?>
<fieldset class="form-fieldset">
  <legend><?= te('audio_pipeline.asr.workers') ?></legend>
  <p class="muted"><?= te('audio_pipeline.asr.workers_hint') ?></p>
  <?php if ($workers !== []): ?>
  <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th><?= te('audio_pipeline.asr.col_name') ?></th>
          <th><?= te('audio_pipeline.asr.col_url') ?></th>
          <th><?= te('audio_pipeline.asr.col_pin') ?></th>
          <th><?= te('audio_pipeline.asr.col_inflight') ?></th>
          <th><?= te('audio_pipeline.asr.col_enabled') ?></th>
          <th><?= te('audio_pipeline.asr.col_state') ?></th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($workers as $w): ?>
        <?php
          $wid = (int)$w['ID'];
          $prefix = 'asr_workers[' . $wid . ']';
          $last = json_decode((string)($w['LAST_STATUS'] ?? ''), true);
          $last = is_array($last) ? $last : [];
          $lc = is_array($last['counts'] ?? null) ? $last['counts'] : [];
          $backoff = (int)($w['BACKOFF_UNTIL'] ?? 0);
        ?>
        <tr>
          <td><input type="text" name="<?= e($prefix) ?>[name]" value="<?= e((string)$w['NAME']) ?>" maxlength="100" size="12"></td>
          <td><input type="text" name="<?= e($prefix) ?>[base_url]" value="<?= e((string)$w['BASE_URL']) ?>" placeholder="https://203.0.113.10:18443" size="28"></td>
          <td><input type="text" name="<?= e($prefix) ?>[pin]" value="<?= e((string)$w['PIN_SHA256']) ?>" placeholder="base64 sha256" size="20"></td>
          <td><input type="number" min="1" max="200" name="<?= e($prefix) ?>[max_inflight]" value="<?= (int)$w['MAX_INFLIGHT'] ?>" style="width:5em"></td>
          <td><input type="checkbox" name="<?= e($prefix) ?>[enabled]" value="1"<?= (int)$w['ENABLED'] === 1 ? ' checked' : '' ?>></td>
          <td class="small">
            <?= te('audio_pipeline.asr.state_seen', ['at' => $fmtTime((int)$w['LAST_SEEN'])]) ?><br>
            <?php if ($last !== []): ?>
              <?= te('audio_pipeline.asr.state_counts', [
                  'queued' => (int)($lc['queued'] ?? 0),
                  'processing' => (int)($lc['processing'] ?? 0),
                  'unacked' => (int)($lc['done'] ?? 0) + (int)($lc['failed'] ?? 0),
                  'whisper' => !empty($last['whisper_ok']) ? 'ok' : 'down',
              ]) ?><br>
            <?php endif; ?>
            <?php if ((string)$w['LAST_ERROR'] !== ''): ?>
              <strong><?= e((string)$w['LAST_ERROR']) ?></strong><br>
            <?php endif; ?>
            <?php if ($backoff > time()): ?>
              <?= te('audio_pipeline.asr.state_backoff', ['at' => $fmtTime($backoff)]) ?><br>
            <?php endif; ?>
            <span class="muted"><?= te('audio_pipeline.asr.state_secret', ['mask' => AsrPushWorkers::mask((string)$w['SECRET'])]) ?>
              <?= (string)$w['SECRET_NEXT'] !== '' ? te('audio_pipeline.asr.state_secret_next') : '' ?></span>
          </td>
          <td class="actions">
            <button class="btn-secondary btn-sm" type="submit" name="asr_push_action" value="test:<?= $wid ?>"><?= te('audio_pipeline.asr.btn_test') ?></button>
            <button class="btn-secondary btn-sm" type="submit" name="asr_push_action" value="sync:<?= $wid ?>"><?= te('audio_pipeline.asr.btn_sync') ?></button>
            <button class="btn-ghost btn-sm" type="submit" name="asr_push_action" value="rotate:<?= $wid ?>"
                    onclick="return confirm(<?= tj('audio_pipeline.asr.confirm_rotate') ?>)"><?= te('audio_pipeline.asr.btn_rotate') ?></button>
            <?php if ((string)$w['SECRET_NEXT'] !== ''): ?>
              <button class="btn-ghost btn-sm" type="submit" name="asr_push_action" value="promote:<?= $wid ?>"
                      onclick="return confirm(<?= tj('audio_pipeline.asr.confirm_promote') ?>)"><?= te('audio_pipeline.asr.btn_promote') ?></button>
            <?php endif; ?>
            <button class="btn-danger btn-sm" type="submit" name="asr_push_action" value="delete:<?= $wid ?>"
                    onclick="return confirm(<?= tj('audio_pipeline.asr.confirm_delete') ?>)"><?= te('core.common.delete') ?></button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <div class="form-row-inline" style="margin-top:.75rem">
    <label><?= te('audio_pipeline.asr.col_name') ?>
      <input type="text" name="asr_new[name]" maxlength="100" placeholder="macstudio">
    </label>
    <label><?= te('audio_pipeline.asr.col_url') ?>
      <input type="text" name="asr_new[base_url]" placeholder="https://203.0.113.10:18443">
    </label>
    <label><?= te('audio_pipeline.asr.col_pin') ?>
      <input type="text" name="asr_new[pin]" placeholder="base64 sha256">
    </label>
    <label><?= te('audio_pipeline.asr.col_inflight') ?>
      <input type="number" min="1" max="200" name="asr_new[max_inflight]" value="20" style="width:5em">
    </label>
    <button class="btn-secondary" type="submit" name="asr_push_action" value="add:0"><?= te('audio_pipeline.asr.btn_add') ?></button>
  </div>
  <p class="muted small"><?= te('audio_pipeline.asr.add_hint') ?></p>
</fieldset>
<?php endif; ?>
