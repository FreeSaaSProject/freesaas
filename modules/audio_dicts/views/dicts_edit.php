<?php
declare(strict_types=1);
/** @var ?array $row */
/** @var bool $isNew */
/** @var string $error */
/** @var string $path */
/** @var int $id */
/** @var int $keyCount */
/** @var string $listId */

$row = is_array($row ?? null) ? $row : [];
$isNew = !empty($isNew);
$id = (int)($id ?? 0);
$keyCount = (int)($keyCount ?? 0);

$colorFg = trim((string)($row['color'] ?? ''));
$colorBg = trim((string)($row['color_background'] ?? ''));
$colorFgHex = (isset($colorFg[0]) && $colorFg[0] === '#' && strlen($colorFg) >= 7)
    ? substr($colorFg, 0, 7) : '#0f172a';
$colorBgHex = (isset($colorBg[0]) && $colorBg[0] === '#' && strlen($colorBg) >= 7)
    ? substr($colorBg, 0, 7) : '#e2e8f0';
$previewName = trim((string)($row['name'] ?? ''));
if ($previewName === '') {
    $previewName = t('audio_dicts.dicts.preview_sample');
}
$keysHref = $id > 0 ? ('/audio/keys?' . http_build_query(['dict' => $id])) : '';
?>
<?php if ($error !== ''): ?><p class="flash error"><?= e($error) ?></p><?php endif; ?>

<?php if (!$isNew && $keysHref !== ''): ?>
  <section class="page-head">
    <div class="page-head-row">
      <div></div>
      <div class="page-actions">
        <a class="btn-secondary" href="<?= e($keysHref) ?>">
          <?= te('audio_dicts.dicts.open_keys_n', ['n' => (string)$keyCount]) ?>
        </a>
      </div>
    </div>
  </section>
<?php endif; ?>

<form method="post" class="form audio-dict-form">
  <h2 class="form-section-title"><?= te('audio_dicts.edit.sec_main') ?></h2>
  <p class="muted audio-dict-hint"><?= te('audio_dicts.dicts.hint_main') ?></p>

  <label>
    <?= te('audio_dicts.col.name') ?>
    <input name="name" required maxlength="50" value="<?= e((string)($row['name'] ?? '')) ?>"
           placeholder="<?= te('audio_dicts.dicts.name_placeholder') ?>">
    <span class="muted small"><?= te('audio_dicts.dicts.name_hint') ?></span>
  </label>

  <?php
    $descrVal = (string)($row['descr'] ?? '');
    $suggest = is_array($_SESSION['audio_dicts_descr_suggest'] ?? null)
        ? $_SESSION['audio_dicts_descr_suggest'] : null;
    if (is_array($suggest) && (int)($suggest['id'] ?? 0) === $id && !$isNew) {
        $descrVal = (string)($suggest['descr'] ?? $descrVal);
        unset($_SESSION['audio_dicts_descr_suggest']);
    }
    $descrMax = class_exists('RecordsDictsRepository', false) ? RecordsDictsRepository::DESCR_MAX : 200;
    $canSuggest = !$isNew && $id > 0
        && class_exists('DictDescrSuggest', false) && DictDescrSuggest::isReady();
  ?>
  <label>
    <?= te('audio_dicts.col.descr') ?>
    <textarea name="descr" id="dict-descr" rows="3" maxlength="<?= (int)$descrMax ?>"
              placeholder="<?= te('audio_dicts.dicts.descr_placeholder') ?>"><?= e($descrVal) ?></textarea>
    <span class="muted small"><?= te('audio_dicts.dicts.descr_hint', ['n' => (string)$descrMax]) ?></span>
  </label>
  <?php if ($canSuggest): ?>
    <div class="audio-dict-suggest">
      <?php /* Separate form id + form= on button: nested <form> is invalid HTML and was
               submitting the main save (wiping colors) instead of suggest-descr. */ ?>
      <button type="submit" class="btn-secondary" form="dict-suggest-form">
        <?= te('audio_dicts.dicts.suggest_btn') ?>
      </button>
      <span class="muted small"><?= te('audio_dicts.dicts.suggest_hint') ?></span>
    </div>
  <?php endif; ?>

  <label>
    <?= te('audio_dicts.col.tracking') ?>
    <input name="tracking_code" maxlength="64" value="<?= e((string)($row['tracking_code'] ?? '')) ?>"
           placeholder="<?= te('audio_dicts.dicts.tracking_placeholder') ?>">
    <span class="muted small"><?= te('audio_dicts.dicts.tracking_hint') ?></span>
  </label>

  <?php
    $positionOptions = is_array($positionOptions ?? null) ? $positionOptions : [];
    $selectedPos = [];
    if (isset($row['position_ids']) && is_array($row['position_ids'])) {
        $selectedPos = array_values(array_filter(array_map('intval', $row['position_ids'])));
    } elseif (class_exists('RecordsScriptsRepository', false)) {
        $selectedPos = RecordsScriptsRepository::parseIds((string)($row['id_position'] ?? ''));
    }
  ?>
  <fieldset class="audio-dict-positions">
    <legend><?= te('audio_dicts.dicts.field.positions') ?></legend>
    <p class="muted small"><?= te('audio_dicts.dicts.field.positions_hint') ?></p>
    <?php foreach ($positionOptions as $sid => $sname):
      $checked = in_array((int)$sid, $selectedPos, true);
    ?>
      <label class="logo-remove">
        <input type="checkbox" name="positions[]" value="<?= (int)$sid ?>"<?= $checked ? ' checked' : '' ?>>
        <?= e((string)$sname) ?>
      </label>
    <?php endforeach; ?>
    <?php if ($positionOptions === []): ?>
      <p class="muted"><?= te('audio_dicts.dicts.no_positions') ?></p>
    <?php endif; ?>
  </fieldset>

  <h2 class="form-section-title"><?= te('audio_dicts.edit.sec_colors') ?></h2>
  <p class="muted audio-dict-hint"><?= te('audio_dicts.dicts.hint_colors') ?></p>

  <div class="audio-dict-preview" id="dict-color-preview"
       style="color: <?= e($colorFgHex) ?>; background: <?= e($colorBgHex) ?>;">
    <?= e($previewName) ?>
  </div>

  <label>
    <?= te('audio_dicts.col.color') ?>
    <div class="color-row">
      <input type="color" id="dict_color_picker" value="<?= e($colorFgHex) ?>"
             aria-label="<?= te('audio_dicts.dicts.color_picker') ?>">
      <input type="text" name="color" id="dict_color_text" maxlength="10"
             value="<?= e($colorFg) ?>" placeholder="#0f172a">
    </div>
    <span class="muted small"><?= te('audio_dicts.dicts.color_hint') ?></span>
  </label>

  <label>
    <?= te('audio_dicts.col.color_bg') ?>
    <div class="color-row">
      <input type="color" id="dict_bg_picker" value="<?= e($colorBgHex) ?>"
             aria-label="<?= te('audio_dicts.dicts.color_bg_picker') ?>">
      <input type="text" name="color_background" id="dict_bg_text" maxlength="10"
             value="<?= e($colorBg) ?>" placeholder="#e2e8f0">
    </div>
    <span class="muted small"><?= te('audio_dicts.dicts.color_bg_hint') ?></span>
  </label>

  <h2 class="form-section-title"><?= te('audio_dicts.edit.sec_flags') ?></h2>

  <label class="check">
    <input type="checkbox" name="disabled" value="1"<?= !empty($row['disabled']) ? ' checked' : '' ?>>
    <?= te('audio_dicts.col.disabled') ?>
    <span class="muted small"><?= te('audio_dicts.dicts.disabled_hint') ?></span>
  </label>
  <label class="check">
    <input type="checkbox" name="is_review_req" value="1"<?= !empty($row['is_review_req']) ? ' checked' : '' ?>>
    <?= te('audio_dicts.col.review_req') ?>
    <span class="muted small"><?= te('audio_dicts.dicts.review_hint') ?></span>
  </label>

  <div class="form-actions">
    <button type="submit" class="btn-primary"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="<?= e($path) ?>"><?= te('core.common.cancel') ?></a>
  </div>
</form>

<?php if ($canSuggest): ?>
  <form method="post" action="/audio/dicts/<?= $id ?>/suggest-descr" id="dict-suggest-form" class="audio-dict-suggest-form" hidden>
    <?= Csrf::field() ?>
  </form>
<?php endif; ?>

<?php if (!$isNew): ?>
<?= EntityDelete::form((string)($listId ?? 'audio_dicts'), $id > 0 ? $id : (int)($row['id'] ?? 0)) ?>
<?php endif; ?>

<script>
(function () {
  function bindColor(pickerId, textId, onChange) {
    var picker = document.getElementById(pickerId);
    var text = document.getElementById(textId);
    if (!picker || !text) return;
    function syncPreview() {
      if (typeof onChange === 'function') onChange();
    }
    picker.addEventListener('input', function () {
      text.value = picker.value;
      syncPreview();
    });
    text.addEventListener('input', function () {
      var v = text.value.trim();
      if (/^#[0-9a-fA-F]{6}$/.test(v)) picker.value = v;
      syncPreview();
    });
  }
  function refreshPreview() {
    var preview = document.getElementById('dict-color-preview');
    var fg = document.getElementById('dict_color_text');
    var bg = document.getElementById('dict_bg_text');
    var nameInput = document.querySelector('.audio-dict-form input[name="name"]');
    if (!preview) return;
    var fgV = (fg && fg.value.trim()) || '#0f172a';
    var bgV = (bg && bg.value.trim()) || '#e2e8f0';
    if (/^#[0-9a-fA-F]{6}$/i.test(fgV)) preview.style.color = fgV;
    if (/^#[0-9a-fA-F]{6}$/i.test(bgV)) preview.style.background = bgV;
    if (nameInput && nameInput.value.trim() !== '') {
      preview.textContent = nameInput.value.trim();
    }
  }
  bindColor('dict_color_picker', 'dict_color_text', refreshPreview);
  bindColor('dict_bg_picker', 'dict_bg_text', refreshPreview);
  var nameInput = document.querySelector('.audio-dict-form input[name="name"]');
  if (nameInput) nameInput.addEventListener('input', refreshPreview);
})();
</script>
