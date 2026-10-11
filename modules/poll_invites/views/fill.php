<?php
declare(strict_types=1);
/** @var array<string,mixed> $invite */
/** @var array<string,mixed>|null $poll */
/** @var array{path:list<array{qid:int,question:array<string,mixed>,answer:mixed}>,next_qid:int,done:bool,needs_sign:bool} $state */
/** @var array<string,mixed>|null $currentQ */
/** @var array<int|string,string> $dataText */
/** @var bool $showHistory */
/** @var bool $canSign */
/** @var string $message */
/** @var string $error */
$id = (int)$invite['id'];
$completed = (int)$invite['dt_completed'] > 0;
?>
<section class="page-head">
  <div class="page-head-row">
    <div>
      <h1><?= te('poll_invites.fill.title', ['id' => $id]) ?></h1>
      <?php if ($poll): ?>
        <p class="muted"><?= e((string)$poll['name']) ?></p>
      <?php endif; ?>
    </div>
    <div class="page-actions">
      <a class="btn-secondary" href="/poll-invites/<?= $id ?>/edit"><?= te('poll_invites.action.edit') ?></a>
    </div>
  </div>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<?php if ($completed): ?>
  <div class="flash flash-ok"><?= te('poll_invites.fill.completed') ?></div>
<?php endif; ?>

<?php if ($showHistory && $state['path']): ?>
<section class="panel">
  <h2><?= te('poll_invites.fill.history') ?></h2>
  <ol>
    <?php foreach ($state['path'] as $step): ?>
      <li>
        <strong><?= e((string)$step['question']['name']) ?></strong>
        — <?= e(PollWalkEngine::answerLabel($step['question'], $step['answer'])) ?>
        <?php
          $tq = $dataText[$step['qid']] ?? $dataText[(string)$step['qid']] ?? '';
          if ($tq !== ''):
        ?>
          <div class="muted"><?= e_nl((string)$tq) ?></div>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ol>
</section>
<?php endif; ?>

<?php if ($currentQ && !$completed): ?>
<form method="post" class="form-grid form-wide">
  <fieldset>
    <legend><?= e((string)$currentQ['name']) ?></legend>
    <?php if (!empty($currentQ['descr_admin'])): ?>
      <p><?= e_nl((string)$currentQ['descr_admin']) ?></p>
    <?php endif; ?>
    <?php
      $qid = (int)$currentQ['id'];
      $multy = !empty($currentQ['multy_opts']);
      $answers = $currentQ['_ANSWERS_'] ?? [];
    ?>
    <?php if ($multy): ?>
      <?php foreach ($answers as $aid => $a): ?>
        <?php if (isset($a['enabled']) && !(int)$a['enabled']) { continue; } ?>
        <label class="check">
          <input type="checkbox" name="answers[<?= $qid ?>][]" value="<?= (int)$aid ?>">
          <?= e((string)$a['name']) ?>
          <?php if (!empty($a['negative'])): ?>
            <span class="muted">(<?= te('poll_invites.fill.negative_hint') ?>)</span>
          <?php endif; ?>
        </label>
      <?php endforeach; ?>
    <?php else: ?>
      <?php foreach ($answers as $aid => $a): ?>
        <?php if (isset($a['enabled']) && !(int)$a['enabled']) { continue; } ?>
        <label class="check">
          <input type="radio" name="answers[<?= $qid ?>]" value="<?= (int)$aid ?>" required>
          <?= e((string)$a['name']) ?>
          <?php if (!empty($a['negative'])): ?>
            <span class="muted">(<?= te('poll_invites.fill.negative_hint') ?>)</span>
          <?php endif; ?>
        </label>
      <?php endforeach; ?>
    <?php endif; ?>

    <?php if ((int)($currentQ['req_text_len'] ?? 0) > 0): ?>
      <label><?= te('poll_invites.fill.text_answer') ?>
        <textarea name="texts[<?= $qid ?>]" rows="2" minlength="<?= (int)$currentQ['req_text_len'] ?>"><?= e((string)($dataText[$qid] ?? '')) ?></textarea>
      </label>
    <?php endif; ?>
  </fieldset>

  <div class="page-actions">
    <button class="btn-primary" type="submit" name="save" value="1"><?= te('poll_invites.fill.save') ?></button>
  </div>
</form>
<?php elseif (!$completed): ?>
  <p class="muted"><?= te('poll_invites.fill.no_questions') ?></p>
  <form method="post">
    <button class="btn-primary" type="submit" name="complete" value="1"><?= te('poll_invites.fill.complete') ?></button>
  </form>
<?php endif; ?>

<?php if ($canSign && !empty($invite['sign_req']) && empty($invite['id_supervisor_signed'])): ?>
<form method="post" style="margin-top:1rem">
  <button class="btn-secondary" type="submit" name="sign_supervisor" value="1"><?= te('poll_invites.fill.sign') ?></button>
</form>
<?php endif; ?>
