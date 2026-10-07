<?php
/**
 * @var list<array<string,mixed>> $profiles
 * @var bool $canEdit
 * @var string $flash
 * @var ?array $runProfile
 * @var int $year
 * @var int $month
 * @var string $dateFrom
 * @var string $dateTo
 * @var ?array $report
 * @var ?array $vm
 */
$runId = $runProfile ? (int)$runProfile['ID'] : 0;
?>
<?php if ($flash !== ''): ?>
  <p class="flash-ok"><?= e($flash) ?></p>
<?php endif; ?>

<?php if (is_array($vm)): ?>
  <?php require __DIR__ . '/_summaries_report.php'; ?>
<?php elseif ($profiles === []): ?>
  <p class="muted"><?= te('audio_reports.summaries.empty') ?></p>
  <?php if ($canEdit): ?>
    <p><a class="btn" href="/audio/summaries/new"><?= te('audio_reports.summaries.new') ?></a></p>
  <?php endif; ?>
<?php endif; ?>

<details class="ar-sum-profiles-block"<?= is_array($vm) ? '' : ' open' ?>>
  <summary class="ar-sum-profiles-summary">
    <?= te('audio_reports.summaries.list_title') ?>
    <?php if ($canEdit): ?>
      <a class="btn" href="/audio/summaries/new" onclick="event.stopPropagation()"><?= te('audio_reports.summaries.new') ?></a>
    <?php endif; ?>
  </summary>
  <?php if ($profiles === []): ?>
    <p class="muted"><?= te('audio_reports.summaries.empty') ?></p>
  <?php else: ?>
    <table class="ar-sum-table ar-sum-profiles">
      <thead>
        <tr>
          <th><?= te('audio_reports.summaries.col_name') ?></th>
          <th><?= te('audio_reports.summaries.col_position') ?></th>
          <th><?= te('audio_reports.summaries.col_default') ?></th>
          <th><?= te('audio_reports.summaries.col_enabled') ?></th>
          <?php if ($canEdit): ?>
            <th></th>
          <?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($profiles as $p):
            $pid = (int)$p['ID'];
            $isActive = $runId > 0 && $pid === $runId;
            ?>
          <tr<?= $isActive ? ' class="is-active"' : '' ?>>
            <td>
              <a href="/audio/summaries?profile=<?= $pid ?>&amp;year=<?= (int)$year ?>&amp;month=<?= (int)$month ?>"<?= $isActive ? ' aria-current="page"' : '' ?>>
                <?= e((string)$p['NAME']) ?>
              </a>
            </td>
            <td><?= (int)$p['ID_POSITION'] ?></td>
            <td><?= !empty($p['IS_DEFAULT']) ? te('audio_reports.summaries.yes') : te('audio_reports.summaries.no') ?></td>
            <td><?= !empty($p['ENABLED']) ? te('audio_reports.summaries.yes') : te('audio_reports.summaries.no') ?></td>
            <?php if ($canEdit): ?>
              <td><a href="/audio/summaries/<?= $pid ?>"><?= te('audio_reports.summaries.edit') ?></a></td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</details>

<script>
window.arSumI18n = {
  day: <?= tj('audio_reports.summaries.js.day') ?>,
  hours: <?= tj('audio_reports.summaries.js.hours') ?>,
  needPosition: <?= tj('audio_reports.summaries.js.need_position') ?>
};
</script>
