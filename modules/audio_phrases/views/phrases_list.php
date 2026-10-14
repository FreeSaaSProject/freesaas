<?php
declare(strict_types=1);
/**
 * @var list<array<string,mixed>> $items
 * @var int $total
 * @var int $page
 * @var int $perPage
 * @var bool $showAll
 * @var array $filters
 * @var string $status
 * @var int $dict
 * @var int $admin
 * @var int $minDays
 * @var int $minAdmins
 * @var string $q
 * @var bool $canEdit
 * @var array<int,string> $dictOptions
 * @var array<int,string> $staffOptions
 * @var bool $tablesReady
 * @var string $path
 * @var string $listId
 * @var array|null $bulkFlash
 * @var array{total?:int,items?:list<array{id:int,lemmas:string,example?:string}>}|null $pending
 */
$dictOptions = is_array($dictOptions ?? null) ? $dictOptions : [];
$staffOptions = is_array($staffOptions ?? null) ? $staffOptions : [];
$pending = is_array($pending ?? null) ? $pending : ['total' => 0, 'items' => []];
$listId = (string)($listId ?? 'audio_phrases');
$contextAdmin = (int)($admin ?? 0);
$showAll = !empty($showAll);
$pager = ListQuery::pager((int)$total, (int)$page, (int)$perPage, $path, $filters, $showAll);
$pendingHref = ListQuery::url($path, array_merge($filters, ['status' => 'new']), ['page' => null]);
$statusLabels = [
    'new' => t('audio_phrases.status.new'),
    'junk' => t('audio_phrases.status.junk'),
    'skip' => t('audio_phrases.status.skip'),
    'key' => t('audio_phrases.status.key'),
];
$colSpan = ($canEdit ? 7 : 6) + BulkActions::colSpanExtra($listId);
$actionUrl = ListQuery::url($path, $filters, []);

$dictSelectHtml = static function () use ($dictOptions): string {
    $html = '<select class="ap-dict-select" aria-label="' . e(t('audio_phrases.filter.dict')) . '">';
    $html .= '<option value="0">' . te('audio_phrases.action.pick_dict') . '</option>';
    foreach ($dictOptions as $did => $dname) {
        $html .= '<option value="' . (int)$did . '">' . e((string)$dname) . '</option>';
    }
    $html .= '</select>';
    return $html;
};
?>
<section class="page-head">
  <div class="page-head-row">
    <div></div>
    <div class="page-actions">
      <a class="btn-secondary" href="/audio/phrases/day"><?= te('audio_phrases.day.link') ?></a>
    </div>
  </div>
</section>

<?php if (empty($tablesReady)): ?>
  <p class="flash error"><?= te('audio_phrases.empty') ?> (migrate)</p>
<?php endif; ?>
<?php if (!empty($bulkFlash)): ?><?= BulkActions::flashHtml($bulkFlash) ?><?php endif; ?>

<form class="filters" method="get" action="<?= e($path) ?>" data-auto-filter>
  <?= ListQuery::sortHiddenInputs(
      (string)($filters['sort'] ?? 'count'),
      (string)($filters['dir'] ?? 'desc'),
      (int)$perPage
  ) ?>
  <label><?= te('audio_phrases.filter.status') ?>
    <select name="status">
      <option value="all"<?= $status === 'all' ? ' selected' : '' ?>><?= te('audio_phrases.filter.all') ?></option>
      <?php foreach ($statusLabels as $sk => $sl): ?>
        <option value="<?= e($sk) ?>"<?= $status === $sk ? ' selected' : '' ?>><?= e($sl) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('audio_phrases.filter.admin') ?>
    <select name="admin">
      <option value="0"><?= te('audio_phrases.filter.all') ?></option>
      <?php foreach ($staffOptions as $sid => $slabel): ?>
        <option value="<?= (int)$sid ?>"<?= $admin === (int)$sid ? ' selected' : '' ?>><?= e($slabel) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('audio_phrases.filter.dict') ?>
    <select name="dict">
      <option value="0"><?= te('audio_phrases.filter.all') ?></option>
      <?php foreach ($dictOptions as $did => $dname): ?>
        <option value="<?= (int)$did ?>"<?= $dict === (int)$did ? ' selected' : '' ?>><?= e($dname) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label><?= te('audio_phrases.filter.min_days') ?>
    <input type="number" min="0" name="min_days" value="<?= (int)$minDays ?>">
  </label>
  <label><?= te('audio_phrases.filter.min_admins') ?>
    <input type="number" min="0" name="min_admins" value="<?= (int)$minAdmins ?>">
  </label>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('audio_phrases.col.lemmas') ?>" data-filter-debounce>
</form>

<?php require base_path('modules/audio_phrases/views/_pending_bar.php'); ?>
<?= $pager ?>
<?= BulkActions::beginForm($listId, $actionUrl) ?>
<?= BulkActions::bar($listId) ?>
<div class="table-wrap"
     id="phrases-list"
     data-can-edit="<?= $canEdit ? '1' : '0' ?>"
     data-admin-id="<?= $contextAdmin ?>"
     data-dicts="<?= e(json_encode($dictOptions, JSON_UNESCAPED_UNICODE) ?: '{}') ?>"
     data-i18n="<?= e(json_encode([
         'createKey' => t('audio_phrases.create_key'),
         'pickDict' => t('audio_phrases.action.pick_dict'),
         'markNew' => t('audio_phrases.action.new'),
         'error' => t('audio_phrases.err.forbidden'),
         'wrongPos' => t('audio_phrases.key.wrong_position'),
         'confirmOverlaps' => t('audio_phrases.confirm.overlaps'),
         'createAnyway' => t('audio_phrases.action.create_anyway'),
         'cancel' => t('audio_phrases.action.cancel'),
         'reload' => t('audio_phrases.action.reload'),
         'submitKey' => t('audio_phrases.action.submit_key'),
         'editKeyName' => t('audio_phrases.edit_key_name'),
         'textRequired' => t('audio_phrases.err.text_required'),
         'status' => $statusLabels,
     ], JSON_UNESCAPED_UNICODE) ?: '{}') ?>">
<table class="data">
<thead><tr>
  <?= BulkActions::th($listId) ?>
  <th><?= te('audio_phrases.col.lemmas') ?></th>
  <th><?= te('audio_phrases.col.example') ?></th>
  <th><?= te('audio_phrases.col.count') ?></th>
  <th><?= te('audio_phrases.col.days') ?></th>
  <th><?= te('audio_phrases.col.admins') ?></th>
  <th><?= te('audio_phrases.col.status') ?></th>
  <?php if ($canEdit): ?><th><?= te('audio_phrases.col.actions') ?></th><?php endif; ?>
</tr></thead>
<tbody>
<?php foreach ($items as $row):
    $keyId = (int)($row['id_key'] ?? 0);
    $keyName = trim((string)($row['key_name'] ?? ''));
    $dictName = trim((string)($row['dict_name'] ?? ''));
    $keyDictId = (int)($row['id_key_dictonary'] ?? 0);
    if ($keyDictId < 1) {
        $keyDictId = (int)($row['id_dictonary'] ?? 0);
    }
    $hasKey = $keyId > 0;
    $keyDictAllowed = true;
    if ($contextAdmin > 0 && $hasKey) {
        $keyDictAllowed = $keyDictId < 1 || isset($dictOptions[$keyDictId]);
    }
    $rowStatus = (string)($row['status'] ?? 'new');
    // Unclassified, or key from another position while viewing a staffer.
    $needActions = ($rowStatus === 'new' && !$hasKey)
        || ($contextAdmin > 0 && $hasKey && !$keyDictAllowed && $rowStatus !== 'junk'
            && $rowStatus !== 'skip');
    $rowClass = $rowStatus === 'skip' ? ' ap-row-dim' : '';
    ?>
<tr id="phrase-<?= (int)$row['id'] ?>" class="<?= trim($rowClass) ?>" data-id="<?= (int)$row['id'] ?>" data-status="<?= e($rowStatus) ?>" data-key-id="<?= $keyId ?>"
    data-example="<?= e((string)$row['example']) ?>" data-lemmas="<?= e((string)$row['lemmas']) ?>">
  <?= BulkActions::td($listId, (int)$row['id']) ?>
  <td class="ap-lemmas">
    <div class="ap-lemmas-main"><?= e((string)$row['lemmas']) ?></div>
    <div class="ap-key-meta"<?= $hasKey ? '' : ' hidden' ?>>
      <?php if ($hasKey): ?>
        <a class="ap-key-link" href="<?= e(NavContext::withReturn('/audio/keys/' . $keyId . '/edit')) ?>"><?= e($keyName !== '' ? $keyName : ('#' . $keyId)) ?></a>
        <?php if ($dictName !== ''): ?>
          <span class="ap-dict-name muted"><?= e($dictName) ?></span>
        <?php endif; ?>
        <?php if ($contextAdmin > 0 && !$keyDictAllowed): ?>
          <span class="ap-wrong-pos"><?= te('audio_phrases.key.wrong_position') ?></span>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </td>
  <td class="muted"><?= e((string)($row['example'] ?? '')) ?></td>
  <td><?= (int)($row['total_count'] ?? 0) ?></td>
  <td><?= (int)($row['days_seen'] ?? 0) ?></td>
  <td><?= (int)($row['admins_seen'] ?? 0) ?></td>
  <td class="ap-status"><?= e($statusLabels[$rowStatus] ?? $rowStatus) ?></td>
  <?php if ($canEdit): ?>
  <td class="ap-actions">
    <?php if ($rowStatus === 'skip'): ?>
    <div class="ap-action-row">
      <button type="button" class="ap-btn ap-btn-restore" data-status="new"><?= te('audio_phrases.action.new') ?></button>
    </div>
    <?php elseif ($needActions): ?>
    <div class="ap-action-row">
      <button type="button" class="ap-btn ap-btn-junk" data-status="junk"><?= te('audio_phrases.action.junk') ?></button>
      <button type="button" class="ap-btn ap-btn-skip" data-status="skip"><?= te('audio_phrases.action.skip') ?></button>
    </div>
    <div class="ap-action-row ap-create-row">
      <?= $dictSelectHtml() ?>
      <button type="button" class="ap-btn ap-btn-create" data-create-key="1" disabled
              title="<?= te('audio_phrases.create_key') ?>" aria-label="<?= te('audio_phrases.create_key') ?>">+</button>
    </div>
    <?php endif; ?>
  </td>
  <?php endif; ?>
</tr>
<?php endforeach; ?>
<?php if (!$items): ?>
<tr><td colspan="<?= (int)$colSpan ?>" class="muted"><?= te('audio_phrases.empty') ?></td></tr>
<?php endif; ?>
</tbody></table>
</div>
<?= BulkActions::endForm($listId) ?>
<?= $pager ?>
<?php require base_path('modules/audio_phrases/views/_pending_bar.php'); ?>
