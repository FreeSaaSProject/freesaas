<?php
/** @var bool $forced */
/** @var array<string,string> $options */
$forced = !empty($forced);
$options = is_array($options ?? null) ? $options : [];
$title = $forced ? t('audio.badge.gate.title_forced') : t('audio.badge.gate.title_pick');
?>
<div class="audio-badge-gate" role="dialog" aria-modal="true" aria-labelledby="audio-badge-gate-title">
  <div class="audio-badge-gate-card">
    <h1 id="audio-badge-gate-title"><?= e($title) ?></h1>
    <p class="muted"><?= te('audio.badge.gate.hint') ?></p>
    <form method="post" action="/audio/api/badge" id="audio-badge-form" class="audio-badge-form">
      <label class="audio-badge-select-wrap">
        <span class="sr-only"><?= te('audio.badge.gate.select') ?></span>
        <select name="badge_id" id="audio-badge-select" required>
          <option value=""><?= te('audio.badge.gate.placeholder') ?></option>
          <?php foreach ($options as $value => $label): ?>
            <option value="<?= e((string)$value) ?>"><?= e((string)$label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button type="submit" class="btn-primary"><?= te('audio.badge.gate.submit') ?></button>
      <?php if (!$forced): ?>
        <a class="btn-secondary" href="/"><?= te('audio.badge.gate.cancel') ?></a>
      <?php endif; ?>
      <p class="audio-badge-form-status muted" hidden></p>
    </form>
  </div>
</div>
<script>
(function () {
  var form = document.getElementById('audio-badge-form');
  if (!form) return;
  var status = form.querySelector('.audio-badge-form-status');
  var I = window.FREESAAS_I18N || {};
  var L = {
    pickRequired: I['audio.badge.pick_required'] || '',
    error: I['audio.badge.error'] || '',
    network: I['audio.badge.network'] || ''
  };
  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    var sel = document.getElementById('audio-badge-select');
    var badgeId = sel ? String(sel.value || '') : '';
    if (!badgeId) {
      alert(L.pickRequired || 'Pick a badge');
      return;
    }
    if (status) {
      status.hidden = false;
      status.textContent = '…';
    }
    var body = new URLSearchParams();
    body.set('badge_id', badgeId);
    fetch('/audio/api/badge', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: body.toString(),
      credentials: 'same-origin'
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data && data.success) {
          window.location.href = '/';
          return;
        }
        if (status) {
          status.textContent = (data && data.message) || (L.error || 'Error');
        } else {
          alert((data && data.message) || (L.error || 'Error'));
        }
      })
      .catch(function () {
        if (status) status.textContent = L.network || 'Network error';
      });
  });
})();
</script>
