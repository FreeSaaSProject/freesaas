<?php
declare(strict_types=1);
/**
 * Superadmin: cabin player + transcript for speech-linked records.
 *
 * @var list<array{id:int,play_url:string,transcript_html:string,lines:int,keys:int}> $speechAudio
 * @var int $activeRecordId
 */
$speechAudio = is_array($speechAudio ?? null) ? $speechAudio : [];
if ($speechAudio === []) {
    return;
}
$activeRecordId = (int)($activeRecordId ?? 0);
if ($activeRecordId < 1) {
    $activeRecordId = (int)($speechAudio[0]['id'] ?? 0);
}
$active = null;
foreach ($speechAudio as $block) {
    if ((int)($block['id'] ?? 0) === $activeRecordId) {
        $active = $block;
        break;
    }
}
if ($active === null) {
    $active = $speechAudio[0];
    $activeRecordId = (int)($active['id'] ?? 0);
}
?>
<section class="panel poll-invites-speech-review">
  <h2><?= te('poll_invites.speech.heading') ?></h2>
  <?php if (count($speechAudio) > 1): ?>
    <p class="poll-invites-speech-tabs">
      <?php foreach ($speechAudio as $block): ?>
        <?php
          $rid = (int)($block['id'] ?? 0);
          $href = ListQuery::url('/poll-invites/' . (int)($row['id'] ?? 0) . '/edit', [
              'record' => $rid,
          ]);
        ?>
        <?php if ($rid === $activeRecordId): ?>
          <span class="pill"><?= te('poll_invites.action.audio_record', ['id' => $rid]) ?></span>
        <?php else: ?>
          <a class="btn-secondary btn-sm" href="<?= e($href) ?>"><?= te('poll_invites.action.audio_record', ['id' => $rid]) ?></a>
        <?php endif; ?>
      <?php endforeach; ?>
    </p>
  <?php endif; ?>

  <?php if (trim((string)($active['play_url'] ?? '')) !== ''): ?>
    <section class="poll-invites-speech-player">
      <h3><?= te('poll_invites.speech.playback') ?></h3>
      <audio controls preload="none" src="<?= e((string)$active['play_url']) ?>" style="width:100%;max-width:40rem"></audio>
    </section>
  <?php else: ?>
    <p class="muted"><?= te('poll_invites.speech.no_playback') ?></p>
  <?php endif; ?>

  <section class="poll-invites-speech-transcript">
    <h3><?= te('poll_invites.speech.transcript') ?>
      <span class="muted">(<?= te('poll_invites.speech.transcript_stats', [
        'lines' => (int)($active['lines'] ?? 0),
        'keys' => (int)($active['keys'] ?? 0),
      ]) ?>)</span>
    </h3>
    <?php if (trim((string)($active['transcript_html'] ?? '')) !== ''): ?>
      <div class="transcript" id="poll-invites-transcript">
        <?= $active['transcript_html'] ?>
      </div>
    <?php else: ?>
      <p class="muted"><?= te('poll_invites.speech.transcript_empty') ?></p>
    <?php endif; ?>
  </section>
</section>
