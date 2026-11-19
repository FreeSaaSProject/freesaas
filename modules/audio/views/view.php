<?php
declare(strict_types=1);
/** @var array $row */
/** @var string $flash */
/** @var string $playUrl */
/** @var bool $canEdit */
/** @var array{html:string,lines:int,trash:int,keys:int} $transcript */
/** @var list<array<string,mixed>> $instances */
/** @var array<int,array{name:string,color:string,bg:string}> $dictMeta */
/** @var list<array{dict_id:int,diversity:int,instances:int}> $keyDicts */
/** @var list<array<string,mixed>> $speakers */
/** @var bool $canAssignSpeaker */
/** @var bool $canRunDiarize */
/** @var bool $hasAsrDiaHost */
/** @var bool $hasTranscription */
/** @var bool $diarizeOn */
/** @var array<string,mixed>|null $asrDiaRun */
$dictMeta = is_array($dictMeta ?? null) ? $dictMeta : [];
$keyDicts = is_array($keyDicts ?? null) ? $keyDicts : [];
$instances = is_array($instances ?? null) ? $instances : [];
$speakers = is_array($speakers ?? null) ? $speakers : [];
$transcript = is_array($transcript ?? null) ? $transcript : ['html' => '', 'lines' => 0, 'trash' => 0, 'keys' => 0];
$canAssignSpeaker = !empty($canAssignSpeaker);
$canRunDiarize = !empty($canRunDiarize);
$hasAsrDiaHost = !empty($hasAsrDiaHost);
$hasTranscription = !empty($hasTranscription);
$diarizeOn = !empty($diarizeOn);
$asrDiaRun = is_array($asrDiaRun ?? null) ? $asrDiaRun : null;
$recordId = (int)($row['id'] ?? $row['ID'] ?? 0);
$recordAdminId = (int)($row['id_admin'] ?? $row['ID_ADMIN'] ?? 0);
$runStatusText = '';
if ($asrDiaRun !== null) {
    $runStatusText = t('audio_diarize.view.run_status_detail', [
        'status' => (string)($asrDiaRun['status'] ?? ''),
        'model' => (string)(($asrDiaRun['asr_model'] ?? '') !== '' ? $asrDiaRun['asr_model'] : '—'),
        'cues' => (string)(int)($asrDiaRun['cues'] ?? 0),
        'pct' => (string)$asrDiaRun['matched_pct'],
    ]);
    if ((string)($asrDiaRun['error'] ?? '') !== '') {
        $runStatusText .= ' · ' . (string)$asrDiaRun['error'];
    }
}
?>
<?php if ($flash !== ''): ?><p class="flash"><?= e($flash) ?></p><?php endif; ?>

<section class="page-head-row">
  <div>
    <?php if ($diarizeOn): ?>
    <p class="muted small" id="audio-diarize-status" aria-live="polite">
      <?= $runStatusText !== ''
        ? e($runStatusText)
        : te('audio_diarize.view.run_none') ?>
    </p>
    <?php if ($canRunDiarize && !$hasAsrDiaHost): ?>
    <p class="muted small"><?= te('audio_diarize.view.no_asr_dia_host') ?></p>
    <?php endif; ?>
    <?php endif; ?>
  </div>
  <div class="page-actions">
    <?php if ($canRunDiarize): ?>
    <button type="button" class="btn" id="audio-run-diarize"
            data-record-diarize
            data-record-id="<?= $recordId ?>"
            data-reuse="<?= $hasTranscription ? '1' : '0' ?>"
            title="<?= te('audio_diarize.view.run_diarize_hint') ?>">
      <?= te('audio_diarize.view.run_diarize') ?>
    </button>
    <?php endif; ?>
    <?php if ($canEdit): ?>
    <form method="post" class="inline-form">
      <?= NavContext::hiddenField() ?>
      <input type="hidden" name="action" value="toggle_disabled">
      <button type="submit"><?= !empty($row['disabled']) ? te('audio.view.enable') : te('audio.view.disable') ?></button>
    </form>
    <?php endif; ?>
  </div>
</section>

<?php if ($canRunDiarize): ?>
<?= View::capture('_longjob_panel', [
    'longjobId' => 'audio-record-asr-dia',
    'longjobTitle' => t('audio_diarize.asr_dia.longjob_title'),
    'longjobDualBars' => false,
    'longjobShowBytes' => false,
], 'core') ?>
<div id="audio-record-diarize-boot" hidden
     data-csrf="<?= e(class_exists('Csrf', false) ? Csrf::token() : '') ?>"
     data-record-id="<?= $recordId ?>"
     data-reuse="<?= $hasTranscription ? '1' : '0' ?>"
     data-i18n="<?= e(json_encode([
         'confirm' => t('audio_diarize.view.run_diarize_confirm'),
         'starting' => t('audio_diarize.view.run_starting'),
         'error' => t('audio_diarize.view.run_error', ['msg' => '{msg}']),
         'ljStarting' => t('core.longjob.starting'),
         'badJson' => t('core.longjob.bad_json'),
         'doneTitle' => t('core.longjob.done_title'),
         'errorTitle' => t('core.longjob.error_title'),
         'cancelledTitle' => t('core.longjob.cancelled_title'),
         'ok' => t('core.longjob.ok'),
         'title' => t('audio_diarize.asr_dia.longjob_title'),
     ], JSON_UNESCAPED_UNICODE)) ?>"></div>
<?php endif; ?>

<div class="audio-view-grid"
     data-record-id="<?= $recordId ?>"
     data-admin-id="<?= $recordAdminId ?>">
  <div class="audio-view-main">
    <dl class="kv">
      <dt>ID</dt><dd><?= $recordId ?></dd>
      <dt><?= te('audio.col.admin') ?></dt><dd><?= UsersRepository::badgeHtml($recordAdminId) ?></dd>
      <dt><?= te('audio.col.badge') ?></dt><dd><code><?= e((string)$row['id_dictophone']) ?></code></dd>
      <dt><?= te('audio.col.dt') ?></dt><dd><?= !empty($row['dt_recorded']) ? e(date('Y-m-d H:i:s', (int)$row['dt_recorded'])) : '—' ?></dd>
      <dt><?= te('audio.col.filename') ?></dt><dd><?= e((string)$row['filename']) ?></dd>
      <dt><?= te('audio.col.playtime') ?></dt><dd><?= (int)$row['playtime'] ?> s</dd>
      <dt><?= te('audio.col.score') ?></dt><dd><?= (int)$row['score'] ?></dd>
      <dt><?= te('audio.col.quality') ?></dt><dd><?= (int)($row['quality'] ?? 0) ?></dd>
      <dt><?= te('audio.col.disabled') ?></dt><dd><?= !empty($row['disabled']) ? te('core.common.on') : te('core.common.off') ?></dd>
    </dl>

    <?php if ($keyDicts): ?>
    <p class="audio-key-chips" aria-label="<?= te('audio.col.key_dicts') ?>">
      <?php foreach ($keyDicts as $chip): ?>
        <?php $meta = $dictMeta[$chip['dict_id']] ?? null; ?>
        <span class="key-dict-chip" style="<?= $meta ? 'background:' . e($meta['bg']) . ';color:' . e($meta['color']) : '' ?>">
          <?= e($meta['name'] ?? ('#' . $chip['dict_id'])) ?>
          <?= te('audio.view.kv_ratio', ['d' => $chip['diversity'], 'i' => $chip['instances']]) ?>
        </span>
      <?php endforeach; ?>
    </p>
    <?php endif; ?>

    <?php if ($playUrl !== ''): ?>
    <section>
      <h2><?= te('audio.view.playback') ?></h2>
      <audio id="audio-player" controls preload="none" src="<?= e($playUrl) ?>" style="width:100%;max-width:40rem"></audio>
    </section>
    <?php endif; ?>

    <?php if ($diarizeOn || $speakers !== []): ?>
    <section class="audio-speakers" aria-label="<?= te('audio.view.speakers') ?>">
      <h2><?= te('audio.view.speakers') ?></h2>
      <?php if ($canAssignSpeaker): ?>
      <p class="muted small audio-speakers-hint"><?= te('audio_diarize.speaker_assign.hint') ?></p>
      <?php elseif ($diarizeOn && $speakers !== []): ?>
      <p class="muted small"><?= te('audio_diarize.speaker_assign.need_edit') ?></p>
      <?php endif; ?>
      <?php if ($speakers === []): ?>
      <p class="muted"><?= te('audio_diarize.view.speakers_empty') ?></p>
      <?php else: ?>
      <ul class="audio-speakers-list" id="audio-speakers-list">
        <?php foreach ($speakers as $sp): ?>
          <?php
            $role = (string)($sp['role'] ?? 'unknown');
            $lid = (string)($sp['local_id'] ?? '');
            $matched = (int)($sp['matched_admin_id'] ?? 0);
            $cls = $matched > 0 || $role === 'staff' ? 'is-staff' : ($role === 'other' ? 'is-other' : '');
            // Always allow bind/rebind when enrollment is on — matched clusters still need samples.
            $canBind = $canAssignSpeaker && $lid !== '';
            $bindLabel = $matched > 0
                ? t('audio_diarize.speaker_assign.rebind')
                : t('audio_diarize.speaker_assign.bind');
          ?>
          <li class="audio-speaker-chip <?= e($cls) ?>"
              data-speaker-id="<?= e($lid) ?>"
              data-matched-admin="<?= $matched ?>">
            <button type="button"
                    class="audio-speaker-filter"
                    data-speaker-filter="<?= e($lid) ?>"
                    aria-pressed="false">
              <code><?= e($lid) ?></code>
              <span class="muted"><?= e($role) ?></span>
              <?php if (!empty($sp['is_dominant'])): ?><strong>·</strong><?php endif; ?>
              <?= (int)round((float)($sp['talk_sec'] ?? 0)) ?>s
              <?php if ($matched > 0): ?>
                · <?= UsersRepository::badgeHtml($matched) ?>
                (<?= (int)round(((float)($sp['confidence'] ?? 0)) * 100) ?>%)
              <?php endif; ?>
            </button>
            <?php if ($canBind): ?>
            <button type="button"
                    class="audio-speaker-assign"
                    data-speaker-assign="<?= e($lid) ?>"
                    title="<?= e($bindLabel) ?>">
              <?= e($bindLabel) ?>
            </button>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
      <div class="audio-speaker-assign-bar" id="audio-speaker-assign-bar" hidden>
        <label class="audio-speaker-assign-label">
          <span class="muted"><?= te('audio_diarize.voice_sample.staff') ?></span>
          <select id="audio-speaker-assign-admin"></select>
        </label>
        <button type="button" class="btn" id="audio-speaker-assign-confirm">
          <?= te('audio_diarize.speaker_assign.bind') ?>
        </button>
        <button type="button" class="btn-link" id="audio-speaker-assign-cancel">
          <?= te('core.common.cancel') ?>
        </button>
        <span class="muted small" id="audio-speaker-assign-status"></span>
      </div>
      <p class="muted small" id="audio-speaker-filter-status" hidden></p>
    </section>
    <?php endif; ?>

    <section>
      <h2><?= te('audio.view.transcription') ?>
        <span class="muted">(<?= te('audio.transcript.stats', ['lines' => (int)$transcript['lines'], 'keys' => (int)$transcript['keys']]) ?>)</span>
      </h2>
      <div class="transcript" id="audio-transcript" data-record="<?= $recordId ?>">
        <?= $transcript['html'] ?>
      </div>
    </section>

    <?php if (trim((string)($row['description'] ?? '')) !== ''): ?>
    <section>
      <h2><?= te('audio.view.description') ?></h2>
      <pre><?= e((string)$row['description']) ?></pre>
    </section>
    <?php endif; ?>
  </div>

  <aside class="audio-view-side">
    <h2><?= te('audio.view.instances') ?> (<?= count($instances) ?>)</h2>
    <?php if (!$instances): ?>
      <p class="muted"><?= te('audio.view.instances_empty') ?></p>
    <?php else: ?>
      <ul class="audio-instances">
        <?php foreach ($instances as $inst): ?>
          <li>
            <span class="key-hit" style="background:<?= e((string)($inst['bg'] ?? '#fde68a')) ?>;color:<?= e((string)($inst['color'] ?? '#111')) ?>">
              <?= e((string)($inst['key_name'] ?: ('#' . $inst['id_key']))) ?>
            </span>
            <span class="muted">L<?= (int)$inst['string_num'] ?> @<?= (int)$inst['string_position'] ?> +<?= (int)$inst['length'] ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </aside>
</div>

<?php if ($canAssignSpeaker || $speakers !== [] || $canRunDiarize): ?>
<script>
window.AudioListI18n = Object.assign({}, window.AudioListI18n || {}, <?= json_encode([
    'voiceEnrollment' => $canAssignSpeaker,
    'voiceStaff' => t('audio_diarize.voice_sample.staff'),
    'voiceNoStaff' => t('audio_diarize.voice_sample.err.no_staff'),
    'voiceSample' => t('audio_diarize.voice_sample.add'),
    'voiceMinSec' => 3,
    'voiceMaxSec' => 120,
    'speakerAssignBind' => t('audio_diarize.speaker_assign.bind'),
    'speakerAssignClear' => t('audio_diarize.speaker_assign.clear'),
    'speakerAssignWorking' => t('audio_diarize.speaker_assign.working'),
    'speakerAssignExclude' => t('audio_diarize.speaker_assign.exclude'),
    'speakerAssignInclude' => t('audio_diarize.speaker_assign.include'),
    'speakerAssignExcludedN' => t('audio_diarize.speaker_assign.excluded_n'),
    'error' => t('audio.api.error'),
    'network' => t('audio.api.network'),
    'done' => t('audio.api.done'),
], JSON_UNESCAPED_UNICODE) ?>);
</script>
<?php endif; ?>
