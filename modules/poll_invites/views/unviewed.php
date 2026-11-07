<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var string $message */
/** @var string $error */
/** @var bool $canOpenCards */
/** @var bool $showAudioLinks */
$items = is_array($items ?? null) ? $items : [];
$canOpenCards = !empty($canOpenCards);
$showAudioLinks = !empty($showAudioLinks);
$message = (string)($message ?? '');
$error = (string)($error ?? '');
?>
<section class="page-head">
  <div class="page-head-row">
    <div>
      <h1><?= te('poll_invites.unviewed.heading') ?></h1>
      <p class="muted"><?= te('poll_invites.unviewed.help') ?></p>
    </div>
    <?php if ($items !== []): ?>
    <div class="page-actions">
      <form method="post" class="inline-form"
            onsubmit="return confirm('<?= e(t('poll_invites.unviewed.mark_all_confirm')) ?>');">
        <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
        <button type="submit" name="mark_all" value="1" class="btn-primary">
          <?= te('poll_invites.unviewed.mark_all', ['n' => (int)$total]) ?>
        </button>
      </form>
    </div>
    <?php endif; ?>
  </div>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<?php if ($items === []): ?>
  <p class="muted"><?= te('poll_invites.unviewed.empty') ?></p>
<?php else: ?>
  <div class="poll-invites-unviewed-list">
    <?php foreach ($items as $row): ?>
      <?php
        $id = (int)($row['id'] ?? 0);
        $meta = is_array($row['meta'] ?? null) ? $row['meta'] : [];
        $procId = (int)($meta['id_proc'] ?? $row['id_proc'] ?? 0);
        $score = $meta['score'] ?? null;
        $transcript = trim((string)($row['transcript'] ?? ''));
        $answerLines = is_array($row['answer_lines'] ?? null) ? $row['answer_lines'] : [];
        $procStart = (int)($meta['proc_dtstart'] ?? 0);
        $procEnd = (int)($meta['proc_dtend'] ?? 0);
        $product = trim((string)($meta['proc_product_name'] ?? ''));
        $procWhen = $procStart > 0 ? PollInvitesRepository::dtDisplay($procStart) : '—';
        if ($procStart > 0 && $procEnd > $procStart) {
            $procWhen .= '–' . date('H:i', $procEnd);
        }
      ?>
      <article class="panel poll-invites-unviewed-card">
        <header class="poll-invites-unviewed-head">
          <div>
            <strong>#<?= $id ?></strong>
            <?php if (!empty($row['poll_name'])): ?>
              <span class="muted"> · <?= e((string)$row['poll_name']) ?></span>
            <?php endif; ?>
            <?php if ($score !== null && (int)$score >= 1): ?>
              <span class="poll-invites-score"> · <?= (int)$score ?></span>
            <?php endif; ?>
          </div>
          <form method="post" class="inline-form">
            <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
            <input type="hidden" name="ids[]" value="<?= $id ?>">
            <button type="submit" class="btn-secondary"><?= te('poll_invites.unviewed.mark') ?></button>
          </form>
        </header>
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
            <dd><?= e(PollInvitesRepository::dtDisplay((int)($row['dt_create'] ?? 0))) ?></dd>
          </div>
          <div>
            <dt><?= te('poll_invites.field.score') ?></dt>
            <dd class="poll-invites-score"><?= $score !== null && (int)$score >= 1 ? (int)$score : '—' ?></dd>
          </div>
          <div>
            <dt><?= te('poll_invites.field.executor') ?></dt>
            <dd><?= class_exists('UsersRepository', false)
              ? UsersRepository::badgeHtml((int)($meta['id_proc_operator'] ?? 0), true, true)
              : '—' ?></dd>
          </div>
          <div>
            <dt><?= te('poll_invites.field.admin') ?></dt>
            <dd><?= class_exists('UsersRepository', false)
              ? UsersRepository::badgeHtml((int)($meta['id_proc_admin'] ?? 0), true, true)
              : '—' ?></dd>
          </div>
        </dl>

        <?php if ($answerLines !== []): ?>
          <div class="poll-invites-unviewed-answers">
            <h3><?= te('poll_invites.unviewed.answers') ?></h3>
            <ol>
              <?php foreach ($answerLines as $line): ?>
                <li>
                  <strong><?= e((string)($line['q'] ?? '')) ?></strong>
                  — <?= e((string)($line['a'] ?? '')) ?>
                </li>
              <?php endforeach; ?>
            </ol>
          </div>
        <?php endif; ?>

        <?php if ($transcript !== ''): ?>
          <div class="poll-invites-unviewed-transcript">
            <h3><?= te('poll_invites.col.transcript') ?></h3>
            <p><?= e_nl($transcript) ?></p>
          </div>
        <?php endif; ?>

        <?php
          $recordIds = is_array($row['speech_record_ids'] ?? null) ? $row['speech_record_ids'] : [];
          if ($recordIds === [] && is_array($meta['speech_record_ids'] ?? null)) {
              $recordIds = $meta['speech_record_ids'];
          }
        ?>
        <?php if ($showAudioLinks && $recordIds !== []): ?>
          <p class="poll-invites-audio-links">
            <?php foreach ($recordIds as $rid): ?>
              <a href="/audio/<?= (int)$rid ?>" target="_blank" rel="noopener"><?= te('poll_invites.action.audio_record', ['id' => (int)$rid]) ?></a>
            <?php endforeach; ?>
          </p>
        <?php endif; ?>

        <?php if ($canOpenCards): ?>
          <p class="muted">
            <a href="/poll-invites/<?= $id ?>/edit"><?= te('poll_invites.action.edit') ?></a>
            <?php if ((int)($row['dt_completed'] ?? 0) < 1): ?>
              · <a href="/poll-invites/<?= $id ?>/fill"><?= te('poll_invites.action.fill') ?></a>
            <?php endif; ?>
          </p>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  </div>
  <p class="muted"><?= e(t('poll_invites.unviewed.total', ['n' => (int)$total])) ?></p>
<?php endif; ?>
