<?php
/**
 * @var array<string,mixed> $item
 * @var string $path
 * @var array $filters
 * @var string $transcriptHtml
 * @var bool $canEdit
 */
$item = is_array($item ?? null) ? $item : [];
$transcriptHtml = (string)($transcriptHtml ?? '');
$listHref = ListQuery::url($path, $filters, []);
$fmtDur = static function (int $sec): string {
    $sec = max(0, $sec);
    return sprintf('%02d:%02d:%02d', intdiv($sec, 3600), intdiv($sec % 3600, 60), $sec % 60);
};
?>
<section class="page-head">
  <div class="page-head-row">
    <div></div>
    <div class="page-actions">
      <a class="btn-secondary" href="<?= e($listHref) ?>"><?= te('audio_reports.collections.back_list') ?></a>
    </div>
  </div>
</section>

<article class="ar-col-detail">
  <header class="ar-col-detail-head">
    <h2><?= e((string)($item['script_name'] ?? '')) ?></h2>
    <p class="muted">
      #<?= (int)($item['id'] ?? 0) ?>
      · <?= e((string)($item['admin_name'] ?? '')) ?>
      · <?= e(date('d-m-Y H:i:s', (int)($item['dt_start'] ?? 0))) ?>
      – <?= e(date('H:i:s', (int)($item['dt_end'] ?? 0))) ?>
      (<?= e($fmtDur((int)($item['duration'] ?? 0))) ?>)
    </p>
    <p>
      <strong><?= te('audio_reports.collections.col.score') ?>: <?= (int)($item['score'] ?? 0) ?></strong>
      <?php if (!empty($item['finished'])): ?>
        <span class="ar-col-finish"><?= te('audio_reports.collections.finish') ?></span>
      <?php endif; ?>
      <?php if (!empty($item['has_intersect'])): ?>
        <span class="ar-col-warn"><?= te('audio_reports.collections.intersect_warn') ?></span>
      <?php endif; ?>
    </p>
  </header>

  <section class="ar-col-detail-sec">
    <h3><?= te('audio_reports.collections.col.transcript') ?></h3>
    <p class="muted small"><?= te('audio_reports.collections.transcript_hint') ?></p>
    <?php if ($transcriptHtml !== ''): ?>
      <div class="ar-col-transcript-full"><?= $transcriptHtml ?></div>
    <?php else: ?>
      <p class="muted"><?= te('audio_reports.collections.transcript_unavailable') ?></p>
    <?php endif; ?>
  </section>

  <section class="ar-col-detail-sec">
    <h3><?= te('audio_reports.collections.col.parts') ?></h3>
    <?php if (empty($item['parts'])): ?>
      <p class="muted">—</p>
    <?php else: ?>
      <ul class="ar-col-part-list">
        <?php foreach ($item['parts'] as $p): ?>
          <li class="<?= !empty($p['finish']) ? 'is-finish' : '' ?>"><?= e((string)$p['name']) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <section class="ar-col-detail-sec">
    <h3><?= te('audio_reports.collections.col.keys') ?></h3>
    <?php if (empty($item['keys'])): ?>
      <p class="muted">—</p>
    <?php else: ?>
      <div class="ar-col-keys">
        <?php foreach ($item['keys'] as $k): ?>
          <span class="ar-col-key"
                style="<?= $k['color'] !== '' ? 'color:' . e((string)$k['color']) . ';' : '' ?><?= $k['bg'] !== '' ? 'background-color:' . e((string)$k['bg']) . ';' : '' ?>">
            <?= e((string)$k['name']) ?>
            <?php if ($k['dict_name'] !== ''): ?>
              <span class="ar-col-key-dict"><?= e((string)$k['dict_name']) ?></span>
            <?php endif; ?>
          </span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</article>
