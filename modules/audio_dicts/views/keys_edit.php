<?php
declare(strict_types=1);
/** @var ?array $row */
/** @var bool $isNew */
/** @var string $error */
/** @var array{type?:string,message?:string}|null $flash */
/** @var string $path */
/** @var int $id */
/** @var string $listId */
/** @var array<int,string> $dictOptions */
/** @var list<array<string,mixed>> $overlaps */
/** @var bool $forceChecked */
/** @var bool $forceNeeded */

$row = is_array($row ?? null) ? $row : [];
$isNew = !empty($isNew);
$id = (int)($id ?? 0);
$dictOptions = is_array($dictOptions ?? null) ? $dictOptions : [];
$overlaps = is_array($overlaps ?? null) ? $overlaps : [];
$forceChecked = !empty($forceChecked);
$forceNeeded = !empty($forceNeeded);
$flash = is_array($flash ?? null) ? $flash : null;

$relLabels = [
    'exact' => t('audio_dicts.keys.overlap_exact'),
    'contains' => t('audio_dicts.keys.overlap_contains'),
    'contained' => t('audio_dicts.keys.overlap_contained'),
];
?>
<?php if ($flash !== null && ($flash['message'] ?? '') !== ''): ?>
  <p class="flash flash-<?= e((string)($flash['type'] ?? 'ok')) ?>"><?= e((string)$flash['message']) ?></p>
<?php endif; ?>
<?php if ($error !== ''): ?><p class="flash error"><?= e($error) ?></p><?php endif; ?>

<form method="post" class="form audio-key-form" id="audio-key-form"
      data-nav-no-ret
      data-overlap-url="/audio/keys/check-overlap"
      data-exclude="<?= $isNew ? 0 : $id ?>"
      data-i18n="<?= e(json_encode([
          'confirmOverlaps' => t('audio_dicts.keys.overlap_title'),
          'forceHint' => t('audio_dicts.keys.force_hint'),
          'checking' => t('audio_dicts.keys.overlap_checking'),
          'none' => t('audio_dicts.keys.overlap_none'),
          'softOnly' => t('audio_dicts.keys.overlap_soft_ok'),
      ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>">

  <h2 class="form-section-title"><?= te('audio_dicts.keys.sec_main') ?></h2>
  <p class="muted audio-dict-hint"><?= te('audio_dicts.keys.hint_main') ?></p>

  <label>
    <?= te('audio_dicts.col.name') ?>
    <input type="text" name="name" id="key-name" required maxlength="255"
           value="<?= e((string)($row['name'] ?? '')) ?>"
           placeholder="<?= te('audio_dicts.keys.name_placeholder') ?>"
           autocomplete="off">
    <span class="muted small"><?= te('audio_dicts.keys.name_hint') ?></span>
  </label>

  <div id="key-overlap-panel" class="audio-key-overlap"<?= $overlaps === [] ? ' hidden' : '' ?>
       aria-live="polite">
    <div class="audio-key-overlap-title"><?= te('audio_dicts.keys.overlap_title') ?></div>
    <ul class="audio-key-overlap-list" id="key-overlap-list">
      <?php foreach ($overlaps as $ov): ?>
        <?php
          $oid = (int)($ov['id'] ?? 0);
          $rel = (string)($ov['relation'] ?? '');
          $relLabel = (string)($ov['relation_label'] ?? ($relLabels[$rel] ?? $rel));
          $dictName = trim((string)($ov['dict_name'] ?? ''));
        ?>
        <li>
          <a href="/audio/keys/<?= $oid ?>/edit" target="_blank" rel="noopener">
            #<?= $oid ?> «<?= e((string)($ov['name'] ?? '')) ?>»
          </a>
          <?php if ($dictName !== ''): ?>
            <span class="muted">[<?= e($dictName) ?>]</span>
          <?php endif; ?>
          <span class="audio-key-overlap-rel">— <?= e($relLabel) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
    <p class="muted small" id="key-overlap-status"></p>
  </div>

  <label>
    <?= te('audio_dicts.col.dict') ?>
    <select name="id_dictonary" id="key-dict">
      <option value="0">—</option>
      <?php foreach ($dictOptions as $did => $dname): ?>
        <option value="<?= (int)$did ?>"<?= (int)($row['id_dictonary'] ?? 0) === (int)$did ? ' selected' : '' ?>>
          <?= e($dname) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <span class="muted small"><?= te('audio_dicts.keys.dict_hint') ?></span>
  </label>

  <h2 class="form-section-title"><?= te('audio_dicts.keys.sec_links') ?></h2>
  <p class="muted audio-dict-hint"><?= te('audio_dicts.keys.hint_links') ?></p>

  <label>
    <?= te('audio_dicts.col.looklike') ?>
    <input type="number" min="0" name="id_looklike" id="key-looklike"
           value="<?= (int)($row['id_looklike'] ?? 0) ?>">
    <span class="muted small"><?= te('audio_dicts.keys.looklike_hint') ?></span>
  </label>

  <h2 class="form-section-title"><?= te('audio_dicts.edit.sec_flags') ?></h2>

  <label class="check">
    <input type="checkbox" name="disabled" value="1"<?= !empty($row['disabled']) ? ' checked' : '' ?>>
    <?= te('audio_dicts.col.disabled') ?>
    <span class="muted small"><?= te('audio_dicts.keys.disabled_hint') ?></span>
  </label>

  <label class="check" id="key-force-wrap"<?= !$forceNeeded ? ' hidden' : '' ?>>
    <input type="checkbox" name="force" value="1" id="key-force"<?= $forceChecked ? ' checked' : '' ?>>
    <?= te('audio_dicts.keys.force') ?>
    <span class="muted small"><?= te('audio_dicts.keys.force_hint') ?></span>
  </label>

  <div class="form-actions">
    <button type="submit" class="btn-primary"><?= te('core.common.save') ?></button>
    <a class="btn-secondary" href="<?= e($path) ?>"><?= te('core.common.cancel') ?></a>
  </div>
</form>

<?php if (!$isNew): ?>
<?= EntityDelete::form((string)($listId ?? 'audio_keys'), $id > 0 ? $id : (int)($row['id'] ?? 0)) ?>
<?php endif; ?>
