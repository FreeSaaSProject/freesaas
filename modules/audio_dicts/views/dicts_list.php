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
<?= View::capture('_longjob_panel', [
  'longjobId' => 'audio-dicts-suggest',
  'longjobTitle' => t('audio_dicts.bulk.suggest_title'),
  'longjobDualBars' => false,
  'longjobShowBytes' => false,
], 'core') ?>
<div id="audio-dicts-suggest-boot" hidden
     data-audio-dicts-suggest
     data-csrf="<?= e(class_exists('Csrf', false) ? Csrf::token() : '') ?>"
     data-i18n="<?= e(json_encode([
         'title' => t('audio_dicts.bulk.suggest_title'),
         'starting' => t('core.longjob.starting'),
         'badJson' => t('core.longjob.bad_json'),
         'doneTitle' => t('audio_dicts.bulk.suggest_done_title'),
         'errorTitle' => t('core.longjob.error_title'),
         'cancelledTitle' => t('core.longjob.cancelled_title'),
         'ok' => t('audio_dicts.bulk.suggest_close'),
         'abort' => t('audio_dicts.bulk.suggest_abort'),
         'confirm' => t('audio_dicts.bulk.suggest_descr_confirm'),
         'empty' => t('audio_dicts.bulk.suggest_empty'),
         'busy' => t('audio_dicts.bulk.suggest_busy'),
     ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '{}') ?>"></div>
<?php endif; ?>
