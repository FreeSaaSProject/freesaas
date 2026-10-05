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
 */
?>
<?php if ($flash !== ''): ?>
  <p class="flash-ok"><?= e($flash) ?></p>
<?php endif; ?>

<div class="ar-sum-toolbar">
  <?php if ($canEdit): ?>
    <a class="btn" href="/audio/summaries/new"><?= te('audio_reports.summaries.new') ?></a>
  <?php endif; ?>
</div>

<?php if ($profiles === []): ?>
  <p class="muted"><?= te('audio_reports.summaries.empty') ?></p>
<?php else: ?>
  <table class="ar-sum-table">
    <thead>
      <tr>
        <th><?= te('audio_reports.summaries.col_name') ?></th>
        <th><?= te('audio_reports.summaries.col_position') ?></th>
        <th><?= te('audio_reports.summaries.col_default') ?></th>
        <th><?= te('audio_reports.summaries.col_enabled') ?></th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($profiles as $p): ?>
        <tr>
          <td><a href="/audio/summaries/<?= (int)$p['ID'] ?>"><?= e((string)$p['NAME']) ?></a></td>
          <td><?= (int)$p['ID_POSITION'] ?></td>
          <td><?= !empty($p['IS_DEFAULT']) ? te('audio_reports.summaries.yes') : te('audio_reports.summaries.no') ?></td>
          <td><?= !empty($p['ENABLED']) ? te('audio_reports.summaries.yes') : te('audio_reports.summaries.no') ?></td>
          <td>
            <a href="/audio/summaries?run=1&amp;profile=<?= (int)$p['ID'] ?>&amp;year=<?= (int)$year ?>&amp;month=<?= (int)$month ?>"><?= te('audio_reports.summaries.run') ?></a>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<form class="ar-sum-toolbar" method="get" action="/audio/summaries">
  <input type="hidden" name="run" value="1">
  <label><?= te('audio_reports.summaries.col_name') ?>
    <select name="profile">
      <?php foreach ($profiles as $p): ?>
        <option value="<?= (int)$p['ID'] ?>"<?= ($runProfile && (int)$runProfile['ID'] === (int)$p['ID']) ? ' selected' : '' ?>><?= e((string)$p['NAME']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('audio_reports.calendar.year') ?>
    <input type="number" name="year" value="<?= (int)$year ?>" min="2000" max="2100">
  </label>
  <label><?= te('audio_reports.calendar.month') ?>
    <select name="month">
      <?php for ($i = 1; $i <= 12; $i++): ?>
        <option value="<?= $i ?>"<?= $month === $i ? ' selected' : '' ?>><?= e(t('audio_reports.calendar.month.' . $i)) ?></option>
      <?php endfor; ?>
    </select>
  </label>
  <label><?= te('audio_reports.summaries.period') ?>
    <input type="date" name="date_from" value="<?= e($dateFrom) ?>">
    <input type="date" name="date_to" value="<?= e($dateTo) ?>">
  </label>
  <button type="submit"><?= te('audio_reports.summaries.run') ?></button>
</form>

<?php if (is_array($report)): ?>
  <?php
    $totals = $report['totals'] ?? [];
    $cards = $report['cards'] ?? [];
    $cardMap = [
        'volume' => ['label' => t('audio_reports.summaries.card.volume'), 'val' => $totals['audio_hours'] ?? 0],
        'score' => ['label' => t('audio_reports.summaries.card.score'), 'val' => $totals['avg_score'] ?? '—'],
        'criteria' => ['label' => t('audio_reports.summaries.card.criteria'), 'val' => $totals['criteria_count'] ?? 0],
        'scripts' => ['label' => t('audio_reports.summaries.card.scripts'), 'val' => $totals['scripts_count'] ?? 0],
        'script_score' => ['label' => t('audio_reports.summaries.card.script_score'), 'val' => $totals['script_score_total'] ?? 0],
        'tracking' => ['label' => t('audio_reports.summaries.card.tracking'), 'val' => $totals['tracking_count'] ?? 0],
        'leaders' => ['label' => t('audio_reports.summaries.card.leaders'), 'val' => count($report['rows'] ?? [])],
    ];
  ?>
  <h2><?= e((string)($report['profile_name'] ?? '')) ?> — <?= e((string)($report['period']['label'] ?? '')) ?></h2>
  <div class="ar-sum-cards">
    <?php foreach ($cards as $cardId):
        if (!isset($cardMap[$cardId])) {
            continue;
        }
        $c = $cardMap[$cardId];
        $custom = trim((string)(($report['labels'][$cardId] ?? '')));
        ?>
      <div class="ar-sum-card">
        <div class="ar-sum-card-val"><?= e((string)$c['val']) ?></div>
        <div class="ar-sum-card-label"><?= e($custom !== '' ? $custom : $c['label']) ?></div>
      </div>
    <?php endforeach; ?>
    <?php foreach (($report['external'] ?? []) as $ext): ?>
      <div class="ar-sum-card">
        <div class="ar-sum-card-val"><?= e((string)($ext['total'] ?? '')) ?></div>
        <div class="ar-sum-card-label"><?= e((string)($ext['title'] ?? $ext['key'] ?? '')) ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <table class="ar-sum-table">
    <thead>
      <tr>
        <th><?= te('audio_reports.summaries.col_employee') ?></th>
        <th><?= te('audio_reports.summaries.col_hours') ?></th>
        <th><?= te('audio_reports.summaries.col_score') ?></th>
        <th><?= te('audio_reports.summaries.col_days') ?></th>
        <th><?= te('audio_reports.summaries.col_pass') ?></th>
        <th><?= te('audio_reports.summaries.col_fail') ?></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach (($report['rows'] ?? []) as $row): ?>
        <tr>
          <td><?= e((string)($row['employee']['name'] ?? '')) ?></td>
          <td><?= e((string)$row['hours']) ?></td>
          <td><?= $row['avg_score'] !== null ? e((string)$row['avg_score']) : '—' ?></td>
          <td><?= (int)$row['days'] ?></td>
          <td><?= (int)$row['days_pass'] ?></td>
          <td><?= (int)$row['days_fail'] ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
