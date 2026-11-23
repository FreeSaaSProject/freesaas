<?php
declare(strict_types=1);
/** @var array<string,mixed> $row procedure row */
/** @var list<array<string,mixed>> $attachments */
/** @var list<array<string,mixed>> $unmet */
$attachments = is_array($attachments ?? null) ? $attachments : [];
$unmet = is_array($unmet ?? null) ? $unmet : [];
$procId = (int)($row['id'] ?? 0);
$canUpload = $procId > 0 && MediaAcl::canUploadOwner('base_procedures', $procId);
?>
<section class="media-panel" data-media-panel>
  <h2><?= te('media.panel.title') ?></h2>
  <?php if ($unmet !== []): ?>
    <?php
    $parts = [];
    foreach ($unmet as $u) {
        $label = trim((string)($u['label'] ?? ''));
        $parts[] = t('media.panel.unmet_item', [
            'kind' => t('media.kind.' . $u['kind']),
            'label' => $label !== '' ? $label : '—',
            'have' => (string)$u['have'],
            'min' => (string)$u['min_count'],
        ]);
    }
    ?>
    <div class="flash flash-warn"><?= e(t('media.panel.unmet', ['list' => implode('; ', $parts)])) ?></div>
  <?php endif; ?>

  <?php if ($attachments === []): ?>
    <p class="muted"><?= te('media.panel.empty') ?></p>
  <?php else: ?>
    <ul class="media-panel-grid">
      <?php foreach ($attachments as $a): ?>
        <li>
          <a href="/media/file/<?= (int)$a['id'] ?>" target="_blank" rel="noopener">
            <img src="/media/thumb/<?= (int)$a['id'] ?>" alt="<?= e((string)($a['original_name'] ?? '')) ?>" width="96" height="96">
          </a>
          <span class="muted"><?= te('media.kind.' . (string)$a['kind']) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <?php if ($canUpload): ?>
  <form method="post" action="/media/upload" enctype="multipart/form-data" class="media-upload-form" id="media-upload-form-<?= $procId ?>">
    <input type="hidden" name="owner_table" value="base_procedures">
    <input type="hidden" name="owner_id" value="<?= $procId ?>">
    <input type="hidden" name="ret" value="/procedures/<?= $procId ?>/edit">
    <div class="media-upload-row">
      <label class="media-upload-kind"><?= te('media.list.kind') ?>
        <select name="kind">
          <?php foreach (MediaConfig::kinds() as $k): ?>
            <option value="<?= e($k) ?>"><?= te('media.kind.' . $k) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="btn-secondary media-upload-pick">
        <span class="media-upload-pick-label"><?= te('media.panel.choose_file') ?></span>
        <input type="file" name="file" accept="image/jpeg,image/png,image/webp" required hidden
               class="media-upload-file">
      </label>
      <span class="muted media-upload-name" data-empty="<?= e(t('media.panel.no_file')) ?>"><?= te('media.panel.no_file') ?></span>
      <button class="btn-primary" type="submit"><?= te('media.panel.upload') ?></button>
    </div>
  </form>
  <script>
  (function () {
    var form = document.getElementById('media-upload-form-<?= $procId ?>');
    if (!form || form.getAttribute('data-bound') === '1') return;
    form.setAttribute('data-bound', '1');
    var input = form.querySelector('.media-upload-file');
    var nameEl = form.querySelector('.media-upload-name');
    if (!input || !nameEl) return;
    input.addEventListener('change', function () {
      var f = input.files && input.files[0] ? input.files[0].name : '';
      nameEl.textContent = f !== '' ? f : (nameEl.getAttribute('data-empty') || '');
    });
  })();
  </script>
  <?php endif; ?>
</section>
