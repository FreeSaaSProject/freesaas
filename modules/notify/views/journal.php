<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var int $page */
/** @var int $limit */
/** @var int $client_id */
/** @var string $scope */
/** @var string $event */
/** @var string $status */
/** @var string $q */
?>
<section class="page-head">
  <p class="lede"><?= te('notify.journal.lede') ?></p>
</section>

<form method="get" class="filters">
  <label><?= te('notify.journal.filter_client') ?>
    <input type="number" name="client_id" value="<?= $client_id > 0 ? $client_id : '' ?>" min="1" autocomplete="off">
  </label>
  <label><?= te('notify.journal.filter_scope') ?>
    <select name="scope">
      <option value=""><?= te('notify.journal.scope_all') ?></option>
      <option value="to_client"<?= $scope === 'to_client' ? ' selected' : '' ?>><?= te('notify.journal.scope_to_client') ?></option>
      <option value="about_client"<?= $scope === 'about_client' ? ' selected' : '' ?>><?= te('notify.journal.scope_about_client') ?></option>
    </select>
  </label>
  <label><?= te('notify.journal.filter_event') ?>
    <input type="text" name="event" value="<?= e($event) ?>" autocomplete="off">
  </label>
  <label><?= te('notify.journal.filter_status') ?>
    <input type="text" name="status" value="<?= e($status) ?>" autocomplete="off">
  </label>
  <label><?= te('notify.journal.filter_q') ?>
    <input type="search" name="q" value="<?= e($q) ?>" autocomplete="off">
  </label>
  <button type="submit" class="btn-secondary"><?= te('notify.filter.apply') ?></button>
</form>

<?php if ($items === []): ?>
  <p class="muted"><?= te('notify.journal.empty') ?></p>
<?php else: ?>
  <table class="data-table">
    <thead>
      <tr>
        <th>ID</th>
        <th><?= te('notify.col.when') ?></th>
        <th><?= te('notify.col.event') ?></th>
        <th><?= te('notify.col.channel') ?></th>
        <th><?= te('notify.col.address') ?></th>
        <th><?= te('notify.col.client') ?></th>
        <th><?= te('notify.col.status') ?></th>
        <th><?= te('notify.col.text') ?></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $row): ?>
        <tr>
          <td><?= (int)($row['id'] ?? $row['ID'] ?? 0) ?></td>
          <td><?= e(date('Y-m-d H:i', (int)($row['dtcreate'] ?? $row['DTCREATE'] ?? 0))) ?></td>
          <td><?= e((string)($row['event'] ?? $row['EVENT'] ?? '')) ?></td>
          <td><?= e((string)($row['channel'] ?? $row['CHANNEL'] ?? '')) ?></td>
          <td><?= e((string)($row['address'] ?? $row['ADDRESS'] ?? '')) ?></td>
          <td><?= (int)($row['client_id'] ?? $row['CLIENT_ID'] ?? 0) ?></td>
          <td><?= e((string)($row['status'] ?? $row['STATUS'] ?? '')) ?></td>
          <td><?= e(mb_substr((string)($row['text'] ?? $row['TEXT'] ?? ''), 0, 120)) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <p class="muted"><?= te('notify.journal.pager', ['total' => (string)(int)$total, 'page' => (string)(int)$page]) ?></p>
<?php endif; ?>
