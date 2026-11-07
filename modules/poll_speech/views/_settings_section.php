<?php
declare(strict_types=1);
/**
 * Embeddable poll_speech settings for /polls/settings (and own settings page).
 *
 * @var array<string,string> $cfg
 * @var array<int,string> $pollOptions
 * @var list<array{id:int,name:string,disabled:int,score:int}> $dictKeys
 */
$cfg = is_array($cfg ?? null) ? $cfg : [];
$pollOptions = is_array($pollOptions ?? null) ? $pollOptions : [];
$dictKeys = is_array($dictKeys ?? null) ? $dictKeys : [];
$oralId = (int)($cfg['oral_poll_id'] ?? 0);
$adminField = (string)($cfg['admin_field'] ?? 'linked');
$btnOn = ((string)($cfg['oral_btn_enabled'] ?? '1')) === '1';
$qid = (string)($cfg['qid'] ?? $cfg['qid_staff'] ?? '0');
$answers = (string)($cfg['answers'] ?? $cfg['answers_staff'] ?? '');

$byScore = [];
$unmapped = [];
for ($s = 1; $s <= 10; $s++) {
    $byScore[$s] = [];
}
foreach ($dictKeys as $key) {
    $sc = (int)($key['score'] ?? 0);
    if ($sc >= 1 && $sc <= 10) {
        $byScore[$sc][] = $key;
    } else {
        $unmapped[] = $key;
    }
}

$scoreSelect = static function (int $keyId, int $current) : string {
    $html = '<select name="key_assign[' . $keyId . ']" class="poll-speech-keymap-select">';
    $html .= '<option value="0"' . ($current < 1 ? ' selected' : '') . '>'
        . te('poll_speech.settings.keymap_none') . '</option>';
    for ($s = 10; $s >= 1; $s--) {
        $html .= '<option value="' . $s . '"' . ($current === $s ? ' selected' : '') . '>'
            . $s . '</option>';
    }
    $html .= '</select>';
    return $html;
};
?>
<input type="hidden" name="poll_speech_settings_save" value="1">
<p class="muted"><?= te('poll_speech.settings.hint') ?></p>

<fieldset class="form-fieldset">
  <legend><?= te('poll_speech.settings.sec.ui') ?></legend>
  <label class="checkbox">
    <input type="hidden" name="oral_btn_enabled" value="0">
    <input type="checkbox" name="oral_btn_enabled" value="1"<?= $btnOn ? ' checked' : '' ?>>
    <?= te('poll_speech.settings.oral_btn_enabled') ?>
  </label>
  <p class="muted poll-speech-field-hint"><?= te('poll_speech.settings.oral_btn_enabled_help') ?></p>

  <label><?= te('poll_speech.settings.oral_poll_id') ?>
    <select name="oral_poll_id">
      <option value="0"><?= te('poll_speech.settings.oral_poll_auto') ?></option>
      <?php foreach ($pollOptions as $pid => $pname): ?>
        <option value="<?= (int)$pid ?>"<?= $oralId === (int)$pid ? ' selected' : '' ?>><?= e('#' . (int)$pid . ' — ' . $pname) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <p class="muted poll-speech-field-hint"><?= te('poll_speech.settings.oral_poll_id_help') ?></p>
</fieldset>

<fieldset class="form-fieldset">
  <legend><?= te('poll_speech.settings.sec.search') ?></legend>

  <label><?= te('poll_speech.settings.dict_id') ?>
    <input type="number" name="dict_id" min="0" value="<?= e((string)($cfg['dict_id'] ?? '50')) ?>">
  </label>
  <p class="muted poll-speech-field-hint"><?= te('poll_speech.settings.dict_id_help') ?></p>

  <label><?= te('poll_speech.settings.window_sec') ?>
    <input type="number" name="window_sec" min="60" max="86400" value="<?= e((string)($cfg['window_sec'] ?? '900')) ?>">
  </label>
  <p class="muted poll-speech-field-hint"><?= te('poll_speech.settings.window_sec_help') ?></p>

  <label><?= te('poll_speech.settings.admin_field') ?>
    <select name="admin_field">
      <option value="linked"<?= $adminField === 'linked' ? ' selected' : '' ?>><?= te('poll_speech.settings.admin_linked') ?></option>
      <option value="proc_admin"<?= $adminField === 'proc_admin' ? ' selected' : '' ?>><?= te('poll_speech.settings.admin_proc') ?></option>
      <option value="both"<?= $adminField === 'both' ? ' selected' : '' ?>><?= te('poll_speech.settings.admin_both') ?></option>
    </select>
  </label>
  <p class="muted poll-speech-field-hint"><?= te('poll_speech.settings.admin_field_help') ?></p>
</fieldset>

<fieldset class="form-fieldset poll-speech-keymap">
  <legend><?= te('poll_speech.settings.sec.keymap') ?></legend>
  <p class="muted poll-speech-field-hint"><?= te('poll_speech.settings.keymap_help') ?></p>

  <?php if ($dictKeys === []): ?>
    <p class="muted"><?= te('poll_speech.settings.keymap_empty') ?></p>
  <?php else: ?>
    <div class="poll-speech-keymap-grid">
      <?php for ($score = 10; $score >= 1; $score--): ?>
        <div class="poll-speech-keymap-score">
          <h4 class="poll-speech-keymap-score-title">
            <?= te('poll_speech.settings.keymap_score', ['n' => (string)$score]) ?>
            <span class="muted">(<?= count($byScore[$score]) ?>)</span>
          </h4>
          <?php if ($byScore[$score] === []): ?>
            <p class="muted poll-speech-keymap-empty-score">—</p>
          <?php else: ?>
            <ul class="poll-speech-keymap-list">
              <?php foreach ($byScore[$score] as $key): ?>
                <li class="<?= !empty($key['disabled']) ? 'is-disabled' : '' ?>">
                  <span class="poll-speech-keymap-id">#<?= (int)$key['id'] ?></span>
                  <span class="poll-speech-keymap-name"><?= e((string)$key['name']) ?></span>
                  <?php if (!empty($key['disabled'])): ?>
                    <span class="muted"><?= te('poll_speech.settings.keymap_disabled') ?></span>
                  <?php endif; ?>
                  <?= $scoreSelect((int)$key['id'], (int)$key['score']) ?>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      <?php endfor; ?>
    </div>

    <div class="poll-speech-keymap-unmapped">
      <h4>
        <?= te('poll_speech.settings.keymap_unmapped') ?>
        <span class="muted">(<?= count($unmapped) ?>)</span>
      </h4>
      <p class="muted poll-speech-field-hint"><?= te('poll_speech.settings.keymap_unmapped_help') ?></p>
      <?php if ($unmapped === []): ?>
        <p class="muted">—</p>
      <?php else: ?>
        <ul class="poll-speech-keymap-list">
          <?php foreach ($unmapped as $key): ?>
            <li class="<?= !empty($key['disabled']) ? 'is-disabled' : '' ?>">
              <span class="poll-speech-keymap-id">#<?= (int)$key['id'] ?></span>
              <span class="poll-speech-keymap-name"><?= e((string)$key['name']) ?></span>
              <?= $scoreSelect((int)$key['id'], 0) ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</fieldset>

<fieldset class="form-fieldset">
  <legend><?= te('poll_speech.settings.sec.score') ?></legend>
  <p class="muted poll-speech-field-hint"><?= te('poll_speech.settings.score_help') ?></p>

  <label><?= te('poll_speech.settings.qid') ?>
    <input type="number" name="qid" min="0" value="<?= e($qid) ?>">
  </label>
  <p class="muted poll-speech-field-hint"><?= te('poll_speech.settings.qid_help') ?></p>

  <label><?= te('poll_speech.settings.answers') ?>
    <input type="text" name="answers" value="<?= e($answers) ?>" style="width:100%">
  </label>
  <p class="muted poll-speech-field-hint"><?= te('poll_speech.settings.answers_help') ?></p>
</fieldset>
