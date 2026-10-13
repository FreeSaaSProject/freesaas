<?php
declare(strict_types=1);
/** @var list<array{id:string,layer:string,title:string,explain:string,prompt:string}> $cases */
/** @var bool $live_default */
/** @var array<string,mixed>|null $last_run */
/** @var string $profile_id */
$cases = is_array($cases ?? null) ? $cases : [];
$liveDefault = !isset($live_default) || !empty($live_default);
$lastRun = is_array($last_run ?? null) ? $last_run : null;
$profileId = trim((string)($profile_id ?? ''));
if ($profileId === '') {
    $profileId = class_exists('AssistantEvalStore', false)
        ? AssistantEvalStore::defaultProfileId()
        : 'main';
}
$casesJson = json_encode($cases, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($casesJson === false) {
    $casesJson = '[]';
}
$lastRunJson = json_encode($lastRun ?? new stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($lastRunJson === false || $lastRun === null) {
    $lastRunJson = 'null';
}
$baseSystem = '';
if (class_exists('AssistantHarness', false) && class_exists('AssistantTools', false)) {
    $baseSystem = AssistantHarness::buildSystemPrompt(AssistantTools::availableOps());
}
$i18n = [
    'running' => t('assistant.eval.running'),
    'done' => t('assistant.eval.done'),
    'aborted' => t('assistant.eval.aborted'),
    'pass' => t('assistant.eval.status.pass'),
    'fail' => t('assistant.eval.status.fail'),
    'skip' => t('assistant.eval.status.skip'),
    'repaired' => t('assistant.eval.status.repaired'),
    'repaired_note' => t('assistant.eval.repaired_note'),
    'question' => t('assistant.eval.col.question'),
    'answer' => t('assistant.eval.col.answer'),
    'check' => t('assistant.eval.col.check'),
    'result' => t('assistant.eval.col.result'),
    'system' => t('assistant.eval.system_prompt'),
    'retry' => t('assistant.eval.retry_one'),
    'summary' => t('assistant.eval.summary', [
        'pass' => '{pass}',
        'fail' => '{fail}',
        'skip' => '{skip}',
        'ms' => '{ms}',
    ]),
    'totals' => t('assistant.eval.totals', [
        'tests' => '{tests}',
        'clean' => '{clean}',
        'repaired' => '{repaired}',
        'failed' => '{failed}',
        'skip' => '{skip}',
    ]),
    'last_meta' => t('assistant.eval.last_meta', [
        'profile' => '{profile}',
        'when' => '{when}',
    ]),
    'err' => t('assistant.eval.err.request'),
    'err_csrf' => t('assistant.eval.err.csrf'),
    'err_500' => t('assistant.eval.err.server_500'),
    'err_http' => t('assistant.eval.err.http', ['status' => '{status}']),
];
$i18nJson = json_encode($i18n, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($i18nJson === false) {
    $i18nJson = '{}';
}
$hasLast = is_array($lastRun) && !empty($lastRun['rows']);
$lastSummary = is_array($lastRun['summary'] ?? null) ? $lastRun['summary'] : [];
$failCount = (int)($lastSummary['fail'] ?? 0);
?>
<div class="assistant-eval" id="assistant-eval"
     data-eval-url="/assistant/eval"
     data-cases="<?= e($casesJson) ?>"
     data-last-run="<?= e($lastRunJson) ?>"
     data-profile="<?= e($profileId) ?>"
     data-i18n="<?= e($i18nJson) ?>">
  <p class="lede"><?= te('assistant.eval.lede') ?></p>

  <form method="post" action="/assistant/eval" class="assistant-eval__form" id="assistant-eval-form">
    <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
    <label class="assistant-eval__live">
      <input type="hidden" name="live" value="0">
      <input type="checkbox" name="live" value="1" id="assistant-eval-live"<?= $liveDefault ? ' checked' : '' ?>>
      <?= te('assistant.eval.live') ?>
    </label>
    <button type="submit" class="btn-primary" id="assistant-eval-run"><?= te('assistant.eval.run') ?></button>
    <button type="button" class="btn-secondary" id="assistant-eval-retry-fails"<?= ($hasLast && $failCount > 0) ? '' : ' hidden' ?>>
      <?= te('assistant.eval.retry_fails') ?>
    </button>
    <button type="button" class="btn-secondary" id="assistant-eval-abort" hidden><?= te('assistant.eval.abort') ?></button>
    <a class="btn-secondary" href="/assistant"><?= te('assistant.eval.back') ?></a>
  </form>

  <div class="assistant-eval__last" id="assistant-eval-last"<?= $hasLast ? '' : ' hidden' ?>>
    <p class="assistant-eval__last-title"><?= te('assistant.eval.last_title') ?></p>
    <p class="muted assistant-eval__last-meta" id="assistant-eval-last-meta">
      <?php if ($hasLast): ?>
        <?= te('assistant.eval.last_meta', [
            'profile' => (string)($lastRun['profile'] ?? $profileId),
            'when' => (string)($lastRun['finished_at'] ?? $lastRun['saved_at'] ?? ''),
        ]) ?>
      <?php endif; ?>
    </p>
    <p class="muted"><?= te('assistant.eval.last_note') ?></p>
  </div>

  <div class="assistant-eval__panel" id="assistant-eval-panel"<?= $hasLast ? '' : ' hidden' ?>>
    <details class="assistant-eval__system" id="assistant-eval-system-wrap" open>
      <summary><?= te('assistant.eval.system_prompt') ?></summary>
      <p class="muted assistant-eval__system-note"><?= te('assistant.eval.system_prompt_note') ?></p>
      <pre class="assistant-eval__system-pre" id="assistant-eval-system"><?= e(
          $hasLast && trim((string)($lastRun['system_prompt'] ?? '')) !== ''
              ? (string)$lastRun['system_prompt']
              : $baseSystem
      ) ?></pre>
    </details>

    <p class="assistant-eval__msg muted" id="assistant-eval-msg"></p>
    <div class="assistant-eval__bar-block">
      <div class="assistant-eval__bar-label">
        <span id="assistant-eval-bar-label"><?= te('assistant.eval.progress') ?></span>
        <span id="assistant-eval-pct">0%</span>
      </div>
      <div class="assistant-eval__bar" id="assistant-eval-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
        <div class="assistant-eval__fill" id="assistant-eval-fill"></div>
      </div>
    </div>
    <p class="assistant-eval__summary" id="assistant-eval-summary">
      <?php if ($hasLast): ?>
        <?= te('assistant.eval.summary', [
            'pass' => (string)(int)($lastSummary['pass'] ?? 0),
            'fail' => (string)(int)($lastSummary['fail'] ?? 0),
            'skip' => (string)(int)($lastSummary['skip'] ?? 0),
            'ms' => (string)(int)($lastRun['ms'] ?? $lastSummary['ms'] ?? 0),
        ]) ?>
      <?php endif; ?>
    </p>
  </div>

  <div class="assistant-eval__log" id="assistant-eval-log" aria-live="polite"></div>
  <p class="assistant-eval__totals" id="assistant-eval-totals" hidden></p>
</div>
