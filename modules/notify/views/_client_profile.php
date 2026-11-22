<?php
declare(strict_types=1);
/** @var int $client_id */
/** @var list<array<string,mixed>> $to_client */
/** @var list<array<string,mixed>> $about_client */
/** @var list<array<string,mixed>> $tasks */
$tab = trim((string)($_GET['notify_tab'] ?? 'to'));
?>
<section class="notify-history">
  <h3><?= te('notify.client.block_title') ?></h3>
  <nav class="notify-tabs">
    <a href="?notify_tab=to" class="<?= $tab !== 'about' ? 'is-active' : '' ?>"><?= te('notify.client.tab_to') ?></a>
    <a href="?notify_tab=about" class="<?= $tab === 'about' ? 'is-active' : '' ?>"><?= te('notify.client.tab_about') ?></a>
  </nav>

  <?php if ($tab === 'about'): ?>
    <?php if ($about_client === [] && $tasks === []): ?>
      <p class="muted"><?= te('notify.client.empty') ?></p>
    <?php else: ?>
      <table class="data-table">
        <thead><tr><th><?= te('notify.col.when') ?></th><th><?= te('notify.col.event') ?></th><th><?= te('notify.col.channel') ?></th><th><?= te('notify.col.status') ?></th><th><?= te('notify.col.text') ?></th></tr></thead>
        <tbody>
        <?php foreach ($about_client as $row): ?>
          <tr>
            <td><?= e(date('Y-m-d H:i', (int)($row['dtcreate'] ?? $row['DTCREATE'] ?? 0))) ?></td>
            <td><?= e((string)($row['event'] ?? $row['EVENT'] ?? '')) ?></td>
            <td><?= e((string)($row['channel'] ?? $row['CHANNEL'] ?? '')) ?></td>
            <td><?= e((string)($row['status'] ?? $row['STATUS'] ?? '')) ?></td>
            <td><?= e(mb_substr((string)($row['text'] ?? $row['TEXT'] ?? ''), 0, 100)) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php foreach ($tasks as $t): ?>
          <tr>
            <td><?= e(date('Y-m-d H:i', (int)($t['dtcreate'] ?? $t['DTCREATE'] ?? 0))) ?></td>
            <td><?= e((string)($t['event'] ?? $t['EVENT'] ?? '')) ?> · task</td>
            <td>—</td>
            <td><?= e((string)($t['status'] ?? $t['STATUS'] ?? '')) ?> / <?= e((string)($t['outcome'] ?? $t['OUTCOME'] ?? '')) ?></td>
            <td>#<?= (int)($t['id'] ?? $t['ID'] ?? 0) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  <?php else: ?>
    <?php if ($to_client === []): ?>
      <p class="muted"><?= te('notify.client.empty') ?></p>
    <?php else: ?>
      <table class="data-table">
        <thead><tr><th><?= te('notify.col.when') ?></th><th><?= te('notify.col.event') ?></th><th><?= te('notify.col.channel') ?></th><th><?= te('notify.col.status') ?></th><th><?= te('notify.col.text') ?></th></tr></thead>
        <tbody>
        <?php foreach ($to_client as $row): ?>
          <tr>
            <td><?= e(date('Y-m-d H:i', (int)($row['dtcreate'] ?? $row['DTCREATE'] ?? 0))) ?></td>
            <td><?= e((string)($row['event'] ?? $row['EVENT'] ?? '')) ?></td>
            <td><?= e((string)($row['channel'] ?? $row['CHANNEL'] ?? '')) ?></td>
            <td><?= e((string)($row['status'] ?? $row['STATUS'] ?? '')) ?></td>
            <td><?= e(mb_substr((string)($row['text'] ?? $row['TEXT'] ?? ''), 0, 100)) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  <?php endif; ?>

  <p><a href="/notify/journal?client_id=<?= (int)$client_id ?>&scope=<?= $tab === 'about' ? 'about_client' : 'to_client' ?>"><?= te('notify.menu.journal') ?></a></p>
</section>
