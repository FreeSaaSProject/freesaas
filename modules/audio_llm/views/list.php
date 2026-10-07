<?php
declare(strict_types=1);
/** @var string $kind */
/** @var list<array> $items */
/** @var int $total */
/** @var string $message */
$kinds = ['regular' => t('audio_llm.kind.regular'), 'cyclic' => t('audio_llm.kind.cyclic'), 'spawned' => t('audio_llm.kind.spawned')];
?>
<section class="page-head">
  <p class="lede"><?= te('audio_llm.list.lede') ?></p>
  <p><a class="btn" href="<?= e(NavContext::withReturn('/audio/llm-studies/new')) ?>"><?= te('audio_llm.list.add') ?></a></p>
</section>
<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>

<p class="tabs">
  <?php foreach ($kinds as $k => $label): ?>
    <?php if ($k === $kind): ?>
      <strong><?= e($label) ?></strong>
    <?php else: ?>
      <a href="/audio/llm-studies?kind=<?= e(rawurlencode($k)) ?>"><?= e($label) ?></a>
    <?php endif; ?>
    <?= $k !== 'spawned' ? ' · ' : '' ?>
  <?php endforeach; ?>
</p>

<?php if ($items === []): ?>
  <p class="muted"><?= te('audio_llm.list.empty') ?></p>
<?php else: ?>
<table class="data-table">
  <thead>
    <tr>
      <th><?= te('audio_llm.col.id') ?></th>
      <th><?= te('audio_llm.col.title') ?></th>
      <th><?= te('audio_llm.col.mode') ?></th>
      <th><?= te('audio_llm.col.extra') ?></th>
      <th><?= te('audio_llm.col.progress') ?></th>
      <th><?= te('audio_llm.col.meaningful') ?></th>
      <th><?= te('audio_llm.col.status') ?></th>
      <th><?= te('audio_llm.col.created') ?></th>
      <th></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($items as $row):
      $sid = (int)$row['ID'];
      $isCyclic = (int)$row['IS_CYCLIC'] === 1;
      $parent = (int)$row['ID_CYCLIC_PARENT'];
      if ($isCyclic) {
          $mode = t('audio_llm.mode.cyclic');
          $extra = t('audio_llm.cyclic.good', ['id' => (string)(int)$row['TRIGGER_GOOD_ID']])
              . ' +' . (int)$row['AMOUNT_POSITIVE'] . ' / ' . (int)$row['AMOUNT_NEGATIVE']
              . ' (' . (!empty($row['CYCLIC_ENABLED']) ? t('audio_llm.cyclic.on') : t('audio_llm.cyclic.off')) . ')';
      } elseif ($parent > 0) {
          $mode = t('audio_llm.mode.record_ids');
          $extra = t('audio_llm.spawned.parent', ['id' => (string)$parent])
              . ', ' . t('audio_llm.spawned.proc', ['id' => (string)(int)$row['ID_PROCEDURE']]);
      } else {
          $mode = ((string)$row['SELECTION_MODE'] === 'admin_range')
              ? t('audio_llm.mode.admin_range') : t('audio_llm.mode.record_ids');
          $extra = '—';
      }
      $st = (int)$row['STATUS'];
      ?>
    <tr>
      <td><?= $sid ?></td>
      <td><a href="/audio/llm-studies/<?= $sid ?>"><?= e((string)$row['TITLE']) ?></a></td>
      <td><?= e($mode) ?></td>
      <td><?= e($extra) ?></td>
      <td><?= (int)$row['DONE_RECORDS'] ?>/<?= (int)$row['TOTAL_RECORDS'] ?></td>
      <td><?= (int)$row['MEANINGFUL_COUNT'] ?></td>
      <td><?= te('audio_llm.status.' . $st) ?></td>
      <td><?= e(date('d.m.Y H:i', (int)$row['DT_CREATE'])) ?></td>
      <td>
        <?php if (!$isCyclic && $parent === 0): ?>
          <a href="/audio/llm-studies/new?cfrom=<?= $sid ?>" title="<?= te('audio_llm.action.copy') ?>">⎘</a>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<p class="muted"><?= (int)$total ?> · page <?= (int)$page ?></p>
<?php endif; ?>
