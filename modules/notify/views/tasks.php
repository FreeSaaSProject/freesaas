<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var string $status */
/** @var int $client_id */
/** @var string $message */
/** @var string $error */
/** @var bool $can_resolve */
?>
<section class="page-head">
  <p class="lede"><?= te('notify.tasks.lede') ?></p>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<form method="get" class="filters">
  <label><?= te('notify.tasks.filter_status') ?>
    <select name="status">
      <?php foreach (['', 'pending', 'done', 'cancelled', 'expired'] as $st): ?>
        <option value="<?= e($st) ?>"<?= $status === $st ? ' selected' : '' ?>><?= $st === '' ? te('core.common.all') : te('notify.tasks.status.' . $st) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('notify.journal.filter_client') ?>
    <input type="number" name="client_id" value="<?= $client_id > 0 ? $client_id : '' ?>" min="1" autocomplete="off">
  </label>
  <button type="submit" class="btn-secondary"><?= te('notify.filter.apply') ?></button>
</form>

<?php if ($items === []): ?>
  <p class="muted"><?= te('notify.tasks.empty') ?></p>
<?php else: ?>
  <table class="data-table">
    <thead>
      <tr>
        <th>ID</th>
        <th><?= te('notify.col.event') ?></th>
        <th><?= te('notify.col.entity') ?></th>
        <th><?= te('notify.col.client') ?></th>
        <th><?= te('notify.col.status') ?></th>
        <th><?= te('notify.col.outcome') ?></th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $row):
          $id = (int)($row['id'] ?? $row['ID'] ?? 0);
          $st = (string)($row['status'] ?? $row['STATUS'] ?? '');
          $ev = (string)($row['event'] ?? $row['EVENT'] ?? '');
      ?>
        <tr>
          <td><?= $id ?></td>
          <td><?= e($ev) ?></td>
          <td><?= e((string)($row['entity'] ?? $row['ENTITY'] ?? '')) ?> #<?= (int)($row['entity_id'] ?? $row['ENTITY_ID'] ?? 0) ?></td>
          <td><?= (int)($row['client_id'] ?? $row['CLIENT_ID'] ?? 0) ?></td>
          <td><?= te('notify.tasks.status.' . ($st !== '' ? $st : 'pending')) ?></td>
          <td><?= e((string)($row['outcome'] ?? $row['OUTCOME'] ?? '')) ?></td>
          <td>
            <?php if ($can_resolve && $st === 'pending'): ?>
              <form method="post" class="notify-resolve filters">
                <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
                <input type="hidden" name="action" value="resolve">
                <input type="hidden" name="task_id" value="<?= $id ?>">
                <label><?= te('notify.col.outcome') ?>
                  <select name="outcome">
                    <option value="confirmed"><?= te('notify.outcome.confirmed') ?></option>
                    <option value="no_answer"><?= te('notify.outcome.no_answer') ?></option>
                    <option value="client_cancelled"><?= te('notify.outcome.client_cancelled') ?></option>
                  </select>
                </label>
                <label><?= te('notify.tasks.comment') ?>
                  <input type="text" name="comment" autocomplete="off">
                </label>
                <button type="submit" class="btn-primary btn-sm"><?= te('notify.tasks.resolve') ?></button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
