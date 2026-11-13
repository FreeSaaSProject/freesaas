<?php
declare(strict_types=1);
/** @var string $mode not_found|expired|not_yet|form */
?>
<main class="auth-stage poll-public"><div class="auth-panel">
<?php if ($mode === 'not_found'): ?>
  <h1><?= te('poll_invites.public.not_found_title') ?></h1>
  <p class="muted"><?= te('poll_invites.public.not_found') ?></p>
<?php elseif ($mode === 'expired'): ?>
  <h1><?= e($title) ?></h1>
  <p class="muted"><?= te('poll_invites.public.expired') ?></p>
<?php elseif ($mode === 'not_yet'): ?>
  <h1><?= e($title) ?></h1>
  <p class="muted"><?= te('poll_invites.public.not_yet', ['date' => I18n::formatDate((int)$invite['dt_avail'], 'datetime')]) ?></p>
<?php else:
    $intro = trim(PollPublic::render((string)($invite['text_admin_to_user'] ?? ''), $vars));
    $pollIntro = trim(PollPublic::render((string)($poll['descr_site'] ?? ''), $vars));
    $n = 0;
?>
  <h1><?= e($title) ?></h1>
  <?php if (!empty($flash)): ?>
    <div class="flash <?= ($flash['type'] ?? '') === 'ok' ? 'flash-ok' : 'flash-warn' ?>"><?= e((string)$flash['message']) ?></div>
  <?php endif; ?>
  <?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>
  <?php if ($intro !== ''): ?><div class="poll-public-intro"><?= e_nl($intro) ?></div><?php endif; ?>
  <?php if ($pollIntro !== ''): ?><div class="poll-public-intro muted"><?= e_nl($pollIntro) ?></div><?php endif; ?>
  <?php if ($state === 'completed'): ?>
    <p class="muted"><?= (int)$invite['dt_expire'] > 0
        ? te('poll_invites.public.can_edit_until', ['date' => I18n::formatDate((int)$invite['dt_expire'], 'datetime')])
        : te('poll_invites.public.can_edit') ?></p>
  <?php endif; ?>
  <form method="post" class="poll-public-form">
    <?php foreach ($questions as $qid => $q):
        $qid = (int)$qid;
        $n++;
        $answers = array_filter($q['_ANSWERS_'] ?? [], static fn(array $a): bool => !isset($a['enabled']) || (int)$a['enabled'] === 1);
        $multi = !empty($q['multy_opts']);
        $chosen = $dataOpts[$qid] ?? $dataOpts[(string)$qid] ?? null;
        $text = (string)($dataText[$qid] ?? $dataText[(string)$qid] ?? '');
        $hint = trim(PollPublic::render((string)($personal['hints'][$qid] ?? $q['descr_site'] ?? ''), $vars));
        $withText = $answers === [] || (int)($q['req_text_len'] ?? 0) > 0;
    ?>
    <fieldset class="poll-public-q<?= $missingQid === $qid ? ' is-missing' : '' ?>" id="q<?= $qid ?>">
      <legend><span class="muted"><?= $n ?>.</span> <?= e(PollPublic::render((string)$q['name'], $vars)) ?></legend>
      <?php if ($hint !== ''): ?><p class="muted poll-public-hint"><?= e_nl($hint) ?></p><?php endif; ?>
      <?php foreach ($answers as $aid => $a):
          $aid = (int)$aid;
          $checked = $multi ? (is_array($chosen) && isset($chosen[$aid])) : ((int)$chosen === $aid && $chosen !== null);
      ?>
        <label class="check">
          <input type="<?= $multi ? 'checkbox' : 'radio' ?>" name="answers[<?= $qid ?>]<?= $multi ? '[]' : '' ?>" value="<?= $aid ?>"<?= $checked ? ' checked' : '' ?>>
          <?= e(PollPublic::render((string)$a['name'], $vars)) ?>
        </label>
      <?php endforeach; ?>
      <?php if ($withText): ?>
        <textarea name="texts[<?= $qid ?>]" rows="<?= $answers === [] ? 3 : 2 ?>"
          <?= $answers !== [] ? 'placeholder="' . te('poll_invites.public.comment') . '"' : '' ?>><?= e($text) ?></textarea>
      <?php endif; ?>
    </fieldset>
    <?php endforeach; ?>
    <button class="btn-primary" type="submit"><?= $state === 'completed' ? te('poll_invites.public.update') : te('poll_invites.public.submit') ?></button>
  </form>
<?php endif; ?>
</div></main>
