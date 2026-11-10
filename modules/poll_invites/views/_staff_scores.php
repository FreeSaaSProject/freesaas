<?php
declare(strict_types=1);
/**
 * Read-only procedure context, responsible staff, overall score.
 *
 * @var array<string,mixed> $staffMeta
 */
$staffMeta = is_array($staffMeta ?? null) ? $staffMeta : [];
$viewers = is_array($viewers ?? null) ? $viewers : [];
$viewers = array_values(array_filter(array_map('intval', $viewers), static function (int $id): bool {
    return $id > 0;
}));
if (empty($staffMeta['has_staff']) && empty($staffMeta['has_scores']) && empty($staffMeta['has_proc'])
    && $viewers === []
) {
    return;
}
$staffBadge = static function (int $id): string {
    if ($id < 1) {
        return '—';
    }
    if (class_exists('UsersRepository', false)) {
        return UsersRepository::badgeHtml($id, true, true);
    }
    return e('#' . $id);
};
$procId = (int)($staffMeta['id_proc'] ?? 0);
$procStart = (int)($staffMeta['proc_dtstart'] ?? 0);
$procEnd = (int)($staffMeta['proc_dtend'] ?? 0);
$product = trim((string)($staffMeta['proc_product_name'] ?? ''));
$askedAt = (int)($staffMeta['asked_at'] ?? 0);
$score = $staffMeta['score'] ?? null;
$speechRecordIds = is_array($staffMeta['speech_record_ids'] ?? null)
    ? $staffMeta['speech_record_ids']
    : [];
$showAudioLinks = !empty($showAudioLinks)
    && Auth::capability('SUPERADMIN_ACCESS')
    && ModuleManager::isEnabled('audio')
    && $speechRecordIds !== [];
$procWhen = '—';
if ($procStart > 0) {
    $procWhen = PollInvitesRepository::dtDisplay($procStart);
    if ($procEnd > $procStart) {
        $procWhen .= '–' . date('H:i', $procEnd);
    }
}
?>
<section class="panel poll-invites-staff-scores">
  <h2><?= te('poll_invites.meta.heading') ?></h2>
  <dl class="poll-invites-meta-grid">
    <div>
      <dt><?= te('poll_invites.field.proc') ?></dt>
      <dd>
        <?php if ($procId > 0): ?>
          <?php if (ModuleManager::isEnabled('procedures')): ?>
            <a href="/procedures/<?= $procId ?>"><?= $procId ?></a>
          <?php else: ?>
            #<?= $procId ?>
          <?php endif; ?>
          <?php if ($product !== ''): ?>
            <span class="muted"> · <?= e($product) ?></span>
          <?php endif; ?>
        <?php else: ?>
          —
        <?php endif; ?>
      </dd>
    </div>
    <div>
      <dt><?= te('poll_invites.field.proc_when') ?></dt>
      <dd><?= e($procWhen) ?></dd>
    </div>
    <div>
      <dt><?= te('poll_invites.field.asked_at') ?></dt>
      <dd><?= $askedAt > 0 ? e(PollInvitesRepository::dtDisplay($askedAt)) : '—' ?></dd>
    </div>
    <div>
      <dt><?= te('poll_invites.field.score') ?></dt>
      <dd class="poll-invites-score">
        <?php if (!empty($canScoreEdit)): ?>
          <form method="post" class="inline-form poll-invites-score-form" action="">
            <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
            <input type="hidden" name="action" value="set_score">
            <select name="score" aria-label="<?= te('poll_invites.field.score') ?>">
              <option value="0">—</option>
              <?php for ($n = 1; $n <= 10; $n++): ?>
                <option value="<?= $n ?>"<?= ($score !== null && (int)$score === $n) ? ' selected' : '' ?>><?= $n ?></option>
              <?php endfor; ?>
            </select>
            <button type="submit" class="btn-secondary btn-sm"><?= te('poll_invites.score.save') ?></button>
          </form>
        <?php else: ?>
          <?= $score !== null && (int)$score >= 1 ? (int)$score : '—' ?>
        <?php endif; ?>
      </dd>
    </div>
    <div>
      <dt><?= te('poll_invites.field.executor') ?></dt>
      <dd><?= $staffBadge((int)($staffMeta['id_proc_operator'] ?? 0)) ?></dd>
    </div>
    <div>
      <dt><?= te('poll_invites.field.admin') ?></dt>
      <dd><?= $staffBadge((int)($staffMeta['id_proc_admin'] ?? 0)) ?></dd>
    </div>
    <?php if ($showAudioLinks): ?>
    <div>
      <dt><?= te('poll_invites.field.speech_records') ?></dt>
      <dd class="poll-invites-audio-links">
        <?php foreach ($speechRecordIds as $rid): ?>
          <a href="/audio/<?= (int)$rid ?>" target="_blank" rel="noopener"><?= te('poll_invites.action.audio_record', ['id' => (int)$rid]) ?></a>
        <?php endforeach; ?>
      </dd>
    </div>
    <?php endif; ?>
    <?php if ($viewers !== []): ?>
    <div class="poll-invites-viewers-cell">
      <dt><?= te('poll_invites.field.viewers') ?></dt>
      <dd class="poll-invites-viewers" title="<?= te('poll_invites.field.viewers') ?>"
          aria-label="<?= te('poll_invites.viewers.count', ['n' => count($viewers)]) ?>">
        <span class="muted" aria-hidden="true">👁</span>
        <?php foreach ($viewers as $vid): ?>
          <?= $staffBadge((int)$vid) ?>
        <?php endforeach; ?>
      </dd>
    </div>
    <?php endif; ?>
  </dl>
</section>
