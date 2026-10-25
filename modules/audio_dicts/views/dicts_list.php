<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var string $q */
/** @var bool $canEdit */
/** @var string $sort */
/** @var string $dir */
/** @var int $page */
/** @var int $perPage */
/** @var bool $showAll */
/** @var array $filters */
/** @var array|null $bulkFlash */
/** @var string $path */
/** @var string $listId */
/** @var array<int,string> $positionOptions */
$showAll = !empty($showAll);
$positionOptions = is_array($positionOptions ?? null) ? $positionOptions : [];
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, $showAll);
$colSpan = 8 + BulkActions::colSpanExtra($listId);
$actionUrl = ListQuery::url($path, $filters, []);
?>
<section class="page-head">
  <?php if ($canEdit): ?>
  <div class="page-head-row"><div></div><div class="page-actions"><a class="btn-primary" href="<?= e($path) ?>/new"><?= te('core.common.add') ?></a></div></div>
  <?php endif; ?>
</section>
<?php if (!empty($bulkFlash)): ?><?= BulkActions::flashHtml($bulkFlash) ?><?php endif; ?>
<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('audio_dicts.search_placeholder') ?>" data-filter-debounce>
</form>
<?= $pager ?>
<?= BulkActions::beginForm($listId, $actionUrl) ?>
<?= BulkActions::bar($listId) ?>
<div class="table-wrap"><table class="data">
<thead><tr>
  <?= BulkActions::th($listId) ?>
  <?= ListQuery::sortTh('ID', 'id', $sort, $dir, $path, $filters) ?>
  <?= ListQuery::sortTh(t('audio_dicts.col.name'), 'name', $sort, $dir, $path, $filters) ?>
  <th><?= te('audio_dicts.col.positions') ?></th>
  <th><?= te('audio_dicts.col.descr') ?></th>
  <?= ListQuery::sortTh(t('audio_dicts.col.keys'), 'keys', $sort, $dir, $path, $filters) ?>
  <th><?= te('audio_dicts.col.tracking') ?></th>
  <?= ListQuery::sortTh(t('audio_dicts.col.disabled'), 'disabled', $sort, $dir, $path, $filters) ?>
</tr></thead>
<tbody>
<?php foreach ($items as $c): ?>
  <?php
    $dictId = (int)$c['id'];
    $keyCount = (int)($c['key_count'] ?? 0);
    $keysHref = '/audio/keys?' . http_build_query(['dict' => $dictId]);
    $fg = trim((string)($c['color'] ?? ''));
    $bg = trim((string)($c['color_background'] ?? ''));
    $fgOk = isset($fg[0]) && $fg[0] === '#' && strlen($fg) >= 4;
    $bgOk = isset($bg[0]) && $bg[0] === '#' && strlen($bg) >= 4;
    $badgeStyle = '';
    if ($fgOk) {
        $badgeStyle .= 'color:' . $fg . ';';
    }
    if ($bgOk) {
        $badgeStyle .= 'background:' . $bg . ';';
    }
  ?>
<tr>
  <?= BulkActions::td($listId, $dictId) ?>
  <td class="muted"><?php if ($canEdit): ?><a href="<?= e($path) ?>/<?= $dictId ?>/edit"><?= $dictId ?></a><?php else: ?><?= $dictId ?><?php endif; ?></td>
  <td>
    <?php if ($canEdit): ?>
      <a href="<?= e($path) ?>/<?= $dictId ?>/edit" class="audio-dict-name-badge"<?= $badgeStyle !== '' ? ' style="' . e($badgeStyle) . '"' : '' ?>><?= e((string)$c['name']) ?></a>
    <?php else: ?>
      <span class="audio-dict-name-badge"<?= $badgeStyle !== '' ? ' style="' . e($badgeStyle) . '"' : '' ?>><?= e((string)$c['name']) ?></span>
    <?php endif; ?>
  </td>
  <td class="scripts-list-positions">
    <?php
      $posIds = class_exists('RecordsScriptsRepository', false)
          ? RecordsScriptsRepository::parseIds((string)($c['id_position'] ?? ''))
          : [];
      if ($posIds === []):
    ?>
      <span class="muted"><?= te('audio_dicts.dicts.positions_all') ?></span>
    <?php else: ?>
      <?php foreach ($posIds as $pid):
          $pname = $positionOptions[$pid] ?? ('#' . $pid);
      ?>
        <span class="scripts-pos-chip"><?= e((string)$pname) ?></span>
      <?php endforeach; ?>
    <?php endif; ?>
  </td>
  <td><?= e((string)($c['descr'] ?: '—')) ?></td>
  <td>
    <a href="<?= e($keysHref) ?>" title="<?= e(t('audio_dicts.dicts.open_keys')) ?>">
      <?= $keyCount ?>
    </a>
  </td>
  <td><code><?= e((string)($c['tracking_code'] ?: '—')) ?></code></td>
  <td><?= !empty($c['disabled']) ? te('core.common.on') : te('core.common.off') ?></td>
</tr>
<?php endforeach; ?>
<?php if (!$items): ?><tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('audio_dicts.empty') ?></td></tr><?php endif; ?>
</tbody></table></div>
<?= BulkActions::endForm($listId) ?>
<?= $pager ?>

<?php if (class_exists('ModuleManager', false) && ModuleManager::isEnabled('llm')): ?>
<div id="audio-dicts-suggest-overlay" class="db-job-overlay" hidden
     data-audio-dicts-suggest
     data-i18n="<?= e(json_encode([
         'title' => t('audio_dicts.bulk.suggest_title'),
         'running' => t('audio_dicts.bulk.suggest_running'),
         'doneTitle' => t('audio_dicts.bulk.suggest_done_title'),
         'busy' => t('audio_dicts.bulk.suggest_busy'),
         'empty' => t('audio_dicts.bulk.suggest_empty'),
         'itemOk' => t('audio_dicts.bulk.suggest_item_ok'),
         'itemSkip' => t('audio_dicts.bulk.suggest_item_skip'),
         'itemErr' => t('audio_dicts.bulk.suggest_item_err'),
         'summary' => t('audio_dicts.bulk.suggest_summary'),
         'aborted' => t('audio_dicts.bulk.suggest_aborted'),
         'http' => t('audio_dicts.bulk.suggest_http'),
         'confirm' => t('audio_dicts.bulk.suggest_descr_confirm'),
     ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>">
  <div class="db-job-panel" role="dialog" aria-modal="true" aria-labelledby="audio-dicts-suggest-title">
    <h3 id="audio-dicts-suggest-title" class="subhead"><?= te('audio_dicts.bulk.suggest_title') ?></h3>
    <p id="audio-dicts-suggest-msg" class="db-job-msg muted"></p>
    <div class="db-job-bars">
      <div class="db-job-bar-block">
        <div class="db-job-bar-label">
          <span id="audio-dicts-suggest-meta">0 / 0</span>
          <span id="audio-dicts-suggest-pct">0%</span>
        </div>
        <div class="db-job-bar" id="audio-dicts-suggest-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
          <div id="audio-dicts-suggest-fill" class="db-job-fill"></div>
        </div>
      </div>
    </div>
    <div class="db-job-log-wrap">
      <div class="db-job-log-label muted"><?= te('core.db.job.log_heading') ?></div>
      <div id="audio-dicts-suggest-log" class="db-job-log" aria-live="polite"></div>
    </div>
    <div class="db-job-actions actions">
      <button type="button" class="btn-secondary" id="audio-dicts-suggest-abort"><?= te('audio_dicts.bulk.suggest_abort') ?></button>
      <button type="button" class="btn-primary" id="audio-dicts-suggest-ok" hidden disabled><?= te('audio_dicts.bulk.suggest_close') ?></button>
    </div>
  </div>
</div>
<?php endif; ?>
