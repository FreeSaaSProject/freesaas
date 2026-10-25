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
 * @var list<string> $llmErrors
 * @var array<string,mixed>|null $llmFilter
 * @var string|null $proposalsEmptyReason
 * @var bool $showReparseHint
 * @var bool $autoDrain
 * @var int $phasesTotal
 * @var int $phasesDone
 */
$status = (int)($run['status'] ?? 0);
$autoDrain = !empty($autoDrain);
$phasesTotal = max(1, (int)($phasesTotal ?? 4));
$phasesDone = max(0, (int)($phasesDone ?? 0));
$llmErrors = is_array($llmErrors ?? null) ? $llmErrors : [];
$llmFilter = is_array($llmFilter ?? null) ? $llmFilter : null;
$proposalsEmptyReason = isset($proposalsEmptyReason) ? (string)$proposalsEmptyReason : '';
$showReparseHint = !empty($showReparseHint);
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
        · <?= te('audio_script_coach.depth.' . CoachRunsRepository::normalizeDepth((string)($run['depth'] ?? 'normal'))) ?>
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

<?php if ($canEdit): ?>
  <div class="asc-actions"
       data-asc-run="<?= (int)$run['id'] ?>"
       data-asc-auto="<?= $autoDrain ? '1' : '0' ?>"
       data-asc-phases-done="<?= (int)$phasesDone ?>"
       data-asc-phases-total="<?= (int)$phasesTotal ?>"
       data-asc-msg-working="<?= e(t('audio_script_coach.progress.working')) ?>"
       data-asc-msg-done="<?= e(t('audio_script_coach.progress.done_short')) ?>"
       data-asc-msg-aborted="<?= e(t('audio_script_coach.progress.aborted')) ?>">
    <?php if ($status !== 2): ?>
      <form method="post" action="/audio/script-coach/run/<?= (int)$run['id'] ?>" class="inline">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="advance">
        <button type="submit" class="btn-secondary"><?= te('audio_script_coach.run.advance') ?></button>
      </form>
      <button type="button" class="btn-primary" data-asc-drain><?= te('audio_script_coach.run.drain') ?></button>
    <?php endif; ?>
    <?php if ($status === 2 || $status === 3): ?>
      <?php $curDepth = CoachRunsRepository::normalizeDepth((string)($run['depth'] ?? 'normal')); ?>
      <form method="post" action="/audio/script-coach/run/<?= (int)$run['id'] ?>" class="inline asc-rebuild"
            onsubmit="return confirm(<?= tj('audio_script_coach.run.rebuild_confirm') ?>);">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="rebuild">
        <label class="asc-rebuild-depth">
          <span class="muted"><?= te('audio_script_coach.depth.label') ?></span>
          <select name="depth">
            <option value="normal"<?= $curDepth === 'normal' ? ' selected' : '' ?>><?= te('audio_script_coach.depth.normal') ?></option>
            <option value="hard"<?= $curDepth === 'hard' ? ' selected' : '' ?>><?= te('audio_script_coach.depth.hard') ?></option>
          </select>
        </label>
        <button type="submit" class="btn-secondary"><?= te('audio_script_coach.run.rebuild') ?></button>
      </form>
    <?php endif; ?>
  </div>

  <div id="asc-job-overlay" class="asc-job-overlay" hidden>
    <div class="asc-job-panel" role="dialog" aria-modal="true" aria-labelledby="asc-job-title">
      <h3 id="asc-job-title" class="subhead"><?= te('audio_script_coach.progress.title') ?></h3>
      <p id="asc-job-msg" class="asc-job-msg muted"><?= te('audio_script_coach.progress.working') ?></p>
      <div class="asc-job-bar-label">
        <span><?= te('audio_script_coach.progress.bar') ?></span>
        <span id="asc-job-pct">0%</span>
      </div>
      <div class="asc-job-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
        <div id="asc-job-fill" class="asc-job-fill"></div>
      </div>
      <div id="asc-job-log" class="asc-job-log" aria-live="polite"></div>
      <div class="asc-job-actions actions">
        <button type="button" class="btn-secondary" id="asc-job-abort" hidden><?= te('audio_script_coach.progress.abort') ?></button>
        <button type="button" class="btn-primary" id="asc-job-ok" hidden><?= te('audio_script_coach.progress.ok') ?></button>
      </div>
    </div>
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
          <?php
            $missedN = count(is_array($sf['missed_starts'] ?? null) ? $sf['missed_starts'] : []);
          ?>
          <?php if ($missedN > 0): ?>
            <span class="asc-badge asc-badge--warn"><?= te('audio_script_coach.run.missed_starts', [
                'n' => (string)$missedN,
            ]) ?></span>
          <?php endif; ?>
          <?php
            $stageGapN = count(is_array($sf['stage_gaps'] ?? null) ? $sf['stage_gaps'] : []);
          ?>
          <?php if ($stageGapN > 0): ?>
            <span class="asc-badge asc-badge--warn"><?= te('audio_script_coach.run.stage_gaps', [
                'n' => (string)$stageGapN,
            ]) ?></span>
          <?php endif; ?>
          <?php if (!empty($sf['force_enrich'])): ?>
            <span class="asc-badge"><?= te('audio_script_coach.run.force_enrich') ?></span>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
<?php endif; ?>

<?php if ($llmErrors !== []): ?>
  <div class="flash flash-warn asc-llm-errors">
    <p><strong><?= te('audio_script_coach.run.llm_errors_title') ?></strong></p>
    <ul>
      <?php foreach ($llmErrors as $err): ?>
        <li><?= e((string)$err) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if (is_array($llmFilter)): ?>
  <?php $skipReason = trim((string)($llmFilter['skip_reason'] ?? '')); ?>
  <section class="asc-filter">
    <h2><?= te('audio_script_coach.run.filter_title') ?></h2>
    <?php if ($skipReason !== ''): ?>
      <p class="muted">
        <?php if ($skipReason === 'nothing_to_enrich'): ?>
          <?= te('audio_script_coach.run.filter_skip_nothing') ?>
        <?php elseif ($skipReason === 'no_windows'): ?>
          <?= te('audio_script_coach.run.filter_skip_no_windows') ?>
        <?php elseif ($skipReason === 'llm_missing'): ?>
          <?= te('audio_script_coach.err.llm_missing') ?>
        <?php else: ?>
          <?= te('audio_script_coach.run.filter_skip_generic', ['reason' => $skipReason]) ?>
        <?php endif; ?>
      </p>
    <?php else: ?>
      <p>
        <?= te('audio_script_coach.run.filter_phrases', [
            'raw' => (string)(int)($llmFilter['phrases_raw'] ?? 0),
            'kept' => (string)(int)($llmFilter['phrases_kept'] ?? 0),
            'dropped' => (string)(int)($llmFilter['phrases_dropped_total'] ?? 0),
        ]) ?>
      </p>
      <?php if (!empty($llmFilter['phrases_dropped'])): ?>
        <ul class="asc-filter-list">
          <?php foreach ($llmFilter['phrases_dropped'] as $row): ?>
            <li><?= e((string)($row['label'] ?? $row['reason'] ?? '')) ?>: <strong><?= (int)($row['n'] ?? 0) ?></strong></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <p>
        <?= te('audio_script_coach.run.filter_proposals', [
            'raw' => (string)(int)($llmFilter['proposals_raw'] ?? 0),
            'kept' => (string)(int)($llmFilter['proposals_kept'] ?? 0),
            'dropped' => (string)(int)($llmFilter['proposals_dropped_total'] ?? 0),
        ]) ?>
      </p>
      <?php if (!empty($llmFilter['proposals_dropped'])): ?>
        <ul class="asc-filter-list">
          <?php foreach ($llmFilter['proposals_dropped'] as $row): ?>
            <li><?= e((string)($row['label'] ?? $row['reason'] ?? '')) ?>: <strong><?= (int)($row['n'] ?? 0) ?></strong></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    <?php endif; ?>
  </section>
<?php endif; ?>

<section class="asc-proposals">
  <h2><?= te('audio_script_coach.run.proposals_title') ?></h2>
  <?php if ($proposals === []): ?>
    <?php
      $emptyKey = 'audio_script_coach.run.proposals_empty';
      if ($proposalsEmptyReason === 'need_llm') {
          $emptyKey = 'audio_script_coach.run.proposals_need_llm';
      } elseif ($proposalsEmptyReason === 'llm_errors') {
          $emptyKey = 'audio_script_coach.run.proposals_llm_failed';
      } elseif ($proposalsEmptyReason === 'nothing_to_enrich') {
          $emptyKey = 'audio_script_coach.run.proposals_nothing';
      } elseif ($proposalsEmptyReason === 'no_windows') {
          $emptyKey = 'audio_script_coach.run.proposals_no_windows';
      }
    ?>
    <p class="muted"><?= te($emptyKey) ?></p>
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
          $doneKeys = [];
          foreach ($applyKeys as $ak) {
              if ((int)($ak['id'] ?? 0) > 0 && empty($ak['skipped'])) {
                  $doneKeys[] = $ak;
              }
          }
          $actionableRows = [];
          $exactRows = [];
          $rejectOnlyRows = [];
          if ($pst === 0) {
              $annotated = CoachPhraseCheck::annotate(is_array($prop['phrases'] ?? null) ? $prop['phrases'] : []);
              foreach ($annotated as $row) {
                  $exactOv = null;
                  foreach (is_array($row['overlaps'] ?? null) ? $row['overlaps'] : [] as $ov) {
                      if ((string)($ov['relation'] ?? '') === 'exact') {
                          $exactOv = $ov;
                          break;
                      }
                  }
                  if ($exactOv !== null) {
                      $exactRows[] = [
                          'name' => (string)$row['name'],
                          'id' => (int)($exactOv['id'] ?? 0),
                      ];
                      continue;
                  }
                  $actionableRows[] = $row;
              }
          } elseif ($pst === 2) {
              foreach (is_array($prop['phrases'] ?? null) ? $prop['phrases'] : [] as $ph) {
                  $rejectOnlyRows[] = ['name' => (string)$ph];
              }
          }
          $propUrl = '/audio/script-coach/proposal/' . (int)$prop['id'];
          $formId = 'asc-prop-' . (int)$prop['id'];
          $hasActionable = $actionableRows !== [];
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
            <?php if ($applyDictId > 0 && ($pst === 1 || $doneKeys !== [])): ?>
              · <a href="/audio/dicts/<?= $applyDictId ?>/edit">#<?= $applyDictId ?></a>
            <?php endif; ?>
          </p>
        <?php endif; ?>

        <?php if ($doneKeys !== [] || $exactRows !== []): ?>
          <div class="asc-phrases">
            <div class="asc-phrases-label"><?= te('audio_script_coach.prop.phrases_added') ?>:</div>
            <table class="asc-phrase-table">
              <tbody>
                <?php foreach ($doneKeys as $row): ?>
                  <tr class="asc-phrase-row--ok">
                    <td class="asc-td-mark"><span class="asc-ok">✓</span></td>
                    <td class="asc-td-phrase">
                      <a class="asc-key-link" href="/audio/keys/<?= (int)$row['id'] ?>/edit">#<?= (int)$row['id'] ?></a>
                      <code class="asc-phrase"><?= e((string)($row['name'] ?? '')) ?></code>
                    </td>
                    <td class="asc-td-note muted"><?= te('audio_script_coach.prop.added') ?></td>
                    <td class="asc-td-act"></td>
                  </tr>
                <?php endforeach; ?>
                <?php foreach ($exactRows as $row): ?>
                  <tr class="asc-phrase-row--ok">
                    <td class="asc-td-mark"><span class="asc-ok">✓</span></td>
                    <td class="asc-td-phrase">
                      <?php if ((int)$row['id'] > 0): ?>
                        <a class="asc-key-link" href="/audio/keys/<?= (int)$row['id'] ?>/edit">#<?= (int)$row['id'] ?></a>
                      <?php endif; ?>
                      <code class="asc-phrase"><?= e((string)$row['name']) ?></code>
                    </td>
                    <td class="asc-td-note muted"><?= te('audio_script_coach.prop.already_exact') ?></td>
                    <td class="asc-td-act"></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>

        <?php if ($pst === 0 && $canEdit && $hasActionable): ?>
          <?php
            $overlapRelLabels = [
                'exact' => t('audio_script_coach.prop.rel.exact'),
                'contains' => t('audio_script_coach.prop.rel.contains'),
                'contained' => t('audio_script_coach.prop.rel.contained'),
            ];
            $targetDictId = $applyDictId > 0 ? $applyDictId : $did;
          ?>
          <div class="asc-phrases">
            <div class="asc-phrases-label"><?= te('audio_script_coach.prop.phrases_pending') ?>:</div>
            <table class="asc-phrase-table">
              <thead>
                <tr>
                  <th class="asc-td-phrase" scope="col"><?= te('audio_script_coach.prop.phrases') ?></th>
                  <th class="asc-td-note" scope="col"><?= te('audio_script_coach.prop.overlap') ?></th>
                  <th class="asc-td-act" scope="col"></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($actionableRows as $idx => $row): ?>
                  <?php
                    $phName = (string)($row['name'] ?? '');
                    $overlaps = is_array($row['overlaps'] ?? null) ? $row['overlaps'] : [];
                    $looklike = 0;
                    foreach ($overlaps as $ov) {
                        if ((string)($ov['relation'] ?? '') === 'contains' && (int)($ov['id'] ?? 0) > 0) {
                            $looklike = (int)$ov['id'];
                            break;
                        }
                    }
                    if ($looklike < 1 && $overlaps !== []) {
                        $looklike = (int)($overlaps[0]['id'] ?? 0);
                    }
                    $newKeyQ = ['name' => $phName];
                    if ($targetDictId > 0) {
                        $newKeyQ['dict'] = $targetDictId;
                    }
                    if ($looklike > 0) {
                        $newKeyQ['looklike'] = $looklike;
                    }
                    $newKeyHref = '/audio/keys/new?' . http_build_query($newKeyQ);
                  ?>
                  <tr>
                    <td class="asc-td-phrase">
                      <code class="asc-phrase"><?= e($phName) ?></code>
                    </td>
                    <td class="asc-td-note">
                      <?php if ($overlaps !== []): ?>
                        <ul class="asc-overlap-list">
                          <?php foreach ($overlaps as $ov): ?>
                            <?php
                              $oid = (int)($ov['id'] ?? 0);
                              $rel = (string)($ov['relation'] ?? '');
                              $relLabel = $overlapRelLabels[$rel] ?? $rel;
                              $ovName = (string)($ov['name'] ?? '');
                            ?>
                            <li>
                              <a href="/audio/keys/<?= $oid ?>/edit" target="_blank" rel="noopener">
                                #<?= $oid ?> «<?= e($ovName) ?>»
                              </a>
                              <span class="muted">(<?= e($relLabel) ?>)</span>
                            </li>
                          <?php endforeach; ?>
                        </ul>
                      <?php else: ?>
                        <span class="muted">—</span>
                      <?php endif; ?>
                    </td>
                    <td class="asc-td-act">
                      <?php if ($action === 'add_key' && $targetDictId > 0): ?>
                        <a class="btn-secondary btn-sm" href="<?= e($newKeyHref) ?>"
                           target="_blank" rel="noopener" data-nav-no-ret>
                          <?= te('audio_script_coach.prop.open_key_form') ?>
                        </a>
                      <?php elseif ($action === 'add_key'): ?>
                        <span class="muted"><?= te('audio_script_coach.prop.need_dict') ?></span>
                      <?php else: ?>
                        <span class="muted">—</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <?php if (trim((string)($prop['rationale'] ?? '')) !== ''): ?>
              <p class="muted"><?= te('audio_script_coach.prop.rationale') ?>: <?= e((string)$prop['rationale']) ?></p>
            <?php endif; ?>
            <p class="muted"><?= te('audio_script_coach.prop.confidence') ?>: <?= e((string)$prop['confidence']) ?></p>
            <p class="muted asc-phrase-hint"><?= te('audio_script_coach.prop.phrase_hint') ?></p>
            <?php if ($action === 'add_dict_with_keys'): ?>
              <form method="post" action="<?= e($propUrl) ?>" class="asc-prop-form" id="<?= e($formId) ?>">
                <?= Csrf::field() ?>
                <?php if ($applyDictId < 1): ?>
                  <label class="asc-check">
                    <input type="checkbox" name="link_part" value="1" <?= !empty($prop['link_part']) ? 'checked' : '' ?>>
                    <?= te('audio_script_coach.prop.link_part') ?>
                  </label>
                <?php endif; ?>
                <label class="asc-check">
                  <input type="checkbox" name="force" value="1">
                  <?= te('audio_script_coach.prop.force') ?>
                </label>
                <div class="asc-prop-actions">
                  <button type="submit" class="btn-primary" name="action" value="accept_all">
                    <?= te('audio_script_coach.prop.accept_create_dict') ?>
                  </button>
                </div>
              </form>
            <?php endif; ?>
          </div>
          <form method="post" action="<?= e($propUrl) ?>" class="asc-prop-form inline">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="reject">
            <button type="submit" class="btn-secondary"><?= te('audio_script_coach.prop.reject') ?></button>
          </form>
        <?php else: ?>
          <?php if ($pst === 1 && $doneKeys === [] && is_array($prop['phrases'] ?? null) && $prop['phrases'] !== []): ?>
            <div class="asc-phrases">
              <div class="asc-phrases-label"><?= te('audio_script_coach.prop.phrases') ?>:</div>
              <table class="asc-phrase-table">
                <tbody>
                  <?php foreach ($prop['phrases'] as $ph): ?>
                    <tr>
                      <td class="asc-td-phrase"><code class="asc-phrase"><?= e((string)$ph) ?></code></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
          <?php if ($rejectOnlyRows !== []): ?>
            <div class="asc-phrases">
              <div class="asc-phrases-label"><?= te('audio_script_coach.prop.phrases') ?>:</div>
              <table class="asc-phrase-table">
                <tbody>
                  <?php foreach ($rejectOnlyRows as $row): ?>
                    <tr>
                      <td class="asc-td-phrase"><code class="asc-phrase"><?= e((string)$row['name']) ?></code></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
          <?php if (trim((string)($prop['rationale'] ?? '')) !== ''): ?>
            <p class="muted"><?= te('audio_script_coach.prop.rationale') ?>: <?= e((string)$prop['rationale']) ?></p>
          <?php endif; ?>
          <p class="muted"><?= te('audio_script_coach.prop.confidence') ?>: <?= e((string)$prop['confidence']) ?></p>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  <?php endif; ?>
</section>

<?php if ($showReparseHint): ?>
  <p class="muted asc-reparse"><?= te('audio_script_coach.run.reparse_hint') ?></p>
<?php endif; ?>
