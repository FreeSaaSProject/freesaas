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
/** @var int $dict */
/** @var array<int,string> $dictOptions */
$showAll = !empty($showAll);
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, $showAll);
$colSpan = 6 + BulkActions::colSpanExtra($listId);
$replaceFilter = (string)($filters['replace'] ?? '');
$replaceOf = (int)($filters['replace_of'] ?? 0);
$replaceFilterOptions = [
    '' => t('audio_dicts.filter.replace_all'),
    'variants' => t('audio_dicts.filter.replace_variants'),
    'canonical' => t('audio_dicts.filter.replace_canonical'),
    'review' => t('audio_dicts.filter.replace_review'),
    'none' => t('audio_dicts.filter.replace_none'),
];
$actionUrl = ListQuery::url($path, $filters, []);
$dict = (int)($dict ?? 0);
$dictOptions = is_array($dictOptions ?? null) ? $dictOptions : [];
?>
<section class="page-head">
  <?php if ($canEdit): ?>
  <div class="page-head-row"><div></div><div class="page-actions"><a class="btn-primary" href="<?= e($path) ?>/new"><?= te('core.common.add') ?></a></div></div>
  <?php endif; ?>
</section>
<?php if (!empty($bulkFlash)): ?><?= BulkActions::flashHtml($bulkFlash) ?><?php endif; ?>
<?php $flash = is_array($flash ?? null) ? $flash : null; ?>
<?php if ($flash !== null && (string)($flash['message'] ?? '') !== ''): ?>
  <p class="flash flash-<?= e((string)($flash['type'] ?? 'ok')) ?>"><?= e((string)$flash['message']) ?></p>
<?php endif; ?>
<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs($sort, $dir, (int)$perPage) ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('audio_dicts.search_placeholder') ?>" data-filter-debounce>
  <label><?= te('audio_dicts.col.dict') ?>
    <select name="dict" data-filter-change>
      <option value="0"><?= te('audio_dicts.filter.all_dicts') ?></option>
      <?php foreach ($dictOptions as $did => $dname): ?>
        <option value="<?= (int)$did ?>"<?= $dict === (int)$did ? ' selected' : '' ?>><?= e($dname) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('audio_dicts.replace.col') ?>
    <select name="replace" data-filter-change>
      <?php foreach ($replaceFilterOptions as $rv => $rl): ?>
        <option value="<?= e((string)$rv) ?>"<?= $replaceFilter === (string)$rv ? ' selected' : '' ?>><?= e($rl) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <?php if ($replaceOf > 0): ?>
    <input type="hidden" name="replace_of" value="<?= $replaceOf ?>">
    <span class="muted small"><?= te('audio_dicts.replace.filter_of', ['id' => $replaceOf]) ?>
      <a href="<?= e($path) ?>"><?= te('audio_dicts.replace.filter_reset') ?></a></span>
  <?php endif; ?>
</form>
<?= $pager ?>
<?= BulkActions::beginForm($listId, $actionUrl) ?>
<?= BulkActions::bar($listId) ?>
<div class="table-wrap"><table class="data">
<thead><tr>
  <?= BulkActions::th($listId) ?>
  <?= ListQuery::sortTh('ID', 'id', $sort, $dir, $path, $filters) ?>
  <?= ListQuery::sortTh(t('audio_dicts.col.name'), 'name', $sort, $dir, $path, $filters) ?>
  <th><?= te('audio_dicts.col.dict') ?></th>
  <th><?= te('audio_dicts.replace.col') ?></th>
  <?= ListQuery::sortTh(t('audio_dicts.col.enabled'), 'disabled', $sort, $dir, $path, $filters) ?>
</tr></thead>
<tbody>
<?php foreach ($items as $c):
  $enabled = empty($c['disabled']);
?>
<tr<?= !$enabled ? ' class="is-disabled"' : '' ?>>
  <?= BulkActions::td($listId, (int)$c['id']) ?>
  <td class="muted"><?php if ($canEdit): ?><a href="<?= e($path) ?>/<?= (int)$c['id'] ?>/edit"><?= (int)$c['id'] ?></a><?php else: ?><?= (int)$c['id'] ?><?php endif; ?></td>
  <td><?= e((string)$c['name']) ?></td>
  <td><?= e((string)($c['dict_name'] ?: '—')) ?></td>
  <td class="small">
    <?php if ((int)($c['id_replace'] ?? 0) > 0 && (string)($c['replace_name'] ?? '') !== ''): ?>
      <span title="<?= e(t('audio_dicts.replace.state.' . ((string)($c['replace_state'] ?? '') ?: 'approved'))) ?>">→ <?= e((string)$c['replace_name']) ?></span>
    <?php endif; ?>
    <?php if ((int)($c['variant_count'] ?? 0) > 0): ?>
      <a href="<?= e($path) ?>?replace_of=<?= (int)$c['id'] ?>" class="muted">[<?= (int)$c['variant_count'] ?>]</a>
    <?php endif; ?>
    <?php if ((string)($c['replace_state'] ?? '') === 'suggested' && (string)($c['replace_suggest'] ?? '') !== ''): ?>
      <?php $sugId = (int)$c['id']; ?>
      <span class="audio-key-suggest-row">
        <?php if ($canEdit): ?>
        <button type="submit" form="key-replace-<?= $sugId ?>" name="action" value="replace_accept"
                class="audio-key-suggest-btn is-ok" title="<?= e(t('audio_dicts.replace.row_accept')) ?>"
                aria-label="<?= e(t('audio_dicts.replace.row_accept')) ?>">✅</button>
        <?php endif; ?>
        <?php if ($canEdit): ?>
        <button type="button" class="audio-key-suggest audio-key-suggest-open"
                data-key-id="<?= $sugId ?>" data-key-name="<?= e((string)$c['name']) ?>"
                title="<?= e(t('audio_dicts.replace.alt.open')) ?>">? <?= e((string)$c['replace_suggest']) ?></button>
        <?php else: ?>
        <span class="audio-key-suggest" title="<?= e(t('audio_dicts.replace.state.suggested')) ?>">? <?= e((string)$c['replace_suggest']) ?></span>
        <?php endif; ?>
        <?php if ($canEdit): ?>
        <button type="submit" form="key-replace-<?= $sugId ?>" name="action" value="replace_reject"
                class="audio-key-suggest-btn is-no" title="<?= e(t('audio_dicts.replace.row_reject')) ?>"
                aria-label="<?= e(t('audio_dicts.replace.row_reject')) ?>">❌</button>
        <?php endif; ?>
      </span>
    <?php elseif ((string)($c['replace_state'] ?? '') === 'pending'): ?>
      <span class="muted"><?= te('audio_dicts.replace.state.pending') ?></span>
    <?php endif; ?>
  </td>
  <td>
    <?php if ($canEdit): ?>
    <label class="switch scripts-enabled-switch" title="<?= $enabled ? te('audio_dicts.keys.enabled_on') : te('audio_dicts.keys.enabled_off') ?>">
      <input type="checkbox" form="key-toggle-<?= (int)$c['id'] ?>" name="enabled" value="1"<?= $enabled ? ' checked' : '' ?> onchange="document.getElementById('key-toggle-<?= (int)$c['id'] ?>').submit()">
      <span class="switch-slider" aria-hidden="true"></span>
      <span class="visually-hidden"><?= te('audio_dicts.col.enabled') ?></span>
    </label>
    <span class="scripts-enabled-label muted"><?= $enabled ? te('audio_dicts.keys.enabled_on') : te('audio_dicts.keys.enabled_off') ?></span>
    <?php else: ?>
      <span class="muted"><?= $enabled ? te('audio_dicts.keys.enabled_on') : te('audio_dicts.keys.enabled_off') ?></span>
    <?php endif; ?>
  </td>
</tr>
<?php endforeach; ?>
<?php if (!$items): ?><tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('audio_dicts.empty') ?></td></tr><?php endif; ?>
</tbody></table></div>
<?= BulkActions::endForm($listId) ?>
<?php if ($canEdit): ?>
  <?php foreach ($items as $c): ?>
  <form id="key-toggle-<?= (int)$c['id'] ?>" method="post" action="<?= e($actionUrl) ?>" class="visually-hidden" aria-hidden="true">
    <input type="hidden" name="action" value="toggle_disabled">
    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
  </form>
  <?php endforeach; ?>
  <?php foreach ($items as $c): ?>
  <?php if ((string)($c['replace_state'] ?? '') === 'suggested' && (string)($c['replace_suggest'] ?? '') !== ''): ?>
  <form id="key-replace-<?= (int)$c['id'] ?>" method="post" action="<?= e((string)($_SERVER['REQUEST_URI'] ?? $actionUrl)) ?>" class="visually-hidden" aria-hidden="true">
    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
  </form>
  <?php endif; ?>
  <?php endforeach; ?>
<?php endif; ?>
<?= $pager ?>
<?php if ($canEdit): ?>
<div id="audio-key-alt-boot" hidden
     data-url="/audio/keys/replace-options"
     data-action="<?= e((string)($_SERVER['REQUEST_URI'] ?? $actionUrl)) ?>"
     data-i18n="<?= e(json_encode([
         'title' => t('audio_dicts.replace.alt.title'),
         'search' => t('audio_dicts.replace.alt.search'),
         'groupUsed' => t('audio_dicts.replace.alt.group_used'),
         'groupOther' => t('audio_dicts.replace.alt.group_other'),
         'variants' => t('audio_dicts.replace.alt.variants'),
         'current' => t('audio_dicts.replace.alt.current'),
         'empty' => t('audio_dicts.replace.alt.empty'),
         'loading' => t('audio_dicts.replace.alt.loading'),
         'error' => t('audio_dicts.replace.alt.error'),
         'keep' => t('audio_dicts.replace.alt.keep'),
         'typed' => t('audio_dicts.replace.alt.typed'),
         'close' => t('audio_dicts.replace.alt.close'),
     ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '{}') ?>"></div>
<?php endif; ?>
<?php if (class_exists('ModuleManager', false) && ModuleManager::isEnabled('llm') && $canEdit): ?>
<?= View::capture('_longjob_panel', [
  'longjobId' => 'audio-keys-replace',
  'longjobTitle' => t('audio_dicts.replace.bulk.llm'),
  'longjobDualBars' => false,
  'longjobShowBytes' => false,
], 'core') ?>
<div id="audio-keys-replace-boot" hidden
     data-audio-keys-replace
     data-csrf="<?= e(class_exists('Csrf', false) ? Csrf::token() : '') ?>"
     data-i18n="<?= e(json_encode([
         'title' => t('audio_dicts.replace.bulk.llm'),
         'starting' => t('core.longjob.starting'),
         'badJson' => t('core.longjob.bad_json'),
         'doneTitle' => t('audio_dicts.bulk.suggest_done_title'),
         'errorTitle' => t('core.longjob.error_title'),
         'cancelledTitle' => t('core.longjob.cancelled_title'),
         'ok' => t('audio_dicts.bulk.suggest_close'),
         'abort' => t('audio_dicts.bulk.suggest_abort'),
         'confirm' => t('audio_dicts.replace.bulk.confirm_llm'),
         'empty' => t('audio_dicts.replace.bulk.empty'),
         'busy' => t('audio_dicts.bulk.suggest_busy'),
     ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '{}') ?>"></div>
<?php endif; ?>
