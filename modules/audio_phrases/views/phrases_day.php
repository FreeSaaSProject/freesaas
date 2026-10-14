<?php
declare(strict_types=1);
/**
 * @var string $date
 * @var int $adminId
 * @var array<int,string> $staffOptions
 * @var list<array<string,mixed>> $items
 * @var bool $canEdit
 * @var string $flash
 * @var array<int,string> $dictOptions
 * @var array{total?:int,items?:list<array{id:int,lemmas:string,example?:string}>}|null $pending
 */
$staffOptions = is_array($staffOptions ?? null) ? $staffOptions : [];
$dictOptions = is_array($dictOptions ?? null) ? $dictOptions : [];
$pending = is_array($pending ?? null) ? $pending : ['total' => 0, 'items' => []];
$pendingHref = '/audio/phrases?status=new' . ($adminId > 0 ? '&admin=' . (int)$adminId : '');
$statusLabels = [
    'new' => t('audio_phrases.status.new'),
    'junk' => t('audio_phrases.status.junk'),
    'skip' => t('audio_phrases.status.skip'),
    'key' => t('audio_phrases.status.key'),
];
$colSpan = $canEdit ? 5 : 4;

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
      <a class="btn-secondary" href="/audio/phrases"><?= te('audio_phrases.menu.list') ?></a>
    </div>
  </div>
</section>

<?php if ($flash !== ''): ?><p class="flash ok"><?= e($flash) ?></p><?php endif; ?>

<form class="filters" method="get" action="/audio/phrases/day">
  <label><?= te('audio_phrases.day_title') ?>
    <input type="date" name="date" value="<?= e($date) ?>">
  </label>
  <label><?= te('audio_phrases.filter.admin') ?>
    <select name="admin">
      <option value="0"><?= te('audio_phrases.filter.all') ?></option>
      <?php foreach ($staffOptions as $sid => $slabel): ?>
        <option value="<?= (int)$sid ?>"<?= $adminId === (int)$sid ? ' selected' : '' ?>><?= e($slabel) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <button type="submit" class="btn-secondary"><?= te('audio_phrases.day.submit') ?></button>
</form>

<?php if ($canEdit): ?>
<form method="post" class="form-actions">
  <?= Csrf::field() ?>
  <input type="hidden" name="rebuild" value="1">
  <button type="submit" class="btn-primary"><?= te('audio_phrases.action.rebuild') ?></button>
</form>
<?php endif; ?>

<?php require base_path('modules/audio_phrases/views/_pending_bar.php'); ?>

<div class="table-wrap ap-day"
     id="phrases-list"
     data-can-edit="<?= $canEdit ? '1' : '0' ?>"
     data-admin-id="<?= (int)$adminId ?>"
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
  <th><?= te('audio_phrases.col.lemmas') ?></th>
  <th><?= te('audio_phrases.col.samples') ?></th>
  <th><?= te('audio_phrases.col.count') ?></th>
  <th><?= te('audio_phrases.col.status') ?></th>
  <?php if ($canEdit): ?><th><?= te('audio_phrases.col.actions') ?></th><?php endif; ?>
</tr></thead>
<tbody>
<?php foreach ($items as $row):
    $samples = is_array($row['samples'] ?? null) ? $row['samples'] : [];
    $keyId = (int)($row['id_key'] ?? 0);
    $keyName = trim((string)($row['key_name'] ?? ''));
    $dictName = trim((string)($row['dict_name'] ?? ''));
    $keyDictId = (int)($row['id_key_dictonary'] ?? 0);
    if ($keyDictId < 1) {
        $keyDictId = (int)($row['id_dictonary'] ?? 0);
    }
    $hasKey = $keyId > 0;
    $keyDictAllowed = true;
    if ((int)$adminId > 0 && $hasKey) {
        $keyDictAllowed = $keyDictId < 1 || isset($dictOptions[$keyDictId]);
    }
    $rowStatus = (string)($row['status'] ?? 'new');
    $needActions = ($rowStatus === 'new' && !$hasKey)
        || ((int)$adminId > 0 && $hasKey && !$keyDictAllowed && $rowStatus !== 'junk'
            && $rowStatus !== 'skip');
    $phraseId = (int)($row['id'] ?? 0);
    $rowClass = $rowStatus === 'skip' ? ' ap-row-dim' : '';
    ?>
<tr id="phrase-<?= $phraseId ?>" class="<?= trim($rowClass) ?>" data-id="<?= $phraseId ?>" data-status="<?= e($rowStatus) ?>" data-key-id="<?= $keyId ?>"
    data-example="<?= e((string)$row['example']) ?>" data-lemmas="<?= e((string)$row['lemmas']) ?>">
  <td class="ap-lemmas">
    <div class="ap-lemmas-main">
      <a href="/audio/phrases?q=<?= e(urlencode((string)$row['lemmas'])) ?>"><?= e((string)$row['lemmas']) ?></a>
    </div>
    <div class="ap-key-meta"<?= $hasKey ? '' : ' hidden' ?>>
      <?php if ($hasKey): ?>
        <a class="ap-key-link" href="<?= e(NavContext::withReturn('/audio/keys/' . $keyId . '/edit')) ?>"><?= e($keyName !== '' ? $keyName : ('#' . $keyId)) ?></a>
        <?php if ($dictName !== ''): ?>
          <span class="ap-dict-name muted"><?= e($dictName) ?></span>
        <?php endif; ?>
        <?php if ((int)$adminId > 0 && !$keyDictAllowed): ?>
          <span class="ap-wrong-pos"><?= te('audio_phrases.key.wrong_position') ?></span>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </td>
  <td class="ap-samples">
    <?php if ($samples === []): ?>
      <span class="muted"><?= e((string)$row['example']) ?></span>
    <?php else: ?>
      <ul class="ap-sample-list">
        <?php foreach ($samples as $s): ?>
          <li>
            <?php if ((int)($s['record_id'] ?? 0) > 0): ?>
              <a class="ap-sample-rec" href="<?= e(NavContext::withReturn('/audio/' . (int)$s['record_id'])) ?>"
                 title="<?= te('audio_phrases.sample.record', ['id' => (string)(int)$s['record_id']]) ?>">#<?= (int)$s['record_id'] ?></a>
            <?php endif; ?>
            <span class="ap-sample-line">
              <?= e((string)($s['before'] ?? '')) ?><mark class="ap-sample-hit"><?= e((string)($s['hit'] ?? '')) ?></mark><?= e((string)($s['after'] ?? '')) ?>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </td>
  <td><?= (int)$row['day_count'] ?></td>
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

<?php require base_path('modules/audio_phrases/views/_pending_bar.php'); ?>
