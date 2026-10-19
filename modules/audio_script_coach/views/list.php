<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var int $adminId */
/** @var string $day */
?>
<section class="page-head">
  <div class="page-head-row">
    <div></div>
  </div>
</section>

<form method="get" action="/audio/script-coach" class="filters asc-filters">
  <label>
    <?= te('audio_script_coach.list.filter_admin') ?>
    <input type="number" name="admin" value="<?= $adminId > 0 ? (int)$adminId : '' ?>" min="1">
  </label>
  <label>
    <?= te('audio_script_coach.list.filter_day') ?>
    <input type="date" name="day" value="<?= e($day) ?>">
  </label>
  <button type="submit" class="btn"><?= te('audio_script_coach.list.filter') ?></button>
</form>

<?php if ($items === []): ?>
  <p class="muted"><?= te('audio_script_coach.list.empty') ?></p>
<?php else: ?>
  <table class="data">
    <thead>
      <tr>
        <th><?= te('audio_script_coach.list.col.id') ?></th>
        <th><?= te('audio_script_coach.list.col.admin') ?></th>
        <th><?= te('audio_script_coach.list.col.day') ?></th>
        <th><?= te('audio_script_coach.list.col.status') ?></th>
        <th><?= te('audio_script_coach.list.col.phase') ?></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $row): ?>
        <?php $st = (int)($row['status'] ?? 0); ?>
        <tr>
          <td><a href="/audio/script-coach/run/<?= (int)$row['id'] ?>">#<?= (int)$row['id'] ?></a></td>
          <td>
            <?= e((string)($row['admin_name'] ?? '')) ?>
            <span class="muted">#<?= (int)$row['id_admin'] ?></span>
          </td>
          <td><?= e((string)$row['day']) ?></td>
          <td><?= te('audio_script_coach.status.' . $st) ?></td>
          <td><?= e((string)($row['phase'] ?? '')) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
