<?php
declare(strict_types=1);
/** @var array $user */
/** @var array<string,mixed>|null $voiceProfile */
$voiceProfile = is_array($voiceProfile ?? null) ? $voiceProfile : null;
?>
<section class="user-edit-section">
  <h3><?= te('audio_diarize.voice_profiles.user_section') ?></h3>
  <?php if ($voiceProfile === null): ?>
    <p class="muted"><?= te('audio_diarize.voice_profiles.user_none') ?></p>
  <?php else: ?>
    <dl class="kv">
      <dt><?= te('audio_diarize.voice_profiles.col.samples') ?></dt>
      <dd><?= (int)$voiceProfile['sample_count'] ?></dd>
      <dt><?= te('audio_diarize.voice_profiles.col.talk') ?></dt>
      <dd><?= (int)round((float)$voiceProfile['talk_sec_total']) ?> s</dd>
      <dt><?= te('audio_diarize.voice_profiles.col.dim') ?></dt>
      <dd><?= (int)$voiceProfile['embedding_dim'] ?></dd>
      <dt><?= te('audio_diarize.voice_profiles.col.source') ?></dt>
      <dd><code><?= e((string)$voiceProfile['source']) ?></code></dd>
      <dt><?= te('audio_diarize.voice_profiles.col.updated') ?></dt>
      <dd><?= !empty($voiceProfile['dt_update']) ? e(date('Y-m-d H:i', (int)$voiceProfile['dt_update'])) : '—' ?></dd>
    </dl>
    <p class="muted"><?= te('audio_diarize.voice_profiles.user_vector_note') ?></p>
  <?php endif; ?>
</section>
