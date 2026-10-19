<?php
declare(strict_types=1);
/**
 * @var array<string,mixed> $run
 * @var list<array<string,mixed>> $proposals
 * @var list<array<string,mixed>> $artifacts
 * @var array<string,mixed>|null $facts
 * @var array<int,string> $scriptNames
 * @var array<int,string> $dictNames
 * @var array<int,string> $partNames
 * @var bool $canEdit
 * @var string $dayHref
 * @var string $reparseHint
 */
$status = (int)($run['status'] ?? 0);
?>
<section class="page-head">
  <div class="page-head-row">
    <div>
      <p class="muted">
        <?= te('audio_script_coach.run.meta', [
            'admin' => (string)(int)$run['id_admin'],
            'day' => (string)$run['day'],
            'phase' => (string)($run['phase'] ?? ''),
        ]) ?>
        · <?= te('audio_script_coach.status.' . $status) ?>
      </p>
    </div>
    <div class="page-actions">
      <a class="btn-secondary" href="<?= e($dayHref) ?>"><?= te('audio_script_coach.day.title', [
          'name' => '#' . (int)$run['id_admin'],
          'date' => (string)$run['day'],
      ]) ?></a>
      <a class="btn-secondary" href="<?= e($reparseHint) ?>"><?= te('audio_script_coach.run.open_calendar') ?></a>
    </div>
  </div>
</section>

<?php if ($status === 3 && trim((string)($run['error_text'] ?? '')) !== ''): ?>
  <p class="flash error"><?= te('audio_script_coach.run.error', ['msg' => (string)$run['error_text']]) ?></p>
<?php endif; ?>

<?php if ($canEdit && $status !== 2): ?>
  <div class="asc-actions">
    <form method="post" action="/audio/script-coach/run/<?= (int)$run['id'] ?>" class="inline">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="advance">
      <button type="submit" class="btn-secondary"><?= te('audio_script_coach.run.advance') ?></button>
    </form>
    <form method="post" action="/audio/script-coach/run/<?= (int)$run['id'] ?>" class="inline">
      <?= Csrf::field() ?>
      <input type="hidden" name="action" value="advance">
      <input type="hidden" name="until_done" value="1">
      <button type="submit" class="btn"><?= te('audio_script_coach.run.drain') ?></button>
    </form>
  </div>
<?php endif; ?>

<?php if ($artifacts !== []): ?>
  <p class="muted asc-artifacts">
    <?= te('audio_script_coach.run.artifacts') ?>:
    <?php
      $labels = [];
      foreach ($artifacts as $a) {
          $labels[] = e((string)$a['phase']);
      }
      echo implode(', ', $labels);
    ?>
  </p>
<?php endif; ?>

<?php if (is_array($facts)): ?>
  <section class="asc-facts">
    <h2><?= te('audio_script_coach.run.facts_title') ?></h2>
    <p class="muted"><?= te('audio_script_coach.run.records', ['n' => (string)(int)($facts['record_count'] ?? 0)]) ?></p>
    <ul class="asc-script-facts">
      <?php foreach (is_array($facts['scripts'] ?? null) ? $facts['scripts'] : [] as $sf): ?>
        <li>
          <strong><?= e((string)($sf['name'] ?? ('#' . (int)$sf['id']))) ?></strong>
          · <?= te('audio_script_coach.run.starts', ['n' => (string)(int)($sf['starts'] ?? 0)]) ?>
          · <?= te('audio_script_coach.run.finishes', ['n' => (string)(int)($sf['finishes'] ?? 0)]) ?>
          · <?= te('audio_script_coach.run.incomplete', ['n' => (string)count(is_array($sf['incomplete'] ?? null) ? $sf['incomplete'] : [])]) ?>
          <?php if (!empty($sf['silent'])): ?>
            <span class="asc-badge asc-badge--warn"><?= te('audio_script_coach.run.silent') ?></span>
          <?php endif; ?>
          <?php if (!empty($sf['trigger_gap'])): ?>
            <span class="asc-badge asc-badge--warn"><?= te('audio_script_coach.run.trigger_gap') ?></span>
          <?php endif; ?>
          <?php if (!empty($sf['weak_start'])): ?>
            <span class="asc-badge asc-badge--warn"><?= te('audio_script_coach.run.weak_start') ?></span>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
<?php endif; ?>

<section class="asc-proposals">
  <h2><?= te('audio_script_coach.run.proposals_title') ?></h2>
  <?php if ($proposals === []): ?>
    <p class="muted"><?= te('audio_script_coach.run.proposals_empty') ?></p>
  <?php else: ?>
    <?php foreach ($proposals as $prop): ?>
      <?php
        $pst = (int)($prop['status'] ?? 0);
        $action = (string)($prop['action'] ?? '');
        $sid = (int)($prop['id_script'] ?? 0);
        $pid = (int)($prop['id_part'] ?? 0);
        $did = (int)($prop['id_dict'] ?? 0);
      ?>
      <article class="asc-proposal asc-proposal--st<?= $pst ?>">
        <header>
          <span class="asc-prop-action"><?= te('audio_script_coach.prop.action.' . $action) ?></span>
          <span class="asc-prop-status"><?= te('audio_script_coach.prop.status.' . $pst) ?></span>
          <span class="muted">#<?= (int)$prop['id'] ?></span>
        </header>
        <p>
          <?= te('audio_script_coach.prop.script') ?>:
          <strong><?= e($scriptNames[$sid] ?? ('#' . $sid)) ?></strong>
          <?php if ($pid > 0): ?>
            · <?= te('audio_script_coach.prop.part') ?>:
            <?= e($partNames[$pid] ?? ('#' . $pid)) ?>
          <?php endif; ?>
        </p>
        <?php
          $applyMeta = is_array($prop['apply_meta'] ?? null) ? $prop['apply_meta'] : null;
          $applyKeys = is_array($applyMeta['keys'] ?? null) ? $applyMeta['keys'] : [];
          $applyDictId = (int)($applyMeta['dict_id'] ?? $did);
          $phraseRows = [];
          if ($pst === 1 && $applyKeys !== []) {
              $phraseRows = $applyKeys;
          } elseif ($pst === 0) {
              $phraseRows = CoachPhraseCheck::annotate(is_array($prop['phrases'] ?? null) ? $prop['phrases'] : []);
          } else {
              foreach (is_array($prop['phrases'] ?? null) ? $prop['phrases'] : [] as $ph) {
                  $phraseRows[] = ['name' => (string)$ph, 'ok' => true, 'id' => 0];
              }
          }
        ?>
        <?php if ($action === 'add_key'): ?>
          <p><?= te('audio_script_coach.prop.dict') ?>:
            <?php if ($applyDictId > 0): ?>
              <a href="/audio/dicts/<?= $applyDictId ?>/edit">#<?= $applyDictId ?></a>
              <?= e($dictNames[$applyDictId] ?? $dictNames[$did] ?? '') ?>
            <?php else: ?>
              <?= e($dictNames[$did] ?? ('#' . $did)) ?>
            <?php endif; ?>
          </p>
        <?php else: ?>
          <p><?= te('audio_script_coach.prop.new_dict') ?>:
            <strong><?= e((string)($prop['new_dict_name'] ?? '')) ?></strong>
            <?php if ($pst === 1 && $applyDictId > 0): ?>
              · <a href="/audio/dicts/<?= $applyDictId ?>/edit">#<?= $applyDictId ?></a>
            <?php endif; ?>
          </p>
        <?php endif; ?>

        <div class="asc-phrases">
          <div class="asc-phrases-label"><?= te('audio_script_coach.prop.phrases') ?>:</div>
          <ul class="asc-phrase-list">
            <?php foreach ($phraseRows as $row): ?>
              <?php
                $phName = (string)($row['name'] ?? '');
                $keyId = (int)($row['id'] ?? 0);
                $skipped = (string)($row['skipped'] ?? '');
                $isOkPending = array_key_exists('ok', $row) ? !empty($row['ok']) : ($keyId > 0);
                $overlaps = is_array($row['overlaps'] ?? null) ? $row['overlaps'] : [];
              ?>
              <li class="asc-phrase-row<?= $skipped !== '' || (!$isOkPending && $pst === 0) ? ' asc-phrase-row--blocked' : '' ?><?= $keyId > 0 ? ' asc-phrase-row--ok' : '' ?>">
                <?php if ($keyId > 0): ?>
                  <span class="asc-ok" title="<?= e(t('audio_script_coach.prop.added')) ?>" aria-label="<?= e(t('audio_script_coach.prop.added')) ?>">✓</span>
                  <a class="asc-key-link" href="/audio/keys/<?= $keyId ?>/edit">#<?= $keyId ?></a>
                  <code class="asc-phrase"><?= e($phName) ?></code>
                <?php elseif ($pst === 0 && !$isOkPending): ?>
                  <span class="asc-block" title="<?= e(t('audio_script_coach.prop.overlap')) ?>">✕</span>
                  <code class="asc-phrase"><?= e($phName) ?></code>
                  <?php if ($overlaps !== []): ?>
                    <span class="muted asc-overlap">
                      <?= te('audio_script_coach.prop.overlaps_with') ?>
                      <?php foreach ($overlaps as $i => $ov): ?>
                        <?= $i > 0 ? ', ' : '' ?>
                        <a href="/audio/keys/<?= (int)$ov['id'] ?>/edit">#<?= (int)$ov['id'] ?></a>
                        <span class="muted">(<?= e((string)($ov['relation'] ?? '')) ?>)</span>
                      <?php endforeach; ?>
                    </span>
                  <?php endif; ?>
                <?php elseif ($skipped !== ''): ?>
                  <span class="asc-block">✕</span>
                  <code class="asc-phrase"><?= e($phName) ?></code>
                  <span class="muted"><?= $skipped === 'create_failed'
                      ? te('audio_script_coach.prop.skipped_create_failed')
                      : te('audio_script_coach.prop.skipped_overlap') ?></span>
                  <?php if ($overlaps !== []): ?>
                    <span class="muted asc-overlap">
                      <?php foreach ($overlaps as $i => $ov): ?>
                        <?= $i > 0 ? ', ' : '' ?>
                        <a href="/audio/keys/<?= (int)$ov['id'] ?>/edit">#<?= (int)$ov['id'] ?></a>
                      <?php endforeach; ?>
                    </span>
                  <?php endif; ?>
                <?php else: ?>
                  <code class="asc-phrase"><?= e($phName) ?></code>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>

        <?php if (trim((string)($prop['rationale'] ?? '')) !== ''): ?>
          <p class="muted"><?= te('audio_script_coach.prop.rationale') ?>: <?= e((string)$prop['rationale']) ?></p>
        <?php endif; ?>
        <p class="muted"><?= te('audio_script_coach.prop.confidence') ?>: <?= e((string)$prop['confidence']) ?></p>
        <?php if ($pst === 0 && $canEdit): ?>
          <?php
            $canAddAny = false;
            foreach ($phraseRows as $pr) {
                if (!empty($pr['ok'])) {
                    $canAddAny = true;
                    break;
                }
            }
          ?>
          <form method="post" action="/audio/script-coach/proposal/<?= (int)$prop['id'] ?>" class="asc-prop-form">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="accept">
            <?php if ($action === 'add_dict_with_keys'): ?>
              <label class="asc-check">
                <input type="checkbox" name="link_part" value="1" <?= !empty($prop['link_part']) ? 'checked' : '' ?>>
                <?= te('audio_script_coach.prop.link_part') ?>
              </label>
            <?php endif; ?>
            <label class="asc-check">
              <input type="checkbox" name="force" value="1">
              <?= te('audio_script_coach.prop.force') ?>
            </label>
            <button type="submit" class="btn" <?= (!$canAddAny ? '' : '') ?>><?= te('audio_script_coach.prop.accept') ?></button>
            <?php if (!$canAddAny): ?>
              <span class="muted"><?= te('audio_script_coach.prop.accept_needs_force') ?></span>
            <?php endif; ?>
          </form>
          <form method="post" action="/audio/script-coach/proposal/<?= (int)$prop['id'] ?>" class="asc-prop-form inline">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="reject">
            <button type="submit" class="btn-secondary"><?= te('audio_script_coach.prop.reject') ?></button>
          </form>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  <?php endif; ?>
</section>

<p class="muted asc-reparse"><?= te('audio_script_coach.run.reparse_hint') ?></p>
