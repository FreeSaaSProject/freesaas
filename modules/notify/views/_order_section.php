<?php
declare(strict_types=1);
/** @var int $order_id */
/** @var list<array<string,mixed>> $tasks */
/** @var bool $can_resolve */
?>
<section class="notify-order-tasks">
  <h3><?= te('notify.order.block_title') ?></h3>
  <?php if ($tasks === []): ?>
    <p class="muted"><?= te('notify.order.empty') ?></p>
  <?php else: ?>
    <ul>
      <?php foreach ($tasks as $t):
          $id = (int)($t['id'] ?? $t['ID'] ?? 0);
          $st = (string)($t['status'] ?? $t['STATUS'] ?? '');
      ?>
        <li>
          #<?= $id ?> · <?= e((string)($t['stage_key'] ?? $t['STAGE_KEY'] ?? '')) ?>
          · <?= te('notify.tasks.status.' . ($st !== '' ? $st : 'pending')) ?>
          <?php if ((string)($t['outcome'] ?? $t['OUTCOME'] ?? '') !== ''): ?>
            / <?= e((string)($t['outcome'] ?? $t['OUTCOME'] ?? '')) ?>
          <?php endif; ?>
          <?php if ($can_resolve && $st === 'pending'): ?>
            <form method="post" action="/notify/tasks" class="inline">
              <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
              <input type="hidden" name="action" value="resolve">
              <input type="hidden" name="task_id" value="<?= $id ?>">
              <select name="outcome">
                <option value="confirmed"><?= te('notify.outcome.confirmed') ?></option>
                <option value="no_answer"><?= te('notify.outcome.no_answer') ?></option>
                <option value="client_cancelled"><?= te('notify.outcome.client_cancelled') ?></option>
              </select>
              <button type="submit"><?= te('notify.tasks.resolve') ?></button>
            </form>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
